/* CrewBoard – frontend calendar interaction */
document.addEventListener('DOMContentLoaded', () => {
  // ── Calendar: click day to reveal detail panel ──────────────
  const grid  = document.getElementById('crewboard-cal-grid');
  const panel = document.getElementById('crewboard-cal-panel');
  if (grid && panel) {
    let activeCell = null;

    grid.addEventListener('click', e => {
      const cell = e.target.closest('.crewboard-cal-cell.has-event');
      if (!cell) return;

      if (activeCell === cell) {
        cell.classList.remove('is-active');
        panel.hidden = true;
        activeCell = null;
        return;
      }

      if (activeCell) activeCell.classList.remove('is-active');
      cell.classList.add('is-active');
      activeCell = cell;

      const events = JSON.parse(cell.dataset.events || '[]');
      panel.innerHTML = events.map(renderEvent).join('');
      panel.hidden = false;
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });

    function renderEvent(evt) {
      let h = `<div class="crewboard-cal-panel-event"><strong>${esc(evt.title)}</strong>`;
      if (evt.has_my_task) {
        h += `<span class="crewboard-cal-mine-badge">✓ Dein Dienst</span>`;
        if (evt.my_services.length) {
          h += `<ul>${evt.my_services.map(s => `<li>${esc(s)}</li>`).join('')}</ul>`;
        }
      }
      h += `</div>`;
      return h;
    }

    function esc(str) {
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }
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
