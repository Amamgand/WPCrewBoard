function crewBoardAdminInit() {
  // ── Services table: add / remove rows ──────────────────
  const tableBody = document.querySelector('#crewboard-services-table tbody');
  const addButton = document.querySelector('#crewboard-add-service');
  const template  = document.querySelector('#crewboard-service-template');
  if (tableBody && addButton && template) {
    const bindRemove = (root = document) => {
      root.querySelectorAll('.crewboard-remove-service').forEach((button) => {
        if (button.dataset.bound) return;
        button.dataset.bound = '1';
        button.addEventListener('click', () => button.closest('tr')?.remove());
      });
    };
    addButton.addEventListener('click', () => {
      const index = `${Date.now()}_${Math.floor(Math.random() * 1000)}`;
      const html  = template.innerHTML.replaceAll('__INDEX__', index);
      tableBody.insertAdjacentHTML('beforeend', html);
      // Auto-fill start/end from event timing stored on the table.
      const tbl    = document.getElementById('crewboard-services-table');
      const newRow = tableBody.lastElementChild;
      if (tbl && newRow) {
        const es = tbl.dataset.evtStart;
        const ee = tbl.dataset.evtEnd;
        if (es) { const f = newRow.querySelector('input[name*="[start]"]'); if (f && !f.value) f.value = es; }
        if (ee) { const f = newRow.querySelector('input[name*="[end]"]');   if (f && !f.value) f.value = ee; }
      }
      bindRemove(tableBody);
    });
    bindRemove();
  }

  // ── Members tables: live search (two sections) ─────────
  function bindTableSearch(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    if (!input || !table) return;
    const filter = () => {
      const term = input.value.trim().toLowerCase();
      table.querySelectorAll('tbody tr').forEach(row => {
        // Search name + email (first td only) to avoid false positives from select options
        const firstTd = row.querySelector('td');
        const text    = firstTd ? firstTd.textContent.toLowerCase() : '';
        row.style.display = term && !text.includes(term) ? 'none' : '';
      });
    };
    input.addEventListener('input',  filter);
    input.addEventListener('keyup',  filter);  // belt-and-suspenders for all browsers
    input.addEventListener('search', filter);  // fires when X is clicked in type=search
  }
  bindTableSearch('crewboard-search-active',   'crewboard-members-active');
  bindTableSearch('crewboard-search-inactive', 'crewboard-members-inactive');

  // ── Settings: custom teams add / remove ─────────────────
  const teamsList  = document.getElementById('crewboard-custom-teams-list');
  const addTeamBtn = document.getElementById('crewboard-add-team');
  if (teamsList && addTeamBtn) {
    const bindRemoveTeam = (root = teamsList) => {
      root.querySelectorAll('.crewboard-remove-team').forEach(btn => {
        if (btn.dataset.bound) return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', () => btn.closest('.crewboard-team-row')?.remove());
      });
    };
    addTeamBtn.addEventListener('click', () => {
      const row = document.createElement('div');
      row.className    = 'crewboard-team-row';
      row.style.cssText = 'display:flex;gap:6px;align-items:center';
      row.innerHTML    = '<input type="text" name="custom_teams[]" placeholder="Teamname" class="regular-text"><button type="button" class="button crewboard-remove-team">Entfernen</button>';
      teamsList.appendChild(row);
      bindRemoveTeam(row);
      row.querySelector('input')?.focus();
    });
    bindRemoveTeam();
  }

  // ── Deny form: toggle textarea on "Ablehnen" button ─────
  document.addEventListener('click', e => {
    const toggle = e.target.closest('.crewboard-deny-toggle');
    if (!toggle) return;
    const form = toggle.closest('.crewboard-deny-wrap')?.querySelector('.crewboard-deny-form');
    if (!form) return;
    form.hidden = !form.hidden;
    toggle.textContent = form.hidden ? '\u2717 Ablehnen' : 'Abbrechen';
    if (!form.hidden) form.querySelector('textarea')?.focus();
  });

  // ── Auto-fill service times from EM event date/time fields ─────────
  (function syncEmTimesToServices() {
    const tbl = document.getElementById('crewboard-services-table');
    if (!tbl) return;

    // Events Manager uses input[name="event_start_date"] / event_start_time etc.
    const sel = name => document.querySelector(`input[name="${name}"], #${name}`);
    const startDateEl = sel('event_start_date');
    if (!startDateEl) return; // not on an EM event edit screen

    // Add hours to a "Y-m-d" + "H:i[:s]" pair, returns "Y-m-dTH:i" in local time.
    function shiftHours(dateStr, timeStr, delta) {
      const [y, mo, d] = dateStr.split('-').map(Number);
      const [h, mi]    = (timeStr || '20:00').split(':').map(Number);
      const dt = new Date(y, mo - 1, d, h + delta, mi || 0);
      const p  = n => String(n).padStart(2, '0');
      return `${dt.getFullYear()}-${p(dt.getMonth()+1)}-${p(dt.getDate())}T${p(dt.getHours())}:${p(dt.getMinutes())}`;
    }

    function applyTimes() {
      const startTimeEl = sel('event_start_time');
      const endDateEl   = sel('event_end_date');
      const endTimeEl   = sel('event_end_time');

      const sDate = startDateEl.value.trim();
      if (!sDate || !/^\d{4}-\d{2}-\d{2}$/.test(sDate)) return;

      const sTime = startTimeEl?.value.trim() || '20:00';
      const eDate = endDateEl?.value.trim()   || sDate;
      const eTime = endTimeEl?.value.trim()   || sTime;

      const svcStart = shiftHours(sDate, sTime, -1);
      const svcEnd   = shiftHours(eDate, eTime,  1);

      tbl.dataset.evtStart = svcStart;
      tbl.dataset.evtEnd   = svcEnd;

      // Fill only currently-empty start/end inputs in all service rows.
      tbl.querySelectorAll('.crewboard-service-row').forEach(row => {
        const sf = row.querySelector('input[name*="[start]"]');
        const ef = row.querySelector('input[name*="[end]"]');
        if (sf && !sf.value) sf.value = svcStart;
        if (ef && !ef.value) ef.value = svcEnd;
      });
    }

    // Run once on load, then watch EM fields for changes.
    applyTimes();
    const emFields = 'input[name="event_start_date"], input[name="event_start_time"], input[name="event_end_date"], input[name="event_end_time"]';
    // Native events (manual typing).
    document.querySelectorAll(emFields).forEach(el => {
      el.addEventListener('change', applyTimes);
      el.addEventListener('input',  applyTimes);
      el.addEventListener('blur',   applyTimes);
    });
    // jQuery events — EM datepicker fires these when a date is picked via the calendar popup.
    if (typeof jQuery !== 'undefined') {
      jQuery(document).on('change input', emFields, applyTimes);
    }
  })();

  // ── Event dropdown: search + past-events toggle ─────────────────
  const cbEventSelect = document.getElementById('crewboard-event-select');
  if (cbEventSelect) {
    const cbPastGroup  = document.getElementById('crewboard-past-events');
    const cbShowPast   = document.getElementById('crewboard-show-past');
    const cbEventSearch = document.getElementById('crewboard-event-search');

    function cbFilterEvents() {
      const term = cbEventSearch ? cbEventSearch.value.toLowerCase().trim() : '';
      cbEventSelect.querySelectorAll('option').forEach(opt => {
        if (!opt.value) return; // always keep the placeholder
        const inPast   = cbPastGroup ? cbPastGroup.contains(opt) : false;
        const pastHide = inPast && cbPastGroup.hidden;
        const textHide = term !== '' && !opt.textContent.toLowerCase().includes(term);
        opt.hidden = pastHide || textHide;
      });
    }

    if (cbShowPast && cbPastGroup) {
      cbShowPast.addEventListener('change', () => {
        cbPastGroup.hidden = !cbShowPast.checked;
        cbFilterEvents();
      });
    }

    if (cbEventSearch) {
      cbEventSearch.addEventListener('input', cbFilterEvents);
    }

    // Sync checkbox state with the optgroup's initial hidden state on load.
    if (cbShowPast && cbPastGroup) {
      cbShowPast.checked = !cbPastGroup.hidden;
    }
  }
}

// Handle both normal load and defer/async scenarios
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', crewBoardAdminInit);
} else {
  crewBoardAdminInit();
}
