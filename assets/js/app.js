function initializeApp() {
    const toggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            sidebar.classList.toggle('open');
        });
    }

    document.querySelectorAll('[data-confirm]').forEach(function (element) {
        element.addEventListener('click', function (event) {
            if (!window.confirm(element.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-toggle-password]').forEach(function (toggle) {
        const password = document.getElementById(toggle.getAttribute('aria-controls'));
        if (!password) return;

        toggle.addEventListener('click', function () {
            const isVisible = password.type === 'text';
            password.type = isVisible ? 'password' : 'text';
            toggle.textContent = isVisible ? 'Show' : 'Hide';
            toggle.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
            toggle.setAttribute('aria-pressed', String(!isVisible));
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeApp);
} else {
    initializeApp();
}
