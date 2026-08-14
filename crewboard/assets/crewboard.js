/* CrewBoard – frontend calendar interaction */
document.addEventListener('DOMContentLoaded', () => {
  // ── Calendar: click day to reveal detail panel ──────────────
  const grid      = document.getElementById('crewboard-cal-grid');
  const evtPanels = document.getElementById('crewboard-evt-panels');
  if (grid) {
    let activeCell = null;

    grid.addEventListener('click', e => {
      const cell = e.target.closest('.crewboard-cal-cell.has-event');
      if (!cell) return;

      if (activeCell === cell) {
        cell.classList.remove('is-active');
        if (evtPanels) {
          evtPanels.classList.remove('cb-open');
          evtPanels.querySelectorAll('.crewboard-evt-panel').forEach(p => p.classList.remove('cb-show'));
        }
        activeCell = null;
        return;
      }

      if (activeCell) activeCell.classList.remove('is-active');
      cell.classList.add('is-active');
      activeCell = cell;

      if (evtPanels) {
        evtPanels.querySelectorAll('.crewboard-evt-panel').forEach(p => p.classList.remove('cb-show'));
        const events = JSON.parse(cell.dataset.events || '[]');
        let shown = 0;
        events.forEach(evt => {
          const p = document.getElementById('crewboard-evt-' + evt.event_id)
                  || evtPanels.querySelector('[data-event-id="' + evt.event_id + '"]');
          if (p) { p.classList.add('cb-show'); shown++; }
        });
        if (shown > 0) {
          evtPanels.classList.add('cb-open');
          evtPanels.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
          evtPanels.classList.remove('cb-open');
        }
      }
    });
  }

  // ── ICS copy button ───────────────────────────────────────
  document.addEventListener('click', e => {
    const btn = e.target.closest('.crewboard-ics-copy-btn');
    if (!btn) return;
    const url = btn.dataset.url || '';
    if (!url) return;
    if (navigator.clipboard) {
      navigator.clipboard.writeText(url).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✓ Kopiert!';
        setTimeout(() => { btn.textContent = orig; }, 2000);
      });
    } else {
      // Fallback for older browsers
      const inp = btn.closest('.crewboard-ics-url-row')?.querySelector('input');
      if (inp) { inp.select(); document.execCommand('copy'); }
    }
  });
});
