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
}

// Handle both normal load and defer/async scenarios
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', crewBoardAdminInit);
} else {
  crewBoardAdminInit();
}
