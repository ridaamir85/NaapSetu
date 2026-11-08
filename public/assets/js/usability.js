(() => {
    const main = document.querySelector('main');
    if (!main) return;
    main.id ||= 'main';
    if (!document.querySelector('.skip-link')) {
        const skip = document.createElement('a'); skip.className = 'skip-link'; skip.href = '#' + main.id; skip.textContent = 'Skip to content'; document.body.prepend(skip);
    }
    const errors = [...main.querySelectorAll('[role="alert"]')].filter(el => !el.hidden && el.textContent.trim());
    if (errors.length) {
        const summary = document.createElement('section'); summary.className = 'ui-error-summary'; summary.tabIndex = -1;
        const heading = document.createElement('h2'); heading.textContent = 'Please check the following'; summary.append(heading);
        const list = document.createElement('ul');
        errors.forEach((error, i) => {
            error.id ||= 'ui-error-' + i;
            const container = error.parentElement;
            const fields = container.matches('form, main') ? [] : [...container.querySelectorAll('input:not([type="hidden"]),select,textarea')];
            const field = fields.length === 1 ? fields[0] : null;
            const item = document.createElement('li');
            if (field && field.id) {
                field.setAttribute('aria-invalid', 'true');
                const descriptions = new Set((field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean)); descriptions.add(error.id); field.setAttribute('aria-describedby', [...descriptions].join(' '));
                const link = document.createElement('a'); link.href = '#' + field.id; link.textContent = error.textContent.trim(); link.addEventListener('click', () => field.focus()); item.append(link);
            } else { item.textContent = error.textContent.trim(); }
            list.append(item);
        });
        summary.append(list); main.prepend(summary); summary.focus();
    }
    const status = [...main.querySelectorAll('[role="status"]')].find(el => !el.hidden && el.textContent.trim());
    const steps = {
        '/customer/request.php': ['/customer/requests.php', 'View requests & quotations'],
        '/customer/styles.php': ['/customer/request.php', 'Use a style in a request'],
        '/customer/feedback.php': ['/customer/orders.php', 'Return to my orders'],
        '/tailor/profile.php': ['/tailor/services.php', 'Manage services'],
        '/tailor/services.php': ['/tailor/requests.php', 'View incoming requests'],
        '/tailor/requests.php': ['/tailor/requests.php', 'View all incoming requests']
    };
    let step = steps[location.pathname];
    if (location.pathname === '/customer/requests.php' && new URLSearchParams(location.search).get('updated') === 'accepted') step = ['/customer/orders.php', 'View my orders'];
    if (status && step) {
        const wrapper = document.createElement('div'); wrapper.className = 'ui-next-step';
        const link = document.createElement('a'); link.className = 'ui-secondary-action'; link.href = step[0]; link.textContent = step[1]; wrapper.append(link); status.after(wrapper);
    }
    if (status && !errors.length && !main.querySelector('.auth-success')) { status.tabIndex = -1; status.focus({preventScroll:true}); }
})();
