// Journal values stay strings. Accounting arithmetic belongs to the server.
document.querySelectorAll('[data-journal-form]').forEach((form) => {
    const rows = form.querySelector('[data-lines]');
    const allocationRows = form.querySelector('[data-allocations]');
    const empty = form.querySelector('[data-empty-lines]');
    const refreshAllocationLines = (clear = false) => {
        const count = rows.querySelectorAll('[data-line]').length;
        allocationRows.querySelectorAll('[data-allocation] select[data-allocation-field$="_line_index"]').forEach((select) => {
            const selected = clear ? '' : select.value;
            select.replaceChildren(new Option('اختر السطر', ''));
            for (let index = 0; index < count; index += 1) {
                select.add(new Option(`سطر ${index + 1}`, String(index)));
            }
            select.value = selected !== '' && Array.from(select.options).some((option) => option.value === selected) ? selected : '';
        });
    };
    const renumber = (clearAllocations = false) => {
        rows.querySelectorAll('[data-line]').forEach((row, index) => {
            row.querySelectorAll('[data-field]').forEach((field) => {
                field.name = `lines[${index}][${field.dataset.field}]`;
            });
        });
        empty.hidden = rows.children.length !== 0;
        refreshAllocationLines(clearAllocations);
    };
    const renumberAllocations = () => {
        allocationRows.querySelectorAll('[data-allocation]').forEach((row, index) => {
            row.querySelectorAll('[data-allocation-field]').forEach((field) => {
                field.name = `allocations[${index}][${field.dataset.allocationField}]`;
            });
        });
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
            renumber(true);
        }
    });
    form.querySelector('[data-add-allocation]').addEventListener('click', () => {
        const fragment = form.querySelector('[data-allocation-template]').content.cloneNode(true);
        allocationRows.append(fragment);
        renumberAllocations();
        refreshAllocationLines();
        allocationRows.lastElementChild.querySelector('select').focus();
    });
    allocationRows.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-allocation]');
        if (button) {
            button.closest('[data-allocation]').remove();
            renumberAllocations();
        }
    });
    renumber();
    renumberAllocations();
});
