for (const button of document.querySelectorAll('[data-toggle-password]')) {
    const input = document.getElementById(button.dataset.togglePassword);
    button.hidden = false;
    button.addEventListener('click', () => {
        const isVisible = input.type === 'password';
        input.type = isVisible ? 'text' : 'password';
        button.textContent = isVisible ? 'Hide password' : 'Show password';
        button.setAttribute('aria-pressed', String(isVisible));
    });
}

const invalidField = document.querySelector('[aria-invalid="true"]');
if (invalidField) {
    invalidField.focus();
}
