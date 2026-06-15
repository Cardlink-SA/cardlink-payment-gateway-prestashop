/**
 * Cardlink Checkout - Admin Order Page JavaScript
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 */

document.addEventListener('DOMContentLoaded', function () {
    // Hide PS native "Partial refund" button when Cardlink manages refunds for this order.
    // Uses multiple selectors to cover PS 1.7.x, 8.x, and 9.x admin variants.
    var cardlinkPanel = document.getElementById('cardlink-payment-actions');
    if (cardlinkPanel && cardlinkPanel.dataset.hideNativeRefund === '1') {
        [
            '#partial-refund-button',
            '#desc-order-partial_refund',
            '.partial-refund-display',
            'button.js-partial-refund-btn',
            '[data-action="partial_refund"]'
        ].forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) {
                el.style.setProperty('display', 'none', 'important');
            });
        });
    }

    var cardlinkForms = document.querySelectorAll('.cardlink-action-form');

    cardlinkForms.forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var button = form.querySelector('button[type="submit"]');
            var originalHtml = button ? button.innerHTML : '';

            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="material-icons">hourglass_empty</i> Processing...';
            }

            var controller = new AbortController();
            // Abort client-side after 90 s so the user isn't left waiting forever.
            var timeoutId = setTimeout(function () { controller.abort(); }, 90000);

            fetch(form.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form),
                signal: controller.signal
            })
            .then(function (response) {
                clearTimeout(timeoutId);
                // nginx/server may return non-2xx (e.g. 502) — treat as timeout-like.
                if (!response.ok) {
                    throw new Error('server_error');
                }
                return response.json();
            })
            .then(function (data) {
                if (data.status === 'success') {
                    // Show success feedback immediately, then reload.
                    // A short delay lets any background server processing (e.g. order status
                    // update, email sending) complete before the page renders.
                    showCardlinkMessage('success', data.message || 'Operation completed successfully.');
                    setTimeout(function () { window.location.reload(); }, 1500);
                } else {
                    showCardlinkMessage(data.status || 'error', data.message || 'An error occurred.');
                    if (button) {
                        button.disabled = false;
                        button.innerHTML = originalHtml;
                    }
                }
            })
            .catch(function (error) {
                clearTimeout(timeoutId);
                // AbortError = our 90 s client timeout; server_error = 502/5xx from nginx.
                // In both cases the gateway operation may have already completed on the server.
                var msg = 'The operation is taking longer than expected. ' +
                          'Please <a href="javascript:location.reload()">refresh the page</a> to check the current payment status.';
                showCardlinkMessage('warning', msg);
                if (button) {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                }
            });
        });
    });

    function showCardlinkMessage(type, message) {
        var panel = document.querySelector('#cardlink-payment-actions .card-body');
        if (!panel) { return; }

        var existing = panel.querySelector('.cardlink-ajax-message');
        if (existing) { existing.remove(); }

        var alertClass = type === 'error' ? 'danger'
                       : type === 'warning' ? 'warning'
                       : type === 'success' ? 'success'
                       : 'info';

        var div = document.createElement('div');
        div.className = 'alert alert-' + alertClass + ' mb-3 cardlink-ajax-message';
        div.setAttribute('role', 'alert');
        div.innerHTML = '<div class="alert-text"><p>' + message + '</p></div>';
        panel.insertBefore(div, panel.firstChild);
    }

    var captureBtn = document.querySelector('#cardlink-capture-form button');
    var voidBtn    = document.querySelector('#cardlink-void-form button');
    var refundBtn  = document.querySelector('#cardlink-refund-form button');

    if (captureBtn) { captureBtn.title = 'Capture the full authorized amount'; }
    if (voidBtn)    { voidBtn.title    = 'Cancel the authorization and release funds'; }
    if (refundBtn)  { refundBtn.title  = 'Refund the captured amount back to the customer'; }
});
