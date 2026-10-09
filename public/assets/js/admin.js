// Administrator dashboard helpers. No inline scripts (strict CSP).
(function () {
    'use strict';

    // Explicit confirmation for sensitive actions: <form data-confirm="...">
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            e.preventDefault();
            return;
        }
        var button = form.querySelector('[type=submit]');
        if (button) {
            // Prevent double submission of state-changing actions.
            setTimeout(function () { button.disabled = true; }, 0);
        }
    });

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-toggle-sidebar]')) {
            document.getElementById('sidebar').classList.toggle('open');
        }
        var copy = e.target.closest('[data-copy]');
        if (copy && navigator.clipboard) {
            e.preventDefault();
            navigator.clipboard.writeText(copy.getAttribute('data-copy'));
            copy.textContent = 'Copied';
        }
    });

    // Mission form: show only the fields relevant to the selected type.
    var type = document.querySelector('[data-mission-type]');
    if (type) {
        var sync = function () {
            document.querySelectorAll('[data-for-types]').forEach(function (el) {
                el.hidden = el.getAttribute('data-for-types').split(',').indexOf(type.value) === -1;
            });
        };
        type.addEventListener('change', sync);
        sync();
    }
})();
