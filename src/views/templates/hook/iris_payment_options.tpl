{*
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Form to be displayed in the payment step
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *}
<div class="additional-information">

    <form method="post" action="{$action}">
        <input type="hidden" name="payment_method" value="IRIS" />

        <img src="{$iris_logo_url}" id="cardlink_checkout--iris-payment-method-logo"
            class="cardlink_checkout--payment-option-logo" />

        {if ($iris_description != '')}
            <div class="cardlink_checkout--iris-payment-method-description">
                <p>{$iris_description}</p>
            </div>
        {/if}
    </form>

</div>