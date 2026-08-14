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
          evtPanels.hidden = true;
          evtPanels.querySelectorAll('.crewboard-evt-panel').forEach(p => { p.hidden = true; });
        }
        activeCell = null;
        return;
      }

      if (activeCell) activeCell.classList.remove('is-active');
      cell.classList.add('is-active');
      activeCell = cell;

      if (evtPanels) {
        // Hide all panels first, then reveal matching ones.
        evtPanels.querySelectorAll('.crewboard-evt-panel').forEach(p => { p.hidden = true; });
        const events = JSON.parse(cell.dataset.events || '[]');
        let shown = 0;
        events.forEach(evt => {
          // Try id-based lookup first, fall back to data-attribute.
          const p = document.getElementById('crewboard-evt-' + evt.event_id)
                  || evtPanels.querySelector('[data-event-id="' + evt.event_id + '"]');
          if (p) { p.hidden = false; shown++; }
        });
        evtPanels.hidden = shown === 0;
        if (shown > 0) evtPanels.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
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
