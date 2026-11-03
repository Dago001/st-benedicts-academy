/**
 * St. Benedict's Early Years British Academy
 * Branded Page Transition Preloader with 3D Crest Spinner
 */
(function() {
    'use strict';

    function initPreloader() {
        var isNavigating = false;
        var navTimer = null;
        var safetyTimer = null;
        var DURATION_MS = 1350; // 1.35s spin before showing destination page

        function getPreloader() {
            return document.getElementById('stbPagePreloader');
        }

        function showPreloader() {
            var el = getPreloader();
            if (!el) return;
            el.classList.remove('is-hidden');
            el.classList.add('is-active');
            el.setAttribute('aria-hidden', 'false');
        }

        function hidePreloader() {
            isNavigating = false;
            if (navTimer) { clearTimeout(navTimer); navTimer = null; }
            if (safetyTimer) { clearTimeout(safetyTimer); safetyTimer = null; }
            var el = getPreloader();
            if (!el) return;
            el.classList.remove('is-active');
            el.classList.add('is-hidden');
            el.setAttribute('aria-hidden', 'true');
        }

        function navigateWithSpinner(url) {
            if (isNavigating) return;
            isNavigating = true;

            showPreloader();

            navTimer = setTimeout(function() {
                window.location.href = url;
            }, DURATION_MS);

            // Safety timeout: dismiss if navigation is aborted or delayed
            safetyTimer = setTimeout(function() {
                hidePreloader();
            }, 7000);
        }

        // Global link click handler (runs in bubble phase so button/onclick handlers run first)
        document.addEventListener('click', function(e) {
            // Ignore if default prevented by inline onclick / confirm()
            if (e.defaultPrevented) return;

            // Only primary mouse button or touch (ignore middle/right click, ctrl/cmd/shift/alt)
            if (e.button !== undefined && e.button !== 0) return;
            if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;

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
            if ((link.target && link.target !== '_self') || link.hasAttribute('download')) {
                return;
            }

            // Ignore links that trigger modals or in-page actions
            var onclickAttr = link.getAttribute('onclick') || '';
            if (onclickAttr) {
                var lower = onclickAttr.toLowerCase();
                if (lower.indexOf('modal') !== -1 || lower.indexOf('deactivate') !== -1 ||
                    lower.indexOf('delete') !== -1 || lower.indexOf('activate') !== -1 ||
                    lower.indexOf('toggle') !== -1 || lower.indexOf('confirm(') !== -1) {
                    return;
                }
            }

            if (link.dataset.toggle === 'modal' || link.dataset.dismiss === 'modal' ||
                link.classList.contains('close') || link.classList.contains('modal-close') ||
                link.classList.contains('sidebar-close-btn') || link.classList.contains('mobile-menu-toggle')) {
                return;
            }

            // Resolve full URL
            var targetUrl = link.href;
            if (!targetUrl) return;

            // Check if link is internal
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
        }, false);

        // Standard navigation form submission
        document.addEventListener('submit', function(e) {
            var form = e.target;
            if (!form || (form.target && form.target !== '_self') || form.getAttribute('data-ajax') === 'true') return;
            if (e.defaultPrevented) return;

            // Don't intercept modals or in-page actions
            if (form.closest('.modal') || form.id === 'deactivateForm' || form.id === 'deleteForm' || form.id === 'activateForm') {
                return;
            }

            // Show spinning logo while the form submits to the server
            showPreloader();
        });

        // BFCache / Browser Back or Forward button navigation
        window.addEventListener('pageshow', function() {
            hidePreloader();
        });

        // Hide preloader smoothly after page is fully ready
        if (document.readyState === 'complete') {
            setTimeout(hidePreloader, 200);
        } else {
            window.addEventListener('load', function() {
                setTimeout(hidePreloader, 200);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPreloader);
    } else {
        initPreloader();
    }
})();
