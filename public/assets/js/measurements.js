const form = document.getElementById('passport-form');
const unit = document.getElementById('measurement-unit');
let previousUnit = unit.value;
const rows = [...form.querySelectorAll('.measurement-row')];
const add = document.getElementById('add-measurement');
const status = document.getElementById('measurement-row-status');
const canonicalCm = new WeakMap();
for (const input of form.querySelectorAll('[data-measurement-value]')) {
    const remember = () => canonicalCm.set(input, input.value === '' ? null : Number(input.value) * (unit.value === 'inch' ? 2.54 : 1));
    remember();
    input.addEventListener('input', remember);
    input.max = unit.value === 'inch' ? '393.69' : '999.99';
}
for (const row of rows) {
    row.hidden = row.hasAttribute('data-extra-row');
    const remove = row.querySelector('.remove-measurement');
    remove.hidden = false;
    remove.addEventListener('click', () => {
        row.querySelectorAll('input').forEach(input => { input.value = ''; canonicalCm.delete(input); });
        row.hidden = true;
        add.hidden = false;
        add.focus();
    });
}
add.hidden = rows.every(row => !row.hidden);
add.addEventListener('click', () => {
    const row = rows.find(row => row.hidden);
    if (row) { row.hidden = false; row.querySelector('input').focus(); }
    add.hidden = rows.every(row => !row.hidden);
    status.hidden = false;
    status.textContent = `${rows.filter(row => !row.hidden).length} measurement rows available (maximum 30).`;
});
unit.addEventListener('change', () => {
    for (const input of form.querySelectorAll('[data-measurement-value]')) {
        if (input.value !== '' && Number.isFinite(Number(input.value))) {
            const cm = canonicalCm.get(input) ?? Number(input.value) * (previousUnit === 'inch' ? 2.54 : 1);
            canonicalCm.set(input, cm);
            input.value = (unit.value === 'inch' ? cm / 2.54 : cm).toFixed(2);
        }
        input.max = unit.value === 'inch' ? '393.69' : '999.99';
    }
    document.querySelectorAll('[data-unit-label]').forEach(label => { label.textContent = unit.value; });
    previousUnit = unit.value;
});
