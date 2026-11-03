/**
 * St. Benedict's Early Years British Academy
 * Branded Page Transition Preloader with 3D Crest Spinner
 */
(function() {
    'use strict';

    function initPreloader() {
        var preloader = document.getElementById('stbPagePreloader');
        if (!preloader) return;

        var isNavigating = false;
        var navTimer = null;
        var safetyTimer = null;
        var DURATION_MS = 1350; // 1.35 seconds spin before showing the page

        function showPreloader() {
            preloader.classList.remove('is-hidden');
            preloader.classList.add('is-active');
            preloader.setAttribute('aria-hidden', 'false');
        }

        function hidePreloader() {
            isNavigating = false;
            if (navTimer) clearTimeout(navTimer);
            if (safetyTimer) clearTimeout(safetyTimer);
            preloader.classList.remove('is-active');
            preloader.classList.add('is-hidden');
            preloader.setAttribute('aria-hidden', 'true');
        }

        function navigateWithSpinner(url) {
            if (isNavigating) return;
            isNavigating = true;

            showPreloader();

            navTimer = setTimeout(function() {
                window.location.href = url;
            }, DURATION_MS);

            // Safety timeout in case navigation stalls or is cancelled
            safetyTimer = setTimeout(function() {
                hidePreloader();
            }, 7000);
        }

        // Global link click handler
        document.addEventListener('click', function(e) {
            // Ignore non-primary clicks (middle click, right click) or modified clicks (ctrl/cmd/shift/alt)
            if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
            if (e.defaultPrevented) return;

            var link = e.target.closest('a');
            if (!link) return;

            var href = link.getAttribute('href');
            if (!href) return;

            // Ignore hash-only, empty, javascript, mailto, tel, whatsapp
            if (href === '#' || href.charAt(0) === '#' || href.indexOf('javascript:') === 0 ||
                href.indexOf('mailto:') === 0 || href.indexOf('tel:') === 0 || href.indexOf('wa.me') !== -1) {
                return;
            }

            // Ignore links meant to open in new tab or download files
            if (link.target === '_blank' || link.hasAttribute('download')) {
                return;
            }

            // Ignore links that trigger modals or actions (e.g. closeModal, confirmDeactivate, deleteModal)
            var onclickAttr = link.getAttribute('onclick') || '';
            if (onclickAttr) {
                var lower = onclickAttr.toLowerCase();
                if (lower.indexOf('modal') !== -1 || lower.indexOf('deactivate') !== -1 ||
                    lower.indexOf('delete') !== -1 || lower.indexOf('activate') !== -1 ||
                    lower.indexOf('toggle') !== -1 || lower.indexOf('filter') !== -1) {
                    return;
                }
            }

            if (link.dataset.toggle === 'modal' || link.dataset.dismiss === 'modal' || link.classList.contains('close') || link.classList.contains('modal-close')) {
                return;
            }

            // Resolve full URL
            var targetUrl = link.href;
            if (!targetUrl) return;

            // Check if link is to an external origin
            try {
                var currentOrigin = window.location.origin;
                var targetOrigin = new URL(targetUrl, currentOrigin).origin;
                if (targetOrigin !== currentOrigin) {
                    return; // External site
                }

                // If same URL with only a hash change, do not trigger full page loader
                var targetParsed = new URL(targetUrl, currentOrigin);
                if (targetParsed.pathname === window.location.pathname &&
                    targetParsed.search === window.location.search &&
                    targetParsed.hash) {
                    return;
                }
            } catch(err) {
                return;
            }

            // Valid navigation link: trigger spinning crest transition!
            e.preventDefault();
            navigateWithSpinner(targetUrl);
        }, true);

        // Standard navigation form submission
        document.addEventListener('submit', function(e) {
            var form = e.target;
            if (!form || form.target === '_blank' || form.getAttribute('data-ajax') === 'true') return;
            if (e.defaultPrevented) return;

            // Don't intercept modals or in-page actions
            if (form.closest('.modal') || form.id === 'deactivateForm' || form.id === 'deleteForm' || form.id === 'activateForm') {
                return;
            }

            // Show spinning logo while the form submits to the server
            showPreloader();
        });

        // BFCache / Browser Back or Forward button navigation
        window.addEventListener('pageshow', function(e) {
            hidePreloader();
        });

        // Hide preloader smoothly after page is fully ready
        window.addEventListener('DOMContentLoaded', function() {
            setTimeout(hidePreloader, 250);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPreloader);
    } else {
        initPreloader();
    }
})();
