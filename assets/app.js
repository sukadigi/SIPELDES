document.addEventListener('input', event => {
    const search = event.target.closest('[data-table-search]');
    if (!search) return;
    const table = document.querySelector(search.dataset.tableSearch);
    if (!table) return;
    const query = search.value.trim().toLocaleLowerCase('id');
    table.querySelectorAll('tbody tr').forEach(row => {
        row.hidden = !row.textContent.toLocaleLowerCase('id').includes(query);
    });
});

document.addEventListener('click', event => {
    if (event.target.closest('[data-print]')) window.print();
});