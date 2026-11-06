const categorySelect = document.getElementById('category');
const garmentSelect = document.getElementById('garment_type');
const customField = document.getElementById('custom_garment_field');
const customInput = document.getElementById('custom_garment');

function updateCustomField() {
    const isOther = garmentSelect.value === 'Other';
    customField.hidden = !isOther;
    customInput.disabled = !isOther;
    customInput.required = isOther;
}

function updateGarments() {
    garmentSelect.disabled = categorySelect.value === '';
    for (const group of garmentSelect.querySelectorAll('optgroup')) {
        const matchesCategory = group.dataset.category === categorySelect.value;
        group.hidden = !matchesCategory;
        group.disabled = !matchesCategory;
    }
    updateCustomField();
}

categorySelect.addEventListener('change', () => {
    garmentSelect.selectedIndex = 0;
    updateGarments();
});
garmentSelect.addEventListener('change', updateCustomField);
updateGarments();
