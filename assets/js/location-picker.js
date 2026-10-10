// assets/js/location-picker.js - State / LGA dropdowns with an "Other (outside Nigeria)" fallback.
// Server-rendered by includes/locations.php; this script only adds the dependent behaviour.
(function () {
    var OTHER = '__other';
    var cache = {};

    function load(url) {
        if (!cache[url]) cache[url] = fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
        return cache[url];
    }
    function show(el, on) { if (el) el.hidden = !on; }
    function setRequired(el, on) { if (el) { if (on) el.setAttribute('required', ''); else el.removeAttribute('required'); } }

    function init(root) {
        var state = root.querySelector('[name="state"]');
        var lga = root.querySelector('[name="lga"]');
        var lgaWrap = root.querySelector('.loc-lga');
        var lgaOtherWrap = root.querySelector('.loc-lga-other');
        var lgaOther = root.querySelector('[name="lga_other"]');
        var foreign = root.querySelector('.loc-foreign');
        var country = root.querySelector('[name="country_other"]');
        var region = root.querySelector('[name="state_other"]');
        var wantRequired = state.hasAttribute('required');

        function fill(data) {
            var list = data[state.value] || [];
            var keep = lga.value;
            lga.innerHTML = '';
            var first = document.createElement('option');
            first.value = '';
            first.textContent = list.length ? 'Select local government' : 'Select a state first';
            lga.appendChild(first);
            list.forEach(function (n) {
                var o = document.createElement('option'); o.value = n; o.textContent = n; lga.appendChild(o);
            });
            if (list.length) {
                var other = document.createElement('option'); other.value = OTHER; other.textContent = 'Other (not listed)'; lga.appendChild(other);
            }
            if (keep && lga.querySelector('option[value="' + keep.replace(/"/g, '\\"') + '"]')) lga.value = keep;
        }

        function sync(data, stateChanged) {
            var isForeign = state.value === OTHER;
            show(foreign, isForeign);
            show(lgaWrap, !isForeign);
            setRequired(country, isForeign); setRequired(region, isForeign);
            setRequired(lga, !isForeign && wantRequired);
            if (isForeign) { lga.value = ''; show(lgaOtherWrap, false); setRequired(lgaOther, false); return; }
            if (stateChanged) fill(data);
            var other = lga.value === OTHER;
            show(lgaOtherWrap, other); setRequired(lgaOther, other);
        }

        load(root.getAttribute('data-src')).then(function (data) {
            state.addEventListener('change', function () { lga.value = ''; sync(data, true); if (state.value === OTHER && country) country.focus(); });
            lga.addEventListener('change', function () { sync(data, false); if (lga.value === OTHER && lgaOther) lgaOther.focus(); });
            sync(data, false);
        }).catch(function () {
            // Data file unreachable: let people type their details instead of blocking the form
            [lga, lgaOther].forEach(function (el) { if (el) setRequired(el, false); });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.location-picker').forEach(init);
    });
})();
