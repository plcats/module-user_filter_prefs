(function () {
    function parseActionFromLocation() {
        try {
            var params = new URLSearchParams(window.location.search || '');
            return params.get('-action') || 'list';
        } catch (e) {
            return 'list';
        }
    }

    function isListContext() {
        var action = parseActionFromLocation();
        return action === 'list' || action === 'xf_infinite_scroll';
    }

    function hasExistingUnfilterAction() {
        return !!document.querySelector('a[href*="-qf=unfilter"]');
    }

    function buildUnfilterUrl() {
        var url;
        try {
            url = new URL(window.location.href);
        } catch (e) {
            return null;
        }

        var table = url.searchParams.get('-table');
        if (!table) {
            return null;
        }

        // Build a minimal URL so we don't carry existing filter params into unfilter.
        var cleanUrl = new URL(url.origin + url.pathname);
        cleanUrl.searchParams.set('-table', table);
        cleanUrl.searchParams.set('-action', 'list');
        cleanUrl.searchParams.set('-qf', 'unfilter');
        return cleanUrl.toString();
    }

    function injectUnfilterActionInListSettings() {
        if (!isListContext()) {
            return false;
        }
        if (hasExistingUnfilterAction()) {
            return true;
        }

        var listSettings = document.querySelector('.mobile-list-settings');
        if (!listSettings) {
            return false;
        }

        var unfilterUrl = buildUnfilterUrl();
        if (!unfilterUrl) {
            return false;
        }

        var link = document.createElement('a');
        link.setAttribute('rel', 'child');
        link.setAttribute('href', unfilterUrl);
        link.setAttribute('title', 'Annulla tutti i filtri');
        link.className = 'ufp-unfilter-action';

        var icon = document.createElement('i');
        icon.className = 'material-icons';
        icon.textContent = 'delete_sweep';

        var label = document.createElement('span');
        label.textContent = 'Annulla Filtri';

        link.appendChild(icon);
        link.appendChild(label);
        listSettings.appendChild(link);
        return true;
    }

    function initListUnfilterActionInjection() {
        if (!isListContext()) {
            return;
        }

        if (injectUnfilterActionInListSettings()) {
            return;
        }

        var attempts = 0;
        var timer = setInterval(function () {
            attempts += 1;
            if (injectUnfilterActionInListSettings() || attempts > 20) {
                clearInterval(timer);
            }
        }, 150);
    }

    function markApplyInFilterDialog() {
        // Only run inside the mobile filter dialog iframe context.
        if (typeof window.applyFilters !== 'function') {
            return false;
        }

        if (window.__ufpApplyHooked) {
            return true;
        }

        var originalApplyFilters = window.applyFilters;
        window.applyFilters = function () {
            try {
                if (typeof window.updateFieldValue === 'function') {
                    window.updateFieldValue('-ufp-apply', '1');
                } else if (typeof window.filterSearch === 'string') {
                    if (window.filterSearch.indexOf('-ufp-apply=') === -1) {
                        window.filterSearch += '&-ufp-apply=1';
                    }
                }
            } catch (e) {
                // Best-effort marker injection; preserve original behavior on errors.
            }
            return originalApplyFilters.apply(this, arguments);
        };

        window.__ufpApplyHooked = true;
        return true;
    }

    initListUnfilterActionInjection();

    if (!markApplyInFilterDialog()) {
        var attempts = 0;
        var timer = setInterval(function () {
            attempts += 1;
            if (markApplyInFilterDialog() || attempts > 20) {
                clearInterval(timer);
            }
        }, 150);
    }
})();
