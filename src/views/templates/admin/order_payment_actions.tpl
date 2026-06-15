{**
 * Cardlink Checkout - Admin Order Payment Actions Template
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 *}

<div class="card cardlink-payment-actions mt-2" id="cardlink-payment-actions"{if !empty($cardlink_hide_native_refund) && $cardlink_hide_native_refund} data-hide-native-refund="1"{/if}>
    <div class="card-header">
        <h3 class="card-header-title">
            <i class="material-icons">payment</i>
            {l s='Cardlink Payment Actions' mod='cardlink_checkout'}
        </h3>
    </div>
    <div class="card-body">
        {if $cardlink_flash_message}
        <div class="alert alert-{if $cardlink_flash_message_type == 'error'}danger{elseif $cardlink_flash_message_type == 'success'}success{else}info{/if} mb-3" role="alert">
            <i></i>
            <div class="alert-text">
                <p>
                    {$cardlink_flash_message|escape:'html':'UTF-8'}
                </p>
            </div>
        </div>
        {/if}
        <div class="row mb-2">
            <div class="col-md-6">
                <strong>{l s='Payment Method:' mod='cardlink_checkout'}</strong>
            </div>
            <div class="col-md-6">
                {$cardlink_pay_method|escape:'html':'UTF-8'}
            </div>
        </div>
        <div class="row mb-2">
            <div class="col-md-6">
                <strong>{l s='Authorized Amount:' mod='cardlink_checkout'}</strong>
            </div>
            <div class="col-md-6">
                <strong>{$cardlink_amount|string_format:"%.2f"} {$cardlink_currency|escape:'html':'UTF-8'}</strong>
            </div>
        </div>
        {if $cardlink_captured_amount > 0}
        <div class="row mb-2">
            <div class="col-md-6">
                <strong>{l s='Captured Amount:' mod='cardlink_checkout'}</strong>
            </div>
            <div class="col-md-6">
                <span class="badge badge-success">{$cardlink_captured_amount|string_format:"%.2f"} {$cardlink_currency|escape:'html':'UTF-8'}</span>
            </div>
        </div>
        {/if}
        {if $cardlink_refunded_amount > 0 && $cardlink_captured_amount > 0}
        <div class="row mb-2">
            <div class="col-md-6">
                <strong>{l s='Refunded Amount:' mod='cardlink_checkout'}</strong>
            </div>
            <div class="col-md-6">
                <span class="badge badge-warning">{$cardlink_refunded_amount|string_format:"%.2f"} {$cardlink_currency|escape:'html':'UTF-8'}</span>
                {if $cardlink_refundable_amount > 0}
                    <small class="text-muted">({l s='Remaining:' mod='cardlink_checkout'} {$cardlink_refundable_amount|string_format:"%.2f"} {$cardlink_currency|escape:'html':'UTF-8'})</small>
                {else}
                    <small class="text-muted">({l s='Fully refunded' mod='cardlink_checkout'})</small>
                {/if}
            </div>
        </div>
        {/if}
        <div class="row mb-2">
            <div class="col-md-6">
                <strong>{l s='Transaction Reference:' mod='cardlink_checkout'}</strong>
            </div>
            <div class="col-md-6">
                <code>{$cardlink_transaction_id|escape:'html':'UTF-8'}</code>
            </div>
        </div>
        <div class="row mb-2">
            <div class="col-md-6">
                <strong>{l s='Payment Reference:' mod='cardlink_checkout'}</strong>
            </div>
            <div class="col-md-6">
                <code>{$cardlink_pay_ref|escape:'html':'UTF-8'}</code>
            </div>
        </div>
        <div class="row mb-2">
            <div class="col-md-6">
                <strong>{l s='Gateway Order ID:' mod='cardlink_checkout'}</strong>
            </div>
            <div class="col-md-6">
                <code>{$cardlink_order_id|escape:'html':'UTF-8'}</code>
            </div>
        </div>

        {if isset($cardlink_is_legacy) && $cardlink_is_legacy}
        <hr class="my-4">
        <div class="alert alert-info d-print-none" role="alert">            
            <i></i>
            <div class="alert-text">
                <p>
                    {l s='This is a legacy order. Transaction details were not recorded. To enable capture/void/refund actions, the customer needs to place a new order.' mod='cardlink_checkout'}
                </p>
            </div>
        </div>
        {else}
        <hr class="my-4">

        <div class="cardlink-payment-buttons d-flex flex-wrap align-items-start gap-3">
            {if $cardlink_can_capture}
            <form method="post" action="{$cardlink_action_url|escape:'html':'UTF-8'}" class="cardlink-action-form d-flex flex-column flex-md-row align-items-start gap-3" id="cardlink-capture-form">
                <input type="hidden" name="cardlink_payment_action" value="1">
                <input type="hidden" name="id_order" value="{$cardlink_id_order|intval}">
                <input type="hidden" name="action" value="capture">
                <div class="form-group mb-0">
                    <div class="input-group">
                        <input
                            type="number"
                            id="cardlink-capture-amount"
                            name="amount"
                            class="form-control"
                            min="0.01"
                            step="0.01"
                            max="{$cardlink_remaining_amount|string_format:"%.2f"}"
                            value="{$cardlink_remaining_amount|string_format:"%.2f"}"
                            required
                        >
                        <span class="input-group-text">{$cardlink_currency|escape:'html':'UTF-8'}</span>
                    </div>
                    <small class="form-text text-muted">
                        {l s='Max:' mod='cardlink_checkout'} {$cardlink_remaining_amount|string_format:"%.2f"} {$cardlink_currency|escape:'html':'UTF-8'}
                        {if $cardlink_captured_amount > 0}
                            ({l s='Already captured:' mod='cardlink_checkout'} {$cardlink_captured_amount|string_format:"%.2f"} {$cardlink_currency|escape:'html':'UTF-8'})
                        {/if}
                    </small>
                </div>
                <div class="d-flex align-items-start ml-1">
                    <button type="submit" class="btn btn-success" onclick="return confirm('{l s='Are you sure you want to capture this payment?' mod='cardlink_checkout' js=1}');">
                        <i class="material-icons">payment</i>
                        {l s='Capture Payment' mod='cardlink_checkout'}
                    </button>
                </div>
            </form>
            {/if}

            {if $cardlink_can_void}
            <form method="post" action="{$cardlink_action_url|escape:'html':'UTF-8'}" class="cardlink-action-form{if $cardlink_captured_amount > 0} d-flex flex-column flex-md-row align-items-start gap-3{/if}" id="cardlink-void-form">
                <input type="hidden" name="cardlink_payment_action" value="1">
                <input type="hidden" name="id_order" value="{$cardlink_id_order|intval}">
                <input type="hidden" name="action" value="void">
                {if $cardlink_captured_amount > 0}
                <div class="form-group mb-0">
                    <div class="input-group">
                        <input
                            type="number"
                            id="cardlink-void-amount"
                            name="amount"
                            class="form-control"
                            min="0.01"
                            step="0.01"
                            max="{$cardlink_captured_amount|string_format:"%.2f"}"
                            value="{$cardlink_captured_amount|string_format:"%.2f"}"
                            required
                        >
                        <span class="input-group-text">{$cardlink_currency|escape:'html':'UTF-8'}</span>
                    </div>
                    <small class="form-text text-muted">
                        {l s='Max:' mod='cardlink_checkout'} {$cardlink_captured_amount|string_format:"%.2f"} {$cardlink_currency|escape:'html':'UTF-8'}
                        ({l s='Captured amount' mod='cardlink_checkout'})
                    </small>
                </div>
                <div class="d-flex align-items-start ml-1">
                    <button type="submit" class="btn btn-danger" onclick="return confirm('{l s='Are you sure you want to void/cancel this payment? This will release the funds back to the customer.' mod='cardlink_checkout' js=1}');">
                        <i class="material-icons">cancel</i>
                        {l s='Void/Cancel Payment' mod='cardlink_checkout'}
                    </button>
                </div>
                {else}
                <input type="hidden" name="amount" value="{$cardlink_amount|floatval}">
                <button type="submit" class="btn btn-danger" onclick="return confirm('{l s='Are you sure you want to void/cancel this payment? This will release the funds back to the customer.' mod='cardlink_checkout' js=1}');">
                    <i class="material-icons">cancel</i>
                    {l s='Void/Cancel Payment' mod='cardlink_checkout'}
                </button>
                {/if}
            </form>
            {/if}

            {if $cardlink_can_refund}
            <form method="post" action="{$cardlink_action_url|escape:'html':'UTF-8'}" class="cardlink-action-form d-flex flex-column flex-md-row align-items-start gap-3" id="cardlink-refund-form">
                <input type="hidden" name="cardlink_payment_action" value="1">
                <input type="hidden" name="id_order" value="{$cardlink_id_order|intval}">
                <input type="hidden" name="action" value="refund">
                <div class="form-group mb-0">
                    <div class="input-group">
                        <input
                            type="number"
                            id="cardlink-refund-amount"
                            name="amount"
                            class="form-control"
                            min="0.01"
                            step="0.01"
                            max="{$cardlink_refundable_amount|string_format:"%.2f"}"
                            value="{$cardlink_refundable_amount|string_format:"%.2f"}"
                            required
                        >
                        <span class="input-group-text">{$cardlink_currency|escape:'html':'UTF-8'}</span>
                    </div>
                    <small class="form-text text-muted">
                        {l s='Max:' mod='cardlink_checkout'} {$cardlink_refundable_amount|string_format:"%.2f"} {$cardlink_currency|escape:'html':'UTF-8'}
                    </small>
                </div>
                <div class="d-flex align-items-start ml-1">
                    <button type="submit" class="btn btn-warning" onclick="return confirm('{l s='Are you sure you want to refund this amount?' mod='cardlink_checkout' js=1}');">
                        <i class="material-icons">undo</i>
                        {l s='Refund Payment' mod='cardlink_checkout'}
                    </button>
                </div>
            </form>
            {/if}

            {if !$cardlink_can_capture && !$cardlink_can_void && !$cardlink_can_refund}
            <div class="alert alert-info mb-0 d-print-none" role="alert">
                <i></i>
                <div class="alert-text">
                    <p>
                        {l s='No payment actions available for this order status.' mod='cardlink_checkout'}
                    </p>
                </div>
            </div>
            {/if}
        </div>
        {/if}
    </div>
</div>
