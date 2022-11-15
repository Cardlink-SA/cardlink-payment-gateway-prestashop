{*
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Form to be displayed in the payment step
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *}
<div class="additional-information">
    <script>
        const deleteStoredTokenUrl = '{$deleteStoredTokenUrl}';
        const deleteStoredTokenConfirmMessage = '{l s='Are you sure you want to delete the stored card?' mod='cardlink_checkout'}';

        {literal}
            function checkFirstStoredToken() {
                const radio = document.querySelectorAll('.stored-token-option-radio');
                let checked = false;

                for (let i = 0; i < radio.length; i++) {
                    if (radio[i].checked) {
                        checked = true;
                        break;
                    }
                }
                if (!checked) {
                    radio[0].checked = true;
                }
            }

            function showStoreTokenOption() {
                document.getElementById('cardlink_checkout--tokenize-container').style.cssText = 'display: block;'
            }

            function hideStoreTokenOption() {
                document.getElementById('cardlink_checkout--tokenize-container').style.cssText = 'display: none;'
            }

            function checkStoredTokenSelection(selectObject) {
                const value = selectObject.value;

                if (value === '0') {
                    showStoreTokenOption();
                } else {
                    hideStoreTokenOption();
                }
            }

            function deleteStoredToken(storedTokenId) {
                if (confirm(deleteStoredTokenConfirmMessage)) {
                    // Set up our HTTP request
                    var xhr = new XMLHttpRequest();

                    // Setup our listener to process request state changes
                    xhr.onreadystatechange = function() {

                        // Only run if the request is complete
                        if (xhr.readyState !== 4) return;

                        // Process our return data on success
                        if (xhr.status >= 200 && xhr.status < 300) {
                            document.getElementById('stored_token_' + storedTokenId + '_container').remove();
                            const newCardOption = document.getElementById('stored_token_0');
                            newCardOption.checked = true;
                            checkStoredTokenSelection(newCardOption);
                        }
                    };

                    // Create and send a GET request
                    // The first argument is the request type (GET, POST, PUT, DELETE, etc.)
                    // The second argument is the endpoint URL
                    xhr.open('DELETE', `${deleteStoredTokenUrl}&token_id=${storedTokenId}`, true);
                    xhr.send();
                }
            }
        {/literal}
    </script>

    <form method="post" action="{$action}">

        <img src="{$cardlink_logo_url}" id="cardlink_checkout--payment-method-logo" />

        {if ($description != '')}
            <div class="cardlink_checkout--payment-method-description">
                <p>{$description}</p>
            </div>
        {/if}

        {if ($acceptsInstallments && $maxInstallments > 1)}
            <div class="form-group row ">
                <label class="col-md-3 form-control-label required" for="field-installments">
                    {l s='Installments' mod='cardlink_checkout'}
                </label>
                <div class="col-md-6">
                    <select id="installments" name="installments" class="form-control form-control-select"
                        title="{l s='Installments' mod='cardlink_checkout'}" required>
                        {for $i=1 to $maxInstallments }
                            <option value="{$i}">
                                {if ($i==1)}
                                    {l s='No Installments' mod='cardlink_checkout'}
                                {else}
                                    {$i}
                                {/if}
                            </option>
                        {/for}
                    </select>
                    <span class="form-control-comment">
                    </span>
                </div>

                <div class="col-md-3 form-control-comment">
                </div>
            </div>
        {/if}

        {if ($allowsTokenization && $isLoggedIn)}
            {if (count($storedTokens) > 0)}
                <div class="form-group row">
                    <label class="col-md-3 form-control-label required">
                        {l s='Stored Cards' mod='cardlink_checkout'}
                    </label>
                    <div class="col-md-9">
                        <div id="cardlink-checkout-stored-token-options">
                            {foreach from=$storedTokens key="index" item="storedToken"}
                                <div class="row stored-token-option" id="stored_token_{$storedToken['id']}_container">
                                    <div class="col-xs-1">
                                        <span class="custom-radio float-xs-left">
                                            <input type="radio" name="stored_token" id="stored_token_{$storedToken['id']}"
                                                class="stored-token-option-radio" value="{$storedToken['id']}"
                                                onchange="checkStoredTokenSelection(this)" required />
                                            <span></span>
                                        </span>
                                    </div>
                                    <div class="col-xs-3">
                                        <label for="stored_token_{$storedToken['id']}">
                                            {if ($storedToken['image_url'])}
                                                <img src="{$storedToken['image_url']}" title="{$storedToken['type_label']}" />
                                            {/if}
                                        </label>
                                    </div>
                                    <div class="col-xs-7">
                                        <label for="stored_token_{$storedToken['id']}">
                                            <div class="h6">xxxx-xxxx-xxxx-{$storedToken['last_digits']}</div>
                                            <div class="">{$storedToken['expires']} - <a href="#"
                                                    onclick="deleteStoredToken({$storedToken['id']}); return false;"
                                                    title="{l s='Remove Stored Card' mod='cardlink_checkout'}">{l s='Remove' mod='cardlink_checkout'}</a>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                                <div class="clearfix"></div>
                            {/foreach}

                            <!-- New Card -->
                            <div class="row stored-token-option">
                                <div class="col-xs-1">
                                    <span class="custom-radio float-xs-left">
                                        <input type="radio" name="stored_token" id="stored_token_0" value="0"
                                            class="stored-token-option-radio" onchange="checkStoredTokenSelection(this)"
                                            required />
                                        <span></span>
                                    </span>
                                </div>
                                <div class="col-xs-9 stored-token-option-0">
                                    <label for="stored_token_0" class="row">
                                        <div class="col-12">
                                            {l s='New Card' mod='cardlink_checkout'}
                                        </div>
                                    </label>
                                </div>
                            </div>
                            <div class="clearfix"></div>
                        </div>

                    </div>
                </div>
            {/if}

            <div id="cardlink_checkout--tokenize-container" class="form-group row">
                <div class="col-xs-12">
                    <ul>
                        <li>
                            <div class="float-xs-left">
                                <span class="custom-checkbox">
                                    <input type="checkbox" name="tokenize_card" id="cardlink_checkout_tokenize_card"
                                        value="1">
                                    <span><i class="material-icons rtl-no-flip checkbox-checked">&#xE5CA;</i></span>
                                </span>
                            </div>
                            <div class="condition-label">
                                <label for="cardlink_checkout_tokenize_card">
                                    {l s='Securely store card' mod='cardlink_checkout'}
                                </label>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

            {if (count($storedTokens) > 0)}
                {literal}
                    <script>
                        window.checkFirstStoredToken();
                        window.hideStoreTokenOption();
                    </script>
                {/literal}
            {/if}
        {/if}

    </form>
</div>