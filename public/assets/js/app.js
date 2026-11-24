/**
 * SyncClass — shell behaviour (Phase 17, NFR-08/NFR-09).
 *
 * The application ships no Bootstrap JavaScript, but the notifications page
 * uses Bootstrap's modal markup and attributes. This file supplies the two
 * interactions the shell needs, in plain DOM code:
 *
 *   1. the mobile navigation toggle (aria-expanded, Escape closes);
 *   2. a focus-managed modal for `[data-bs-toggle="modal"]` /
 *      `[data-bs-dismiss="modal"]`, including Escape, backdrop click and
 *      returning focus to the opener.
 *
 * It is deliberately dependency-free and small; forms keep working without it.
 */
(function () {
    'use strict';

    function onReady(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }

        callback();
    }

    // ---------------------------------------------------------------------
    // Mobile navigation
    // ---------------------------------------------------------------------
    function initNavigation() {
        var toggle = document.querySelector('.nav-toggle');
        var nav = document.getElementById('primary-nav');

        if (toggle === null || nav === null) {
            return;
        }

        function setOpen(open) {
            nav.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        toggle.addEventListener('click', function () {
            setOpen(toggle.getAttribute('aria-expanded') !== 'true');
        });

        // A navigation is a page change: collapse the menu after choosing.
        nav.addEventListener('click', function (event) {
            if (event.target.closest('a') !== null && window.matchMedia('(max-width: 900px)').matches) {
                setOpen(false);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
                setOpen(false);
                toggle.focus();
            }
        });

        // Leaving the small-screen range restores the desktop bar.
        window.addEventListener('resize', function () {
            if (!window.matchMedia('(max-width: 900px)').matches) {
                setOpen(false);
            }
        });
    }

    // ---------------------------------------------------------------------
    // Modal (Bootstrap-compatible markup, no Bootstrap JS)
    // ---------------------------------------------------------------------
    var activeModal = null;
    var returnFocus = null;

    function focusable(modal) {
        return modal.querySelector(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );
    }

    function closeModal() {
        if (activeModal === null) {
            return;
        }

        activeModal.classList.remove('show');
        activeModal.style.display = 'none';
        activeModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');

        var backdrop = document.querySelector('.modal-backdrop');
        if (backdrop !== null) {
            backdrop.remove();
        }

        var modal = activeModal;
        activeModal = null;

        if (returnFocus !== null && document.contains(returnFocus)) {
            returnFocus.focus();
        } else {
            modal.setAttribute('tabindex', '-1');
            modal.focus();
        }

        returnFocus = null;
    }

    function openModal(selector) {
        var modal = document.querySelector(selector);

        if (modal === null) {
            return;
        }

        returnFocus = document.activeElement;
        activeModal = modal;

        modal.style.display = 'block';
        modal.setAttribute('aria-hidden', 'false');
        modal.classList.add('show');
        document.body.classList.add('modal-open');

        var backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.addEventListener('click', closeModal);
        document.body.appendChild(backdrop);

        var target = focusable(modal);
        if (target !== null) {
            target.focus();
        }
    }

    function initModals() {
        document.addEventListener('click', function (event) {
            var opener = event.target.closest('[data-bs-toggle="modal"]');

            if (opener !== null) {
                event.preventDefault();
                openModal(opener.getAttribute('data-bs-target') || '');
                return;
            }

            var dismiss = event.target.closest('[data-bs-dismiss="modal"]');

            if (dismiss !== null) {
                event.preventDefault();
                closeModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && activeModal !== null) {
                event.preventDefault();
                closeModal();
            }
        });
    }

    onReady(function () {
        initNavigation();
        initModals();
    });
})();
