<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Session\AccountInterface;
use Drupal\muteti_seb\Service\Schedule;
use Drupal\muteti_seb\Service\DepartmentMode;
use Drupal\muteti_seb\Service\UserDepartment;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

final class SurgeryController extends ControllerBase {
  public function __construct(private readonly Connection $database, private readonly CsrfTokenGenerator $csrf) {}
  public static function create(ContainerInterface $c): static { return new static($c->get('database'),$c->get('csrf_token')); }

  public function access(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf(DepartmentMode::get(UserDepartment::get($account)) !== 'onko')
      ->addCacheContexts(['user.roles']);
  }

  public function week(Request $request): array {
    $department = UserDepartment::get($this->currentUser());
$mode = DepartmentMode::get($department);
    $availability_enabled = DepartmentMode::featureEnabled($department, 'availability_enabled');
    $away_enabled = DepartmentMode::featureEnabled($department, 'away_enabled');
    $can_assign = $this->currentUser()->hasPermission('assign operating room');
    $can_edit = $this->currentUser()->hasPermission('edit surgery appointment');
    $can_delete = $this->currentUser()->hasPermission('move surgery appointment');
    $show_drag_hint = (bool) array_intersect(
      ['muteti_orvos1', 'muteti_orvos2', 'muteti_boss'],
      $this->currentUser()->getRoles()
    );
    $can_manage_availability =
  $this->currentUser()->hasPermission('manage own doctor availability')
  && mb_strtolower($this->currentUser()->getAccountName(), 'UTF-8') !== 'tothzee';
    try {
      $monday = new DrupalDateTime($request->query->get('week', 'monday this week'));
    }
    catch (\Exception) {
      $monday = new DrupalDateTime('monday this week');
    }
    $monday->modify('monday this week');
    $days = [];
    $day_dates = [];
    for ($i = 0; $i < 7; $i++) {
      $day = clone $monday;
      $day->modify("+$i day");
      $days[] = $day;
      $day_dates[] = $day->format('Y-m-d');
    }
    $selected = (string) $request->query->get('day', '');
    if (!in_array($selected, $day_dates, TRUE)) {
      $today = date('Y-m-d');
      $selected = in_array($today, $day_dates, TRUE) ? $today : $day_dates[0];
    }

    $prev = (clone $monday)->modify('-7 days')->format('Y-m-d');
    $next = (clone $monday)->modify('+7 days')->format('Y-m-d');
    $prev_month_day = (clone $monday)->modify('first day of previous month');
    $next_month_day = (clone $monday)->modify('first day of next month');
    $prev_month = (clone $prev_month_day)->modify('monday this week')->format('Y-m-d');
    $next_month_date = (clone $next_month_day)->modify('monday this week');
    if ($next_month_date <= $monday) {
      $next_month_date->modify('+7 days');
    }
    $next_month = $next_month_date->format('Y-m-d');
    $nav_link = static function (string $symbol, string $label, Url $url): array {
      $link = Link::fromTextAndUrl($symbol, $url)->toRenderable();
      $link['#attributes']['title'] = $label;
      $link['#attributes']['aria-label'] = $label;
      return $link;
    };
    $availability = $this->database->select('muteti_doctor_availability', 'a')
      ->fields('a', ['date', 'status'])
      ->condition('user_id', (int) $this->currentUser()->id())
      ->condition('date', [$day_dates[0], $day_dates[6]], 'BETWEEN')
      ->execute()
      ->fetchAllKeyed();
    $cards = ['#type' => 'container', '#attributes' => ['class' => ['muteti-week-cards']]];
    foreach ($days as $i => $day) {
      $date = $day->format('Y-m-d');
      $classes = ['muteti-day-card'];
      if ($date === $selected) {
        $classes[] = 'is-selected';
      }
      $cards['d'.$i] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['muteti-day-card-shell']],
        'link' => [
          '#type' => 'link',
          '#title' => [
            '#markup' => '<span class="muteti-day-card-date">'.Html::escape($date).'</span><span class="muteti-day-card-name">'.Html::escape((string) $this->t($day->format('l'))).'</span>',
          ],
          '#url' => Url::fromRoute('muteti_seb.surgery', [], ['query' => ['week' => $monday->format('Y-m-d'), 'day' => $date]]),
          '#attributes' => ['class' => $classes],
        ],
        'availability' => $availability_enabled && $can_manage_availability ? [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => ($availability[$date] ?? 'work') === 'absent' ? 'Távollevő vagyok' : 'Távollét?',
          '#attributes' => [
            'type' => 'button',
            'class' => array_filter(['muteti-availability-toggle', ($availability[$date] ?? 'work') === 'absent' ? 'is-absent' : NULL]),
            'data-date' => $date,
            'data-status' => $availability[$date] ?? 'work',
            'aria-pressed' => ($availability[$date] ?? 'work') === 'absent' ? 'true' : 'false',
            'title' => 'Saját napi munka vagy távollét beállítása',
          ],
        ] : [],
        'away' => $away_enabled && $can_manage_availability ? [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => ($availability[$date] ?? 'work') === 'away' ? 'Idegenben vagyok' : 'Idegenben?',
          '#attributes' => [
            'type' => 'button',
            'class' => array_filter(['muteti-away-toggle', ($availability[$date] ?? 'work') === 'away' ? 'is-away' : NULL]),
            'data-date' => $date,
            'data-status' => $availability[$date] ?? 'work',
            'aria-pressed' => ($availability[$date] ?? 'work') === 'away' ? 'true' : 'false',
            'title' => 'Másik kórházban végzett munka beállítása',
          ],
        ] : [],
      ];
    }

    if ($mode === 'urol') {
      // Urology uses an explicit per-day waiting list. Only patients selected
      // with the green M control on the booking page appear here.
      $waiting_query = $this->database->select('muteti_appointment', 'a')
        ->fields('a')
        ->condition('department', $department)
        ->condition('admission_date', $selected, '<=')
        ->condition('operation_name', '', '<>')
        ->condition('operated', 0)
        ->condition('surgery_date', $selected)
        ->isNull('operating_room');
    }
    else {
      $waiting_query = $this->database->select('muteti_appointment', 'a')
        ->fields('a')
        ->condition('department', $department)
        ->condition('admission_date', $selected, '<=')
        ->condition('operation_name', '', '<>')
        ->condition('operated', 0);

      // A patient belongs to the waiting list only while no surgery date has
      // been assigned, or while assigned to the selected day without a room.
      // This also keeps completed past room assignments out when the imported
      // operated flag was not updated.
      $without_surgery_date = $waiting_query->orConditionGroup()
        ->isNull('surgery_date')
        ->condition('surgery_date', '');
      $selected_unassigned = $waiting_query->andConditionGroup()
        ->condition('surgery_date', $selected)
        ->condition(
          $waiting_query->orConditionGroup()
            ->isNull('operating_room')
            ->condition('operating_room', '')
            ->condition('operating_room', '0')
        );
      $waiting_query->condition(
        $waiting_query->orConditionGroup()
          ->condition($without_surgery_date)
          ->condition($selected_unassigned)
      );
    }
    $waiting = $waiting_query->orderBy('admission_date')->execute()->fetchAll();
    $assigned=$this->database->select('muteti_appointment','a')->fields('a')->condition('department',$department)->condition('surgery_date',$selected)->orderBy('operating_room')->orderBy('surgery_order')->execute()->fetchAll();
    $doctor_ids=[];
    foreach(array_merge($waiting,$assigned) as $a) {
      foreach ([$a->doctor_id, $a->assistant1_id, $a->assistant2_id, $a->assistant3_id] as $staff_id) {
        if ($staff_id) $doctor_ids[]=$staff_id;
      }
    }
    $doctors=$doctor_ids?$this->database->select('muteti_doctor','d')->fields('d')->condition('id',array_unique($doctor_ids),'IN')->execute()->fetchAllAssoc('id'):[];
 
$card = function ($a) use ($doctors, $can_assign, $can_edit, $can_delete, $mode): array {
      $doctor = $doctors[$a->doctor_id] ?? NULL;
      $staff = [];
      foreach ([$a->doctor_id, $a->assistant1_id, $a->assistant2_id, $a->assistant3_id] as $staff_id) {
        if ($staff_id && isset($doctors[$staff_id])) {
          $staff[] = $doctors[$staff_id]->name;
        }
      }
      $staff = array_values(array_unique($staff));
      $attributes = [
        'class' => array_filter([
  'muteti-drag-card',
  $can_assign ? 'is-draggable' : NULL,
  $a->aznm ? 'is-aznm' : NULL,
  $mode === 'urol' && ($a->care_type ?? 'normal') === 'one_day'
    ? 'is-care-one-day'
    : NULL,
  $mode === 'urol' && ($a->care_type ?? 'normal') === 'same_day'
    ? 'is-care-same-day'
    : NULL,
]),
        'data-id' => (string) $a->id,
      ];
      if ($can_assign) {
        $attributes['draggable'] = 'true';
      }
      if ($doctor) {
        $has_background = trim((string) $doctor->background_color) !== '';
        $style = 'background-color:'.($has_background ? $doctor->background_color : '#eef2f6').';color:'.($has_background ? ($doctor->text_color ?: '#111111') : '#111111').';';
        if (!empty($doctor->background_gif)) {
          $gif_url = \Drupal::service('file_url_generator')->generateString($doctor->background_gif);
          $style .= 'background-image:url("'.str_replace('"', '%22', $gif_url).'");background-size:cover;background-position:center;background-repeat:no-repeat;';
        }
        $attributes['style'] = $style;
      }
      $patient_prefix = trim((string) ($a->ward_room ?? '')) !== ''
        ? '('.Html::escape($a->ward_room).') '
        : '';
      $inline_actions = '';
      if ($can_edit || $can_delete) {
        $inline_actions = '<span class="muteti-surgery-inline-actions">';
        if ($can_edit) {
          $edit_url = Url::fromRoute('muteti_seb.appointment', [
            'date' => $a->admission_date,
            'slot' => $a->slot_type,
          ])->toString();
          $inline_actions .= '<a class="muteti-surgery-edit-link" href="'.Html::escape($edit_url).'" title="Beteg módosítása" aria-label="Beteg módosítása">M</a>';
        }
        if ($can_edit && $can_delete) {
          $inline_actions .= '<span class="muteti-action-separator">|</span>';
        }
        if ($can_delete) {
          $inline_actions .= '<button type="button" class="muteti-delete-link muteti-surgery-delete-link" data-delete-id="'.(int) $a->id.'" data-delete-patient="'.Html::escape($a->patient_name).'" title="Beteg törlése" aria-label="Beteg törlése">0</button>';
        }
        $inline_actions .= '</span>';
      }
      if ($mode === 'urol') {
        $patient_content = $patient_prefix.'<strong>'.Html::escape($a->patient_name).'</strong>'.$inline_actions
          .'<br>Dg.: '.Html::escape($a->diagnosis ?? '')
          .'<br>Műtét: '.Html::escape($a->operation_name ?? '')
          .'<br>Anaesth.: '.Html::escape($a->anaesth ?? '')
          .'<br><span class="muteti-staff">Orvos: '.Html::escape($doctor->name ?? '-').'</span>';
      }
      else {
        $patient_content = $patient_prefix.'<strong>'.Html::escape($a->patient_name).'</strong>'.$inline_actions
          .'<br>Dg.: '.Html::escape($a->diagnosis ?? '')
          .'<br>Műtét: '.Html::escape($a->operation_name ?? '')
          .'<br><span class="muteti-staff">Orvos: '.Html::escape($doctor->name ?? '-').'</span>';
      }
      return [
        '#type' => 'container',
        '#attributes' => $attributes,
        'content' => [
          '#markup' => \Drupal\Core\Render\Markup::create($patient_content),
        ],
      ];
    };
    $build = [
      '#attached' => [
        'library' => ['muteti_seb/surgery_board', 'muteti_seb/availability'],
        'drupalSettings' => ['mutetiSeb' => [
          'endpoint' => Url::fromRoute('muteti_seb.assignment', [], ['query' => ['token' => $this->csrf->get('muteti/api/assignment')]])->toString(),
          'appointmentDeleteEndpoint' => Url::fromRoute('muteti_seb.appointment_delete', [], ['query' => ['token' => $this->csrf->get('muteti/api/appointment-delete')]])->toString(),
          'availabilityEndpoint' => Url::fromRoute('muteti_seb.availability_update', [], ['query' => ['token' => $this->csrf->get('muteti/api/tavollet')]])->toString(),
        ]],
      ],
      '#cache' => ['max-age' => 0],
      '#type' => 'container',
      '#attributes' => ['class' => ['muteti-surgery-page']],
    ];
    $build['week_frame'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['muteti-surgery-week-frame'], 'id' => 'muteti-surgery-week'],
      'toolbar' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['muteti-title-toolbar']],
        'heading' => ['#markup' => '<h2 class="muteti-panel-title">'.Html::escape($department).' – heti műtéti beosztás</h2>'],
        'nav' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['muteti-nav']],
          'prev_month' => $nav_link('◀◀', 'Előző hónap', Url::fromRoute('muteti_seb.surgery', [], ['query' => ['week' => $prev_month, 'day' => $prev_month_day->format('Y-m-d')]])),
          'prev' => $nav_link('◀', 'Előző hét', Url::fromRoute('muteti_seb.surgery', [], ['query' => ['week' => $prev]])),
          'today' => $nav_link('●', 'Aktuális hét', Url::fromRoute('muteti_seb.surgery')),
          'next' => $nav_link('▶', 'Következő hét', Url::fromRoute('muteti_seb.surgery', [], ['query' => ['week' => $next]])),
          'next_month' => $nav_link('▶▶', 'Következő hónap', Url::fromRoute('muteti_seb.surgery', [], ['query' => ['week' => $next_month, 'day' => $next_month_day->format('Y-m-d')]])),
        ],
      ],
      'cards' => $cards,
    ];
    $build['daily'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['muteti-surgery-board']],
      'top' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['muteti-daily-heading']],
        'title' => ['#markup' => '<h2 class="muteti-panel-title">'.Html::escape($selected).' – műtéti beosztás'.($show_drag_hint ? ' <small class="muteti-drag-hint">(az áthelyezés húzogatással történik)</small>' : '').'</h2>'],
        'pdf' => [
          '#type' => 'link',
          '#title' => [
            '#theme' => 'image',
            '#uri' => base_path().'modules/custom/muteti_seb/images/pdf-icon.svg',
            '#alt' => 'PDF',
            '#attributes' => ['class' => ['muteti-program-pdf-icon']],
          ],
          '#url' => Url::fromRoute('muteti_seb.program_pdf', ['date' => $selected]),
          '#attributes' => [
            'class' => ['muteti-program-pdf-link'],
            'title' => 'Műtéti program PDF',
            'aria-label' => 'Műtéti program PDF',
            'target' => '_blank',
          ],
        ],
      ],
      'layout' => ['#type' => 'container', '#attributes' => ['class' => ['muteti-board-layout']]],
    ];
    $build['daily']['layout']['waiting']=['#type'=>'container','#attributes'=>['class'=>array_filter([$can_assign ? 'muteti-dropzone' : NULL,'muteti-waiting']),'data-room'=>'','data-date'=>''] ,'title'=>['#markup'=>'<h3 class="muteti-zone-title">Műtétre váró bentfekvők</h3>']];
    foreach($waiting as $a)$build['daily']['layout']['waiting']['p'.$a->id]=$card($a);
    $build['daily']['layout']['rooms']=['#type'=>'container','#attributes'=>['class'=>['muteti-rooms']]];
    $rooms=Schedule::departmentRooms($department);
    foreach($assigned as $a)if($a->operating_room&&!in_array($a->operating_room,$rooms,TRUE))$rooms[]=$a->operating_room;
    foreach($rooms as $room){$build['daily']['layout']['rooms']['r'.$room]=['#type'=>'container','#attributes'=>['class'=>array_filter(['muteti-room',$can_assign ? 'muteti-dropzone' : NULL]),'data-room'=>$room,'data-date'=>$selected],'title'=>['#markup'=>'<h3 class="muteti-zone-title">Műtő '.$room.'</h3>']];foreach($assigned as $a)if($a->operating_room===$room)$build['daily']['layout']['rooms']['r'.$room]['p'.$a->id]=$card($a);}
    $mode = DepartmentMode::get($department);
    $info = $this->database->select('muteti_daily_info', 'i')->fields('i')
      ->condition('department', $department)->condition('date', $selected)->execute()->fetchObject();
    $previous = date('Y-m-d', strtotime($selected.' -1 day'));
    $previous_on_call = $this->database->select('muteti_on_call', 'u')->fields('u', ['doctor_name', 'doctor_name_2'])
      ->condition('mode', $mode)->condition('date', $previous)->execute()->fetchObject();
    $today_on_call = $this->database->select('muteti_on_call', 'u')->fields('u', ['doctor_name', 'doctor_name_2'])
      ->condition('mode', $mode)->condition('date', $selected)->execute()->fetchObject();
    $status_names = function (string $status) use ($selected, $department): string {
      $query = $this->database->select('muteti_doctor_availability', 'a');
      $query->join('muteti_doctor', 'd', 'd.user_id = a.user_id');
      return implode(', ', $query->fields('d', ['name'])->condition('a.date', $selected)
        ->condition('a.status', $status)->condition('d.department', $department)->condition('d.active', 1)
        ->orderBy('d.name')->execute()->fetchCol());
    };
    $absent = $availability_enabled ? $status_names('absent') : (string) ($info->other_absent ?? '');
    $away = $away_enabled ? $status_names('away') : '';
    $start = $info->start_time ?? ($mode === 'urol' ? '08:00' : '08:30');
    if ($mode === 'urol') {
      $lines = [
        'Akut beteg ellátás' => trim((string) ($info->acute_1 ?? '')) ?: ($today_on_call->doctor_name ?? ''),
        'Szabadnap' => $previous_on_call->doctor_name ?? '',
        'Egyéb távollevők' => $absent,
        'Telefonos' => $today_on_call->doctor_name_2 ?? '',
        'Idegen intézményben' => $away,
      ];
    }
    else {
      $free = array_filter([$previous_on_call->doctor_name ?? '', $previous_on_call->doctor_name_2 ?? '']);
      $acute = array_filter([$info->acute_1 ?? '', $info->acute_2 ?? '']);
      $lines = [
        'Aznapi műtét felelős' => $info->responsible ?? '',
        'Akut felelős' => implode(', ', $acute),
        'Ambulancia' => $info->ambulance ?? '',
        'Szabadnap' => implode(', ', $free),
        'Egyéb távollevők' => $absent,
      ];
    }
    $panel_markup = '';
    foreach ($lines as $label => $value) {
      $panel_markup .= '<div><strong>'.Html::escape($label).':</strong> '.Html::escape($value ?: '–').'</div>';
    }
    $panel_markup .= '<div class="muteti-daily-info-start"><strong>Műtétek kezdete:</strong> '.Html::escape($start).'</div>';
    $build['daily']['info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['muteti-daily-info']],
      'content' => ['#markup' => $panel_markup],
      'edit' => $this->currentUser()->hasPermission('assign operating room') ? [
        '#type' => 'link',
        '#title' => '+',
        '#url' => Url::fromRoute('muteti_seb.daily_info', ['date' => $selected]),
        '#attributes' => ['class' => ['muteti-daily-info-edit'], 'title' => 'Napi adatok felvitele vagy módosítása'],
      ] : [],
    ];
    return $build;
  }
}
