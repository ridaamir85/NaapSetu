(() => {
    const tailorSelect = document.querySelector('#tailor_id');
    const serviceSelect = document.querySelector('#service_id');

    if (!tailorSelect || !serviceSelect) return;

    const shopName = document.querySelector('#summary-shop-name');
    const shopLocation = document.querySelector('#summary-shop-location');
    const shopAddress = document.querySelector('#summary-shop-address');
    const garment = document.querySelector('#summary-garment');
    const price = document.querySelector('#summary-price');
    const days = document.querySelector('#summary-days');
    const groups = Array.from(serviceSelect.querySelectorAll('optgroup[data-tailor-id]'));

    const selectedShop = () => tailorSelect.selectedOptions[0];
    const selectedService = () => serviceSelect.selectedOptions[0];

    function updateSummary() {
        const shop = selectedShop();
        const item = selectedService();
        const activeItem = item && item.value ? item : null;

        shopName.textContent = activeItem?.dataset.shopName || shop?.dataset.shopName || 'Choose a tailor';
        shopLocation.textContent = activeItem?.dataset.city || shop?.dataset.city || '—';
        shopAddress.textContent = activeItem?.dataset.address || shop?.dataset.address || '—';
        garment.textContent = activeItem?.dataset.garment || 'Choose a service';

        if (!activeItem) {
            price.textContent = 'Custom quotation';
            days.textContent = 'Specified in quotation';
            return;
        }

        const amount = Number(activeItem.dataset.price);
        price.textContent = 'Custom quotation';
        const completionDays = Number(activeItem.dataset.days);
        days.textContent = 'Specified in quotation';
    }

    function updateServiceChoices() {
        const tailorId = tailorSelect.value;
        const currentService = selectedService();
        groups.forEach((group) => {
            group.hidden = Boolean(tailorId) && group.dataset.tailorId !== tailorId;
        });

        if (currentService?.dataset.tailorId && currentService.dataset.tailorId !== tailorId) {
            serviceSelect.value = '';
        }
        updateSummary();
    }

    tailorSelect.addEventListener('change', updateServiceChoices);
    serviceSelect.addEventListener('change', () => {
        const item = selectedService();
        if (item?.dataset.tailorId) {
            tailorSelect.value = item.dataset.tailorId;
            updateServiceChoices();
        } else {
            updateSummary();
        }
    });
    updateServiceChoices();
})();
