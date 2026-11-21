document.addEventListener('DOMContentLoaded', function () {
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
});
