/*
 * Admin dashboard behaviour. Plain JavaScript, no dependencies, no inline
 * scripts (strict CSP). Every interaction degrades to a normal link/form.
 */
(function () {
    'use strict';

    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

    // ------------------------------------------------------------ toasts

    var toastRoot = $('#toasts');
    var icons = { success: 'check-circle', error: 'x-circle', info: 'info' };

    function toast(message, type) {
        if (!toastRoot) return;
        type = type || 'info';
        var el = document.createElement('div');
        el.className = 'toast toast-' + type;
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
        el.innerHTML = '<svg class="icon" aria-hidden="true"><use href="#i-' + icons[type] + '"/></svg>' +
            '<div class="toast-body"></div>' +
            '<button type="button" class="toast-close" aria-label="Dismiss"><svg class="icon icon-sm" aria-hidden="true"><use href="#i-x"/></svg></button>';
        $('.toast-body', el).textContent = message;
        toastRoot.appendChild(el);
        var remove = function () { if (el.parentNode) el.parentNode.removeChild(el); };
        $('.toast-close', el).addEventListener('click', remove);
        if (type !== 'error') setTimeout(remove, 6000);
    }
    window.adminToast = toast;

    $$('[data-flash]').forEach(function (node) {
        toast(node.getAttribute('data-flash'), node.getAttribute('data-flash-type') || 'success');
    });

    // ------------------------------------------------------------ sidebar drawer (mobile)

    var sidebar = $('#sidebar');
    var overlay = $('#overlay');
    var menuButton = $('[data-menu-toggle]');

    function setDrawer(open) {
        if (!sidebar) return;
        sidebar.classList.toggle('is-open', open);
        if (overlay) overlay.classList.toggle('is-open', open);
        if (menuButton) menuButton.setAttribute('aria-expanded', String(open));
        if (open) {
            var current = $('[aria-current="page"]', sidebar) || $('a', sidebar);
            if (current) current.focus();
        } else if (menuButton && sidebar.contains(document.activeElement)) {
            menuButton.focus();
        }
    }
    if (menuButton) menuButton.addEventListener('click', function () { setDrawer(!sidebar.classList.contains('is-open')); });
    if (overlay) overlay.addEventListener('click', function () { setDrawer(false); });

    // ------------------------------------------------------------ dropdown menus

    function closeDropdowns(except) {
        $$('[data-dropdown]').forEach(function (dd) {
            if (dd === except) return;
            var btn = $('[data-dropdown-toggle]', dd), menu = $('.dropdown-menu', dd);
            if (menu && !menu.hidden) { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
        });
    }

    $$('[data-dropdown]').forEach(function (dd) {
        var btn = $('[data-dropdown-toggle]', dd), menu = $('.dropdown-menu', dd);
        if (!btn || !menu) return;
        var items = function () { return $$('a, button', menu); };
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = menu.hidden;
            closeDropdowns(dd);
            menu.hidden = !open;
            btn.setAttribute('aria-expanded', String(open));
            if (open && items()[0]) items()[0].focus();
        });
        menu.addEventListener('keydown', function (e) {
            var list = items(), i = list.indexOf(document.activeElement);
            if (e.key === 'ArrowDown') { e.preventDefault(); list[(i + 1) % list.length].focus(); }
            if (e.key === 'ArrowUp') { e.preventDefault(); list[(i - 1 + list.length) % list.length].focus(); }
            if (e.key === 'Escape') { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); btn.focus(); }
            if (e.key === 'Tab') { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
        });
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-dropdown]')) closeDropdowns();
    });

    // ------------------------------------------------------------ dialogs (native <dialog>)

    var lastTrigger = null;

    function openDialog(dialog, trigger) {
        if (!dialog || dialog.open) return;
        lastTrigger = trigger || document.activeElement;
        dialog.showModal();
        var focusTarget = $('[autofocus], input:not([type=hidden]):not([disabled]), select, textarea', dialog) || $('.btn-primary, .btn-danger, .btn-success', dialog);
        if (focusTarget) focusTarget.focus();
    }

    $$('dialog.modal').forEach(function (dialog) {
        // Click on the backdrop (the dialog element itself) closes it.
        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) dialog.close('cancel');
        });
        dialog.addEventListener('close', function () {
            if (lastTrigger && document.contains(lastTrigger)) lastTrigger.focus();
        });
    });

    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-dialog-open]');
        if (opener) {
            e.preventDefault();
            openDialog(document.getElementById(opener.getAttribute('data-dialog-open')), opener);
            return;
        }
        var closer = e.target.closest('[data-dialog-close]');
        if (closer) {
            var d = closer.closest('dialog');
            if (d) d.close('cancel');
        }
    });

    // Re-open the dialog whose form failed server-side validation.
    var reopen = document.body.getAttribute('data-reopen-dialog');
    if (reopen && document.getElementById(reopen)) openDialog(document.getElementById(reopen));

    // ------------------------------------------------------------ confirmation dialog

    var confirmDialog = $('#confirm-dialog');

    function askConfirm(form, submitter) {
        if (!confirmDialog) return true;
        var variant = form.getAttribute('data-confirm-variant') || 'primary';
        $('#confirm-dialog-title', confirmDialog).textContent = form.getAttribute('data-confirm-title') || 'Are you sure?';
        $('#confirm-dialog-desc', confirmDialog).textContent = form.getAttribute('data-confirm');
        var ok = $('[data-confirm-ok]', confirmDialog);
        ok.textContent = form.getAttribute('data-confirm-button') || 'Confirm';
        ok.className = 'btn ' + (variant === 'danger' ? 'btn-danger' : 'btn-primary');
        var icon = $('.modal-icon', confirmDialog);
        icon.className = 'modal-icon ' + (variant === 'danger' ? 'is-danger' : 'is-warning');

        ok.onclick = function () {
            confirmDialog.close('ok');
            form.setAttribute('data-confirmed', '1');
            if (submitter && submitter.name) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden'; hidden.name = submitter.name; hidden.value = submitter.value;
                form.appendChild(hidden);
            }
            markLoading(form, submitter);
            form.submit();
        };
        openDialog(confirmDialog, submitter);
        return false;
    }

    // ------------------------------------------------------------ form submission states

    function markLoading(form, submitter) {
        var button = submitter || $('[type=submit]', form);
        if (button) button.classList.add('is-loading');
        $$('[type=submit]', form).forEach(function (b) { b.disabled = true; });
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.hasAttribute('data-confirm') && form.getAttribute('data-confirmed') !== '1') {
            e.preventDefault();
            askConfirm(form, e.submitter);
            return;
        }
        if ((form.getAttribute('method') || 'get').toLowerCase() === 'post') {
            // Prevent double submission of state-changing actions; let the browser
            // submit first so the clicked button's name/value is included.
            var submitter = e.submitter;
            setTimeout(function () { markLoading(form, submitter); }, 0);
        }
    });

    // Restore buttons when navigating back to a cached page.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        $$('.is-loading').forEach(function (b) { b.classList.remove('is-loading'); });
        $$('[type=submit][disabled]').forEach(function (b) { b.disabled = false; });
        $$('form[data-confirmed]').forEach(function (f) { f.removeAttribute('data-confirmed'); });
    });

    // ------------------------------------------------------------ misc controls

    document.addEventListener('click', function (e) {
        var copy = e.target.closest('[data-copy]');
        if (copy) {
            e.preventDefault();
            var text = copy.getAttribute('data-copy');
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () { toast('Copied to clipboard', 'success'); }, function () { toast('Copy failed – select the text manually', 'error'); });
            } else {
                var area = document.createElement('textarea');
                area.value = text; document.body.appendChild(area); area.select();
                try { document.execCommand('copy'); toast('Copied to clipboard', 'success'); } catch (err) { toast('Copy failed', 'error'); }
                document.body.removeChild(area);
            }
            return;
        }

        var toggle = e.target.closest('[data-toggle-target]');
        if (toggle) {
            var target = document.getElementById(toggle.getAttribute('data-toggle-target'));
            if (!target) return;
            target.hidden = !target.hidden;
            toggle.setAttribute('aria-expanded', String(!target.hidden));
            var labels = (toggle.getAttribute('data-toggle-labels') || 'Show data table|Hide data table').split('|');
            toggle.textContent = target.hidden ? labels[0] : labels[1];
            return;
        }

        // Whole-row navigation for list tables (links and buttons keep their own behaviour).
        var row = e.target.closest('tr[data-href]');
        if (row && !e.target.closest('a, button, input, select, label, form')) {
            window.location.href = row.getAttribute('data-href');
        }
    });

    $$('select[data-autosubmit]').forEach(function (select) {
        select.addEventListener('change', function () { select.form.submit(); });
    });

    // Global keyboard shortcuts.
    document.addEventListener('keydown', function (e) {
        var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName) || document.activeElement.isContentEditable;
        if (e.key === '/' && !typing && !e.metaKey && !e.ctrlKey) {
            var search = $('#global-search');
            if (search) { e.preventDefault(); search.focus(); search.select(); }
        }
        if (e.key === 'Escape' && sidebar && sidebar.classList.contains('is-open')) setDrawer(false);
    });

    // Mission form: show only fields relevant to the selected type.
    var missionType = $('[data-mission-type]');
    if (missionType) {
        var sync = function () {
            $$('[data-for-types]').forEach(function (el) {
                var show = el.getAttribute('data-for-types').split(',').indexOf(missionType.value) !== -1;
                el.hidden = !show;
                $$('input, select, textarea', el).forEach(function (input) { input.disabled = !show; });
            });
        };
        missionType.addEventListener('change', sync);
        sync();
    }

    // Live JSON check for JSON settings fields.
    $$('textarea[data-json]').forEach(function (area) {
        var hint = document.getElementById(area.id + '-status');
        var check = function () {
            try { JSON.parse(area.value); area.removeAttribute('aria-invalid'); if (hint) { hint.textContent = 'Valid JSON'; hint.className = 'field-help'; } }
            catch (err) { area.setAttribute('aria-invalid', 'true'); if (hint) { hint.textContent = 'Invalid JSON: ' + err.message; hint.className = 'field-error'; } }
        };
        area.addEventListener('input', check);
    });
})();
