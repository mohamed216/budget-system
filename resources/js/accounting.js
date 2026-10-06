// Journal values stay strings. Accounting arithmetic belongs to the server.
document.querySelectorAll('[data-journal-form]').forEach((form) => {
    const rows = form.querySelector('[data-lines]');
    const empty = form.querySelector('[data-empty-lines]');
    const renumber = () => {
        rows.querySelectorAll('[data-line]').forEach((row, index) => {
            row.querySelectorAll('[data-field]').forEach((field) => {
                field.name = `lines[${index}][${field.dataset.field}]`;
            });
        });
        empty.hidden = rows.children.length !== 0;
    };
    form.querySelector('[data-add-line]').addEventListener('click', () => {
        const fragment = form.querySelector('[data-line-template]').content.cloneNode(true);
        const row = fragment.querySelector('[data-line]');
        rows.append(fragment);
        renumber();
        row.querySelector('select').focus();
    });
    rows.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-line]');
        if (button) {
            button.closest('[data-line]').remove();
            renumber();
        }
    });
    renumber();
});
