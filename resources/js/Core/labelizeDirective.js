// v-labelize: gives every body cell of a table the text of its column header as `data-label`, so the same table can
// reflow into stacked cards on phones (see `.txn-cards` in app.css) without hand-writing a label on every <td>.
// A cell that already has its own data-label keeps it. Runs again whenever the table re-renders (sort, filter, paging).
const apply = table => {
  const headRow = [...table.querySelectorAll('tr')].find(tr => tr.querySelector(':scope > th'));
  if (!headRow) return;

  const labels = [];
  [...headRow.children].forEach(th => {
    const text = th.hasAttribute('data-nolabel') ? '\u0000' : (th.textContent || '').trim().replace(/\s+/g, ' ');
    const span = parseInt(th.getAttribute('colspan') || '1', 10) || 1;
    for (let i = 0; i < span; i++) labels.push(i === 0 ? text : '');
  });

  table.querySelectorAll('tr').forEach(tr => {
    if (tr === headRow || tr.querySelector(':scope > th')) return;
    let col = 0;
    [...tr.children].forEach(td => {
      if (td.tagName !== 'TD') return;
      if (!td.hasAttribute('data-label')) {
        const label = labels[col] || '';
        if (label === '\u0000') td.setAttribute('data-nolabel', '1');
        else if (label) td.setAttribute('data-label', label);
      }
      // A cell with nothing in it would show a lone label on a phone - flag it so the card layout can hide it.
      if (!td.textContent.trim() && !td.querySelector('img, svg, button, a, input, select, textarea')) td.setAttribute('data-empty', '1');
      else td.removeAttribute('data-empty');
      col += parseInt(td.getAttribute('colspan') || '1', 10) || 1;
    });
  });
};

export default {
  mounted: apply,
  updated: apply,
};
