{*
 * Cardlink Checkout - Apple Pay payment option form.
 *}
<div id="cardlink-applepay-container">
    <link rel="stylesheet" href="{$front_css_url|escape:'htmlall':'UTF-8'}" />

    <form method="post" action="{$action}" id="cardlink-applepay-form">
        <input type="hidden" name="payment_method" value="applepay" />

        <img src="{$applepay_logo_url}" id="cardlink_checkout--applepay-payment-method-logo"
            class="cardlink_checkout--payment-option-logo" style="max-height: 38px; width: auto;" />

        {if ($applepay_description != '')}
            <div class="cardlink_checkout--applepay-payment-method-description">
                <p>{$applepay_description}</p>
            </div>
        {/if}

        <div id="applePayDiv" style="min-height: 48px; margin: 15px 0; display: none;"></div>

        <div id="cardlink-applepay-terms-notice" style="margin: 10px 0; color: #6c757d; font-size: 0.9em;">
            <span>{l s='Please accept the terms and conditions to enable Apple Pay.' mod='cardlink_checkout'}</span>
        </div>

        <div id="cardlink-applepay-loading" style="display: none; text-align: center; padding: 10px;">
            <span>{l s='Initializing Apple Pay...' mod='cardlink_checkout'}</span>
        </div>

        <div id="cardlink-applepay-error" style="{if $applepay_initial_error}display: block;{else}display: none;{/if} color: #d9534f; padding: 10px;">
            {if $applepay_initial_error}{$applepay_initial_error|escape:'htmlall':'UTF-8'}{/if}
        </div>
    </form>

    <div id="cardlink-applepay-config"
        data-script-info-url="{$applepay_script_info_url|escape:'htmlall':'UTF-8'}"
        data-wallet-url="{$applepay_wallet_url|escape:'htmlall':'UTF-8'}"
        data-create-xid-url="{$applepay_create_xid_url|escape:'htmlall':'UTF-8'}"
        data-sign-data-url="{$applepay_sign_data_url|escape:'htmlall':'UTF-8'}"
        data-mpi-url="{$applepay_mpi_url|escape:'htmlall':'UTF-8'}"
        data-3ds-success-url="{$applepay_3ds_success_url|escape:'htmlall':'UTF-8'}"
        data-3ds-failure-url="{$applepay_3ds_failure_url|escape:'htmlall':'UTF-8'}"
        data-direct-script-url="{$applepay_direct_script_url|escape:'htmlall':'UTF-8'}"
        data-cart-id="{$applepay_cart_id|escape:'htmlall':'UTF-8'}"
        data-order-total="{$applepay_order_total|escape:'htmlall':'UTF-8'}"
        data-currency-code="{$applepay_currency_code|escape:'htmlall':'UTF-8'}"
        data-currency-numeric="{$applepay_currency_numeric|escape:'htmlall':'UTF-8'}"
        data-3ds-ui-mode="{$applepay_3ds_ui_mode|escape:'htmlall':'UTF-8'}"
        style="display:none;">
    </div>

    <script src="{$applepay_checkout_js_url|escape:'htmlall':'UTF-8'}"></script>
    <script>
        (function () {
            setTimeout(function () {
                if (window.CardlinkApplePayCheckoutLoaded) {
                    return;
                }

                var script = document.createElement('script');
                script.src = '{$applepay_checkout_js_url|escape:'javascript':'UTF-8'}';
                script.async = true;
                document.head.appendChild(script);
            }, 50);
        })();
    </script>

    {if $applepay_initial_error}
        <script>
            (function () {
                var message = '{$applepay_initial_error|escape:'javascript':'UTF-8'}';
                if (!message) {
                    return;
                }

                var renderGlobalError = function () {
                    var paymentStep = document.querySelector('#checkout-payment-step .content') || document.querySelector('#checkout-payment-step');
                    if (!paymentStep) {
                        return;
                    }

                    var existing = document.getElementById('cardlink-applepay-global-error');
                    if (existing) {
                        existing.textContent = message;
                        existing.style.display = 'block';
                        return;
                    }

                    var alert = document.createElement('div');
                    alert.id = 'cardlink-applepay-global-error';
                    alert.className = 'alert alert-danger';
                    alert.style.marginBottom = '15px';
                    alert.textContent = message;

                    paymentStep.insertBefore(alert, paymentStep.firstChild);
                };

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', renderGlobalError);
                } else {
                    renderGlobalError();
                }
            })();
        </script>
    {/if}
</div>
