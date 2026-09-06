/* WP Shopify Checkout Bridge — front-end.
 *
 * Responsibilities:
 *   - report one lightweight interaction signal (for the risk shield);
 *   - intercept the theme "Checkout" click and build the Shopify checkout in the
 *     background behind a spinner, with a server-side redirect fallback;
 *   - handle the [wpsb_buy_button] direct-buy click;
 *   - keep the spinner from ever getting stuck (clears on return / 8s timeout).
 */
(function () {
    'use strict';

    var WPSB = window.WPSB || {};
    var SPINNER_TIMEOUT = 8000;
    var spinnerTimer = null;

    function post(action, data) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', WPSB.nonce || '');
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
        return fetch(WPSB.ajax_url, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); });
    }

    /* ---- Spinner ------------------------------------------------------- */

    function showSpinner(label) {
        var el = document.getElementById('wpsb-spinner');
        if (!el) {
            el = document.createElement('div');
            el.id = 'wpsb-spinner';
            el.className = 'wpsb-spinner';
            el.innerHTML = '<div class="wpsb-spinner__box"><div class="wpsb-spinner__ring"></div>'
                + '<p class="wpsb-spinner__text"></p></div>';
            document.body.appendChild(el);
        }
        el.querySelector('.wpsb-spinner__text').textContent = label || 'Taking you to secure checkout…';
        el.classList.add('is-visible');
        // Safety: never let the overlay freeze the screen.
        clearTimeout(spinnerTimer);
        spinnerTimer = setTimeout(hideSpinner, SPINNER_TIMEOUT);
    }

    function hideSpinner() {
        clearTimeout(spinnerTimer);
        var el = document.getElementById('wpsb-spinner');
        if (el) { el.classList.remove('is-visible'); }
    }

    // Clear the spinner when the shopper comes back (Back button / bfcache).
    window.addEventListener('pageshow', hideSpinner);
    window.addEventListener('popstate', hideSpinner);

    /* ---- Interaction signal ------------------------------------------- */

    var signalled = false;
    function reportSignal() {
        if (signalled) { return; }
        signalled = true;
        post('wpsb_signal', {}).catch(function () {});
    }
    ['scroll', 'click', 'keydown', 'touchstart', 'mousemove'].forEach(function (ev) {
        window.addEventListener(ev, reportSignal, { once: true, passive: true });
    });

    /* ---- Direct buy button -------------------------------------------- */

    document.addEventListener('click', function (e) {
        var buy = e.target.closest ? e.target.closest('.wpsb-buy') : null;
        if (!buy) { return; }
        e.preventDefault();
        var variant = buy.getAttribute('data-variant');
        var qty = buy.getAttribute('data-qty') || 1;
        if (!variant) { return; }
        showSpinner();
        firePixels();
        post('wpsb_buy_now', { variant: variant, qty: qty }).then(function (res) {
            if (res && res.success && res.data && res.data.url) {
                window.location.href = res.data.url;
            } else {
                hideSpinner();
                var msg = (res && res.data && res.data.message) || 'Could not start checkout.';
                fallbackLink(buy) || window.alert(msg);
            }
        }).catch(function () {
            hideSpinner();
            fallbackLink(buy);
        });
    });

    function fallbackLink(buy) {
        var a = buy.parentNode && buy.parentNode.querySelector('.wpsb-fallback');
        if (a) { window.location.href = a.href; return true; }
        return false;
    }

    /* ---- WooCommerce checkout interception ---------------------------- */

    // On the cart page, intercept the "Proceed to checkout" click so we build
    // the Shopify checkout in the background instead of loading the heavy WC
    // checkout page first. The server-side redirect remains as a fallback.
    if (WPSB.wc) {
        document.addEventListener('click', function (e) {
            var link = e.target.closest ? e.target.closest('a.checkout-button, a.wc-proceed-to-checkout, .checkout-button') : null;
            if (!link) { return; }
            e.preventDefault();
            showSpinner();
            firePixels();
            post('wpsb_build_checkout', {}).then(function (res) {
                if (res && res.success && res.data && res.data.url) {
                    window.location.href = res.data.url;
                } else if (WPSB.wc_checkout) {
                    // Fall back to the normal WooCommerce checkout (server-side
                    // template_redirect will take it to Shopify).
                    window.location.href = WPSB.wc_checkout;
                } else {
                    hideSpinner();
                }
            }).catch(function () {
                if (WPSB.wc_checkout) {
                    window.location.href = WPSB.wc_checkout;
                } else {
                    hideSpinner();
                }
            });
        });
    }

    /* ---- Deferred pixels ---------------------------------------------- */

    var pixelsFired = false;
    function firePixels() {
        if (pixelsFired) { return; }
        pixelsFired = true;
        try {
            if (WPSB.pixel_fb && window.fbq) { window.fbq('track', 'InitiateCheckout'); }
            if (WPSB.pixel_tt && window.ttq) { window.ttq.track('InitiateCheckout'); }
        } catch (err) { /* pixels are best-effort */ }
    }

})();
