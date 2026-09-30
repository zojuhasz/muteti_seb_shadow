(function (Drupal, once, drupalSettings) {
  'use strict';
  Drupal.behaviors.mutetiSurgeryBoard = {
    attach(context) {
      const readJson = async (response) => {
        const body = await response.text();
        try {
          return JSON.parse(body);
        }
        catch (error) {
          throw new Error(`A szerver nem JSON-választ adott (HTTP ${response.status}).`);
        }
      };
      const bookingNavLinks = once('muteti-booking-scroll', '.muteti-booking-nav a', context);
      bookingNavLinks.forEach((link) => {
        link.addEventListener('click', () => sessionStorage.setItem('mutetiBookingScrollY', String(window.scrollY)));
      });
      if (context === document) {
        const savedScrollY = sessionStorage.getItem('mutetiBookingScrollY');
        if (savedScrollY !== null && document.querySelector('#muteti-booking-table')) {
          sessionStorage.removeItem('mutetiBookingScrollY');
          requestAnimationFrame(() => window.scrollTo(0, Number(savedScrollY)));
        }
      }
      const moveButtons = once('muteti-booking-move', '.muteti-move-link', context);
      const moveStorageKey = 'mutetiBookingMoveSource';
      const readMoveSource = () => {
        try { return JSON.parse(sessionStorage.getItem(moveStorageKey) || 'null'); }
        catch (error) { return null; }
      };
      const refreshMoveState = () => {
        const selected = readMoveSource();
        const table = document.querySelector('#muteti-booking-table');
        if (table) table.classList.toggle('is-moving-patient', Boolean(selected));
        document.querySelectorAll('.muteti-move-link.is-source').forEach((button) => {
          const active = selected && Number(button.dataset.moveId) === Number(selected.id);
          button.classList.toggle('is-selected', Boolean(active));
          button.textContent = active ? 'felvéve' : 'áth';
          button.title = active ? 'Áthelyezés megszakítása' : 'Áthelyezés';
        });
        document.querySelectorAll('.muteti-move-link.is-target').forEach((button) => {
          const duplicate = button.dataset.moveMode === 'duplicate';
          const action = duplicate ? 'Duplikálás ide' : 'Áthelyezés ide';
          const label = selected && selected.patient ? `${action}: ${selected.patient}` : action;
          button.title = label;
          button.setAttribute('aria-label', label);
        });
      };
      moveButtons.forEach((button) => {
        button.addEventListener('click', async (event) => {
          event.preventDefault();
          event.stopPropagation();
          if (button.classList.contains('is-source')) {
            const selected = readMoveSource();
            if (selected && Number(selected.id) === Number(button.dataset.moveId)) {
              sessionStorage.removeItem(moveStorageKey);
            }
            else {
              sessionStorage.setItem(moveStorageKey, JSON.stringify({
                id: Number(button.dataset.moveId),
                patient: button.dataset.movePatient || ''
              }));
            }
            refreshMoveState();
            return;
          }
          const selected = readMoveSource();
          if (!selected) {
            window.alert('Előbb egy foglalt betegcellában kattints az „áth” ikonra.');
            return;
          }
          button.disabled = true;
          try {
            const response = await fetch(drupalSettings.mutetiSeb.appointmentMoveEndpoint, {
              method: 'POST',
              headers: {'Content-Type': 'application/json'},
              credentials: 'same-origin',
              body: JSON.stringify({
                appointment_id: Number(selected.id),
                date: button.dataset.moveDate,
                slot: button.dataset.moveSlot,
                mode: button.dataset.moveMode || 'move'
              })
            });
            const result = await readJson(response);
            if (!response.ok || !result.ok) throw new Error(result.error || `HTTP ${response.status}`);
            sessionStorage.removeItem(moveStorageKey);
            window.location.reload();
          }
          catch (error) {
            button.disabled = false;
            window.alert(error.message || 'Az áthelyezés sikertelen.');
          }
        });
      });
      if (moveButtons.length || context === document) refreshMoveState();
      const deleteButtons = once('muteti-booking-delete', '.muteti-delete-link', context);
      deleteButtons.forEach((button) => {
        button.addEventListener('click', async (event) => {
          event.preventDefault();
          event.stopPropagation();
          const patient = button.dataset.deletePatient || 'a kiválasztott beteg';
          if (!window.confirm(`Biztosan törlöd az előjegyzésből: ${patient}?`)) return;
          button.disabled = true;
          try {
            const response = await fetch(drupalSettings.mutetiSeb.appointmentDeleteEndpoint, {
              method: 'POST',
              headers: {'Content-Type': 'application/json'},
              credentials: 'same-origin',
              body: JSON.stringify({appointment_id: Number(button.dataset.deleteId)})
            });
            const result = await readJson(response);
            if (!response.ok || !result.ok) throw new Error(result.error || `HTTP ${response.status}`);
            sessionStorage.removeItem(moveStorageKey);
            window.location.reload();
          }
          catch (error) {
            button.disabled = false;
            window.alert(error.message || 'A beteg törlése sikertelen.');
          }
        });
      });
      const waitingListButtons = once('muteti-urology-waitlist-main', '.muteti-urology-waitlist-button', context);
      waitingListButtons.forEach((button) => {
        button.addEventListener('click', async (event) => {
          event.preventDefault();
          event.stopPropagation();
          const control = button.closest('.muteti-urology-waitlist-control');
          if (button.dataset.action !== 'revoke') {
            document.querySelectorAll('.muteti-urology-waitlist-control.is-open').forEach((item) => {
              if (item !== control) item.classList.remove('is-open');
            });
            control?.classList.toggle('is-open');
            return;
          }
          if (!window.confirm(button.title || 'Biztosan visszavonod a műtéti időpontot?')) return;
          button.disabled = true;
          try {
            const response = await fetch(drupalSettings.mutetiSeb.urologyWaitingListEndpoint, {
              method: 'POST',
              headers: {'Content-Type': 'application/json'},
              credentials: 'same-origin',
              body: JSON.stringify({
                appointment_id: Number(button.dataset.appointmentId),
                action: 'revoke'
              })
            });
            const result = await readJson(response);
            if (!response.ok || !result.ok) throw new Error(result.error || `HTTP ${response.status}`);
            window.location.reload();
          }
          catch (error) {
            button.disabled = false;
            window.alert(error.message || 'A műtéti időpont visszavonása sikertelen.');
          }
        });
      });
      const waitingListDates = once('muteti-urology-waitlist-date', '.muteti-urology-waitlist-date', context);
      waitingListDates.forEach((button) => {
        button.addEventListener('click', async (event) => {
          event.preventDefault();
          event.stopPropagation();
          button.disabled = true;
          try {
            const response = await fetch(drupalSettings.mutetiSeb.urologyWaitingListEndpoint, {
              method: 'POST',
              headers: {'Content-Type': 'application/json'},
              credentials: 'same-origin',
              body: JSON.stringify({
                appointment_id: Number(button.dataset.appointmentId),
                action: 'schedule',
                date: button.dataset.date
              })
            });
            const result = await readJson(response);
            if (!response.ok || !result.ok) throw new Error(result.error || `HTTP ${response.status}`);
            window.location.reload();
          }
          catch (error) {
            button.disabled = false;
            window.alert(error.message || 'A várólistára helyezés sikertelen.');
          }
        });
      });
      once('muteti-urology-waitlist-close', 'body', context).forEach((body) => {
        body.addEventListener('click', () => {
          document.querySelectorAll('.muteti-urology-waitlist-control.is-open')
            .forEach((item) => item.classList.remove('is-open'));
        });
      });

      const surgeryNavigationLinks = once('muteti-surgery-scroll', '.muteti-surgery-week-frame a', context);
      surgeryNavigationLinks.forEach((link) => {
        link.addEventListener('click', () => sessionStorage.setItem('mutetiSurgeryScrollY', String(window.scrollY)));
      });
      if (context === document) {
        const savedSurgeryScrollY = sessionStorage.getItem('mutetiSurgeryScrollY');
        if (savedSurgeryScrollY !== null && document.querySelector('.muteti-surgery-page')) {
          sessionStorage.removeItem('mutetiSurgeryScrollY');
          requestAnimationFrame(() => window.scrollTo(0, Number(savedSurgeryScrollY)));
        }
      }
      const dayTypeSelects = once('muteti-day-type', '.muteti-day-type-select', context);

      // Keep inline controls clickable inside draggable patient cards.
document.addEventListener('dragstart', (event) => {
  if (event.target.closest('.muteti-surgery-inline-actions')) event.preventDefault();
}, true);
document.addEventListener('mousedown', (event) => {
  if (event.target.closest('.muteti-surgery-inline-actions')) event.stopPropagation();
}, true);dayTypeSelects.forEach((select) => {
        select.addEventListener('change', async () => {
          const previousValue = select.dataset.previousValue;
          select.disabled = true;
          try {
            const response = await fetch(drupalSettings.mutetiSeb.dayTypeEndpoint, {
              method: 'POST',
              headers: {'Content-Type': 'application/json'},
              credentials: 'same-origin',
              body: JSON.stringify({date: select.dataset.date, day_type: select.value})
            });
            const result = await readJson(response);
            if (!response.ok || !result.ok) throw new Error(result.error || `HTTP ${response.status}`);
            select.dataset.previousValue = select.value;
            sessionStorage.setItem('mutetiBookingScrollY', String(window.scrollY));
            window.location.reload();
          }
          catch (error) {
            select.value = previousValue;
            select.disabled = false;
            window.alert(error.message || 'A napfajta mentése sikertelen.');
          }
        });
      });
      const cards = once('muteti-drag', '.muteti-drag-card', context);
      const zones = once('muteti-drop', '.muteti-dropzone', context);
      let dragged = null;
      cards.forEach((card) => {
        card.addEventListener('dragstart', (event) => {
          dragged = card;
          event.dataTransfer.effectAllowed = 'move';
          event.dataTransfer.setData('text/plain', card.dataset.id);
        });
        card.addEventListener('dragover', (event) => {
          event.preventDefault();
          const zone = card.closest('.muteti-dropzone');
          if (dragged && dragged !== card && zone) zone.insertBefore(dragged, card);
        });
      });
      zones.forEach((zone) => {
        zone.addEventListener('dragover', (event) => { event.preventDefault(); zone.classList.add('is-over'); });
        zone.addEventListener('dragleave', () => zone.classList.remove('is-over'));
        zone.addEventListener('drop', async (event) => {
          event.preventDefault(); zone.classList.remove('is-over');
          if (!dragged) return;
          if (event.target === zone || !event.target.closest('.muteti-drag-card')) zone.appendChild(dragged);
          const orderedIds = [...zone.querySelectorAll('.muteti-drag-card')].map((item) => Number(item.dataset.id));
          try {
            const response = await fetch(drupalSettings.mutetiSeb.endpoint, {
              method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin',
              body: JSON.stringify({id: Number(dragged.dataset.id), room: zone.dataset.room || '', date: zone.dataset.date || '', ordered_ids: orderedIds})
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const result = await readJson(response);
            if (!result.ok) throw new Error(result.error || 'Unknown save error');
            dragged = null;
          }
          catch (error) {
            window.alert('A műtőbeosztás mentése sikertelen. Az oldal újratöltődik.');
            window.location.reload();
          }
        });
      });
    }
  };
})(Drupal, once, drupalSettings);
