{*
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Google Pay payment option form displayed in the checkout step.
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *}
<div id="cardlink-googlepay-container">
    <link rel="stylesheet" href="{$front_css_url|escape:'htmlall':'UTF-8'}" />

    <form method="post" action="{$action}" id="cardlink-googlepay-form">
        <input type="hidden" name="payment_method" value="googlepay" />

        <img src="{$googlepay_logo_url}" id="cardlink_checkout--googlepay-payment-method-logo"
            class="cardlink_checkout--payment-option-logo" style="max-height: 38px; width: auto;" />

        {if ($googlepay_description != '')}
            <div class="cardlink_checkout--googlepay-payment-method-description">
                <p>{$googlepay_description}</p>
            </div>
        {/if}

        {* Google Pay button will be rendered here by the Google Pay Direct script *}
        <div id="gpcontainer" style="min-height: 48px; margin: 15px 0; display: none;"></div>

        <div id="cardlink-googlepay-terms-notice" style="margin: 10px 0; color: #6c757d; font-size: 0.9em;">
            <span>{l s='Please accept the terms and conditions to enable Google Pay.' mod='cardlink_checkout'}</span>
        </div>

        <div id="cardlink-googlepay-loading" style="display: none; text-align: center; padding: 10px;">
            <span>{l s='Initializing Google Pay...' mod='cardlink_checkout'}</span>
        </div>

        <div id="cardlink-googlepay-error" style="{if $googlepay_initial_error}display: block;{else}display: none;{/if} color: #d9534f; padding: 10px;">
            {if $googlepay_initial_error}{$googlepay_initial_error|escape:'htmlall':'UTF-8'}{/if}
        </div>
    </form>

    <div id="cardlink-googlepay-config"
        data-script-info-url="{$googlepay_script_info_url|escape:'htmlall':'UTF-8'}"
        data-wallet-url="{$googlepay_wallet_url|escape:'htmlall':'UTF-8'}"
        data-create-xid-url="{$googlepay_create_xid_url|escape:'htmlall':'UTF-8'}"
        data-sign-data-url="{$googlepay_sign_data_url|escape:'htmlall':'UTF-8'}"
        data-mpi-url="{$googlepay_mpi_url|escape:'htmlall':'UTF-8'}"
        data-3ds-success-url="{$googlepay_3ds_success_url|escape:'htmlall':'UTF-8'}"
        data-3ds-failure-url="{$googlepay_3ds_failure_url|escape:'htmlall':'UTF-8'}"
        data-direct-script-url="{$googlepay_direct_script_url|escape:'htmlall':'UTF-8'}"
        data-cart-id="{$googlepay_cart_id|escape:'htmlall':'UTF-8'}"
        data-order-total="{$googlepay_order_total|escape:'htmlall':'UTF-8'}"
        data-currency-code="{$googlepay_currency_code|escape:'htmlall':'UTF-8'}"
        data-currency-numeric="{$googlepay_currency_numeric|escape:'htmlall':'UTF-8'}"
        data-3ds-ui-mode="{$googlepay_3ds_ui_mode|escape:'htmlall':'UTF-8'}"
        style="display:none;">
    </div>

    <script src="{$googlepay_checkout_js_url|escape:'htmlall':'UTF-8'}"></script>
    <script>
        (function () {
            setTimeout(function () {
                if (window.CardlinkGooglePayCheckoutLoaded) {
                    return;
                }

                var script = document.createElement('script');
                script.src = '{$googlepay_checkout_js_url|escape:'javascript':'UTF-8'}';
                script.async = true;
                document.head.appendChild(script);
            }, 50);
        })();
    </script>

    {if $googlepay_initial_error}
        <script>
            (function () {
                var message = '{$googlepay_initial_error|escape:'javascript':'UTF-8'}';
                if (!message) {
                    return;
                }

                var renderGlobalError = function () {
                    var paymentStep = document.querySelector('#checkout-payment-step .content') || document.querySelector('#checkout-payment-step');
                    if (!paymentStep) {
                        return;
                    }

                    var existing = document.getElementById('cardlink-googlepay-global-error');
                    if (existing) {
                        existing.textContent = message;
                        existing.style.display = 'block';
                        return;
                    }

                    var alert = document.createElement('div');
                    alert.id = 'cardlink-googlepay-global-error';
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
