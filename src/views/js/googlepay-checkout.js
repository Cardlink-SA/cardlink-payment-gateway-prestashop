(function () {
    'use strict';

    window.CardlinkGooglePayCheckoutLoaded = true;

    var gpayConfig = null;

    function tryInitConfig() {
        if (gpayConfig) {
            return true;
        }

        var cfg = document.getElementById('cardlink-googlepay-config');
        if (!cfg) {
            return false;
        }

        gpayConfig = {
            scriptInfoUrl: cfg.getAttribute('data-script-info-url') || '',
            walletUrl: cfg.getAttribute('data-wallet-url') || '',
            createXidUrl: cfg.getAttribute('data-create-xid-url') || '',
            signDataUrl: cfg.getAttribute('data-sign-data-url') || '',
            mpiUrl: cfg.getAttribute('data-mpi-url') || '',
            threeDsSuccessUrl: cfg.getAttribute('data-3ds-success-url') || '',
            threeDsFailureUrl: cfg.getAttribute('data-3ds-failure-url') || '',
            directScriptUrl: cfg.getAttribute('data-direct-script-url') || '',
            cartId: cfg.getAttribute('data-cart-id') || '',
            orderTotal: cfg.getAttribute('data-order-total') || '',
            currencyCode: cfg.getAttribute('data-currency-code') || 'EUR',
            currencyNumeric: cfg.getAttribute('data-currency-numeric') || '978',
            threeDsUiMode: cfg.getAttribute('data-3ds-ui-mode') || 'redirect'
        };

        return true;
    }

    var scriptLoading = false;
    var scriptLoaded = false;
    var googlePayInitialized = false;
    var googlePayInitPending = false;
    var googleApiLoading = false;
    var scriptInitData = null;
    var storedMid = '';
    var gpayIsActiveMethod = false;
    var gpayRadioId = null;
    var gpayFormGuardAttached = false;
    var gpayCheckoutGuardAttached = false;
    var pendingRedirectUrl = '';
    var saleInterceptorInstalled = false;
    var saleResponseHandled = false;

    function ensureGooglePayApiLoaded() {
        return new Promise(function (resolve, reject) {
            if (window.google && window.google.payments && window.google.payments.api) {
                resolve();
                return;
            }

            var existingScript = document.querySelector('script[src*="pay.google.com/gp/p/js/pay.js"]');

            if (existingScript && existingScript.getAttribute('data-loaded') === 'true') {
                resolve();
                return;
            }

            if (googleApiLoading && existingScript) {
                existingScript.addEventListener('load', function () {
                    resolve();
                }, { once: true });
                existingScript.addEventListener('error', function () {
                    reject(new Error('Failed to load Google Pay API script.'));
                }, { once: true });
                return;
            }

            var script = existingScript || document.createElement('script');
            if (!existingScript) {
                script.type = 'text/javascript';
                script.async = true;
                script.src = 'https://pay.google.com/gp/p/js/pay.js';
            }

            googleApiLoading = true;

            var onLoad = function () {
                googleApiLoading = false;
                script.setAttribute('data-loaded', 'true');
                resolve();
            };

            var onError = function () {
                googleApiLoading = false;
                reject(new Error('Failed to load Google Pay API script.'));
            };

            script.addEventListener('load', onLoad, { once: true });
            script.addEventListener('error', onError, { once: true });

            if (!existingScript) {
                document.head.appendChild(script);
            }
        });
    }

    function getPaymentConfirmation() {
        return document.getElementById('payment-confirmation');
    }

    function setNativePlaceOrderState(hidden) {
        var confirmSection = getPaymentConfirmation();
        if (!confirmSection) return;

        var submitNodes = confirmSection.querySelectorAll('button[type="submit"], input[type="submit"]');

        Array.prototype.forEach.call(submitNodes, function (node) {
            if (hidden) {
                if (!node.hasAttribute('data-gpay-prev-disabled')) {
                    node.setAttribute('data-gpay-prev-disabled', node.disabled ? '1' : '0');
                }
                node.disabled = true;
                node.style.setProperty('display', 'none', 'important');
            } else {
                var prevDisabled = node.getAttribute('data-gpay-prev-disabled');
                if (prevDisabled !== null) {
                    node.disabled = prevDisabled === '1';
                    node.removeAttribute('data-gpay-prev-disabled');
                }
                node.style.removeProperty('display');
            }
        });
    }

    function getTermsCheckbox() {
        return document.querySelector('#conditions-to-approve input[type="checkbox"]')
            || document.querySelector('input[name="conditions_to_approve[terms-and-conditions]"]')
            || document.querySelector('.condition-label input[type="checkbox"]');
    }

    function areTermsAccepted() {
        var cb = getTermsCheckbox();
        return !cb || cb.checked;
    }

    function movGpayBack() {
        var gpContainer = document.getElementById('gpcontainer');

        setNativePlaceOrderState(false);

        if (gpContainer) {
            gpContainer.style.display = 'none';
            gpContainer.style.pointerEvents = '';
            gpContainer.style.opacity = '';
        }
    }

    function updateGpayButtonVisibility() {
        var gpContainer = document.getElementById('gpcontainer');
        var termsNotice = document.getElementById('cardlink-googlepay-terms-notice');
        var accepted = areTermsAccepted();

        if (accepted) {
            setNativePlaceOrderState(true);
            if (gpContainer) {
                gpContainer.style.display = '';
                gpContainer.style.pointerEvents = '';
                gpContainer.style.opacity = '';
            }
            if (termsNotice) termsNotice.style.display = 'none';
        } else {
            movGpayBack();
            setNativePlaceOrderState(true);
            if (gpContainer) {
                gpContainer.style.display = 'none';
                gpContainer.style.pointerEvents = 'none';
                gpContainer.style.opacity = '0.5';
            }
            if (termsNotice) termsNotice.style.display = '';
        }
    }

    function getAdditionalInfoForRadio(radio) {
        if (!radio) return null;

        var additionalInfo = null;

        var targetSelector = radio.getAttribute('data-target') || radio.getAttribute('data-toggle-target');
        if (targetSelector) {
            additionalInfo = document.querySelector(targetSelector);
        }

        var controlsId = radio.getAttribute('aria-controls');
        if (!additionalInfo && controlsId) {
            additionalInfo = document.getElementById(controlsId);
        }

        if (!additionalInfo && radio.id) {
            additionalInfo = document.getElementById(radio.id + '-additional-information');
        }

        if (!additionalInfo) {
            var optionNode = radio.closest('.payment-option');
            if (optionNode) {
                var sibling = optionNode.nextElementSibling;
                while (sibling) {
                    var classList = sibling.classList;
                    var hasFormClass = classList && (
                        classList.contains('additional-information')
                        || classList.contains('js-payment-option-form')
                        || classList.contains('payment-option-form')
                    );
                    var looksLikeFormById = !!(sibling.id && sibling.id.indexOf('-form') !== -1);

                    if (hasFormClass || looksLikeFormById) {
                        additionalInfo = sibling;
                        break;
                    }

                    sibling = sibling.nextElementSibling;
                }
            }
        }

        return additionalInfo;
    }

    function getGooglePayRadio() {
        if (gpayRadioId) {
            var storedRadio = document.getElementById(gpayRadioId);
            if (storedRadio && storedRadio.matches && storedRadio.matches('input[type="radio"]')) {
                return storedRadio;
            }
        }

        var cfg = document.getElementById('cardlink-googlepay-config');
        if (!cfg) return null;

        var radios = document.querySelectorAll('input[type="radio"]');
        for (var i = 0; i < radios.length; i++) {
            var radio = radios[i];
            var additionalInfo = getAdditionalInfoForRadio(radio);
            if (additionalInfo && additionalInfo.contains(cfg)) {
                if (radio.id) {
                    gpayRadioId = radio.id;
                }
                return radio;
            }
        }

        return null;
    }

    function isGooglePaySelected() {
        var radio = getGooglePayRadio();
        if (radio) {
            if (radio.checked) {
                return true;
            }
        }

        var gpayContainer = document.getElementById('cardlink-googlepay-container');
        if (!gpayContainer) {
            return false;
        }

        var style = window.getComputedStyle(gpayContainer);
        return gpayContainer.offsetParent !== null && style.display !== 'none' && style.visibility !== 'hidden';
    }

    function onPaymentMethodChange() {
        var selected = isGooglePaySelected();
        var accepted = areTermsAccepted();

        if (selected && !gpayIsActiveMethod) {
            gpayIsActiveMethod = true;
            updateGpayButtonVisibility();
            if (accepted) {
                initGooglePay();
            }
        } else if (selected && gpayIsActiveMethod) {
            if (accepted) {
                enforceConfirmationHidden();
            }
            if (accepted && !scriptLoaded && !scriptLoading) {
                initGooglePay();
            } else if (accepted && scriptLoaded && !googlePayInitialized && scriptInitData) {
                initializeGooglePayButton(scriptInitData);
            }
        } else if (!selected && gpayIsActiveMethod) {
            gpayIsActiveMethod = false;
            movGpayBack();
        }
    }

    function enforceConfirmationHidden() {
        setNativePlaceOrderState(true);
    }

    function attachTermsListener() {
        var cb = getTermsCheckbox();
        if (cb && !cb._gpayListenerAttached) {
            cb._gpayListenerAttached = true;
            cb.addEventListener('change', function () {
                if (gpayIsActiveMethod) {
                    updateGpayButtonVisibility();
                    if (cb.checked && !scriptLoaded && !scriptLoading) {
                        initGooglePay();
                    } else if (cb.checked && scriptLoaded && !googlePayInitialized && scriptInitData) {
                        initializeGooglePayButton(scriptInitData);
                    }
                }
            });
        }
    }

    function attachGooglePayFormGuard() {
        if (gpayFormGuardAttached) {
            return;
        }

        var form = document.getElementById('cardlink-googlepay-form');
        if (!form) {
            return;
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        });

        form.addEventListener('click', function (e) {
            if (!gpayIsActiveMethod) {
                return;
            }

            var target = e.target;
            if (!target || !target.closest) {
                return;
            }

            var submitElement = target.closest('button[type="submit"], input[type="submit"]');
            if (submitElement) {
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);

        gpayFormGuardAttached = true;
    }

    function attachCheckoutGuards() {
        if (gpayCheckoutGuardAttached) {
            return;
        }

        document.addEventListener('submit', function (e) {
            if (!gpayIsActiveMethod) {
                return;
            }

            var form = e.target;
            if (!form) {
                return;
            }

            if (form.id === 'VEReqForm1') {
                return;
            }

            if (form.closest && form.closest('#gpcontainer')) {
                return;
            }

            e.preventDefault();
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === 'function') {
                e.stopImmediatePropagation();
            }
        }, true);

        document.addEventListener('click', function (e) {
            if (!gpayIsActiveMethod) {
                return;
            }

            var target = e.target;
            if (!target || !target.closest) {
                return;
            }

            if (target.closest('#gpcontainer')) {
                return;
            }

            var nativeSubmitTrigger = target.closest('#payment-confirmation button[type="submit"], #payment-confirmation input[type="submit"]');
            if (!nativeSubmitTrigger) {
                return;
            }

            e.preventDefault();
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === 'function') {
                e.stopImmediatePropagation();
            }
        }, true);

        gpayCheckoutGuardAttached = true;
    }


    function initGooglePay() {
        if (!tryInitConfig()) {
            return;
        }

        if (scriptLoading || scriptLoaded) {
            return;
        }
        scriptLoading = true;

        var loadingEl = document.getElementById('cardlink-googlepay-loading');
        if (loadingEl) loadingEl.style.display = 'block';

        fetch(gpayConfig.scriptInfoUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mid: '' })
        })
            .then(function (resp) { return resp.json(); })
            .then(function (data) {
                if (!data.success) {
                    showError('Failed to initialize Google Pay.');
                    scriptLoading = false;
                    return;
                }

                var container = document.getElementById('gpcontainer');
                if (container) container.innerHTML = '';

                var scriptUrl = gpayConfig.directScriptUrl + data.queryString;
                var script = document.createElement('script');
                script.type = 'text/javascript';
                script.src = scriptUrl;

                script.onload = function () {
                    scriptLoaded = true;
                    scriptLoading = false;
                    scriptInitData = data;
                    storedMid = data.mid || '';
                    if (loadingEl) loadingEl.style.display = 'none';
                    waitForContainerAndInit(data);
                    if (gpayIsActiveMethod) {
                        updateGpayButtonVisibility();
                    }
                };

                script.onerror = function () {
                    scriptLoading = false;
                    if (loadingEl) loadingEl.style.display = 'none';
                    showError('Failed to load Google Pay script.');
                };

                document.body.appendChild(script);
            })
            .catch(function (err) {
                scriptLoading = false;
                if (loadingEl) loadingEl.style.display = 'none';
                showError('Failed to initialize Google Pay: ' + err.message);
            });
    }

    function waitForContainerAndInit(initData) {
        var container = document.getElementById('gpcontainer');
        if (container) {
            initializeGooglePayButton(initData);
            return;
        }

        var attempts = 0;
        var maxAttempts = 20;
        var poll = setInterval(function () {
            attempts++;
            container = document.getElementById('gpcontainer');
            if (container) {
                clearInterval(poll);
                initializeGooglePayButton(initData);
            } else if (attempts >= maxAttempts) {
                clearInterval(poll);
                showError('Google Pay container was not found in checkout.');
            }
        }, 250);
    }

    function initializeGooglePayButton(initData) {
        if (typeof GooglePay === 'undefined') {
            showError('Google Pay is not available.');
            return;
        }

        if (googlePayInitialized || googlePayInitPending) {
            return;
        }

        googlePayInitPending = true;

        installSaleResponseInterceptor();

        ensureGooglePayApiLoaded()
            .then(function () {
                try {
                    var orderTotal = parseFloat(gpayConfig.orderTotal).toFixed(2);
                    var currencyCode = gpayConfig.currencyCode || 'EUR';
                    var xmlVersion = (initData.vposVersion || '2') + '.1';
                    var orderId = generateOrderId();

                    if (typeof window.paymentResponse === 'undefined') {
                        window.paymentResponse = {
                            complete: function (status) {
                                if (status === 'success') {
                                    handleSuccess();
                                } else {
                                    handleFailure('Google Pay payment was not completed.');
                                }
                            }
                        };
                    }

                    var googlePayInstance;
                    try {
                        googlePay = new GooglePay();
                        googlePayInstance = googlePay;
                    } catch (assignErr) {
                        googlePayInstance = new GooglePay();
                    }

                    googlePayInstance.initGooglePay(
                        xmlVersion,
                        gpayConfig.walletUrl.replace(/\/+$/, ''),
                        orderId,
                        orderTotal,
                        currencyCode,
                        initData.mid,
                        threeDSHandler
                    );

                    googlePayInstance.initGooglePayClient().then(function () {
                        googlePayInitialized = true;
                        googlePayInitPending = false;
                    }).catch(function (error) {
                        googlePayInitPending = false;
                        showError('Error initializing Google Pay client: ' + error.message);
                    });
                } catch (error) {
                    googlePayInitPending = false;
                    showError('Error initializing Google Pay: ' + error.message);
                }
            })
            .catch(function (error) {
                googlePayInitPending = false;
                showError(error.message || 'Google Pay API could not be loaded.');
            });
    }

    function installSaleResponseInterceptor() {
        if (saleInterceptorInstalled || !gpayConfig) {
            return;
        }

        saleInterceptorInstalled = true;
        var walletUrl = (gpayConfig.walletUrl || '').replace(/\/+$/, '');

        function isSaleUrl(url) {
            return typeof url === 'string'
                && url.indexOf(walletUrl) !== -1
                && url.indexOf('sale') !== -1;
        }

        var origOpen = XMLHttpRequest.prototype.open;
        var origSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function (method, url) {
            if (method && method.toUpperCase() === 'POST' && isSaleUrl(url)) {
                this._isGooglePaySale = true;
            }
            return origOpen.apply(this, arguments);
        };

        XMLHttpRequest.prototype.send = function () {
            if (this._isGooglePaySale) {
                var xhr = this;
                xhr.addEventListener('load', function () {
                    onSaleResponse(xhr.responseText);
                });
            }
            return origSend.apply(this, arguments);
        };

        if (typeof window.fetch === 'function') {
            var origFetch = window.fetch;
            window.fetch = function (input, init) {
                var fetchUrl = typeof input === 'string' ? input : (input && input.url ? input.url : '');
                var fetchMethod = (init && init.method) ? init.method.toUpperCase() : 'GET';
                var isGooglePaySale = fetchMethod === 'POST' && isSaleUrl(fetchUrl);

                var promise = origFetch.apply(this, arguments);
                if (isGooglePaySale) {
                    promise.then(function (response) {
                        response.clone().text().then(function (body) {
                            onSaleResponse(body);
                        });
                    });
                }

                return promise;
            };
        }
    }

    function onSaleResponse(responseText) {
        if (saleResponseHandled) {
            return;
        }

        try {
            var resp = JSON.parse(responseText);

            if (resp.status === 'success') {
                saleResponseHandled = true;
                if (resp.redirectUrl) {
                    pendingRedirectUrl = resp.redirectUrl;
                }
            }
        } catch (e) {
            // ignore
        }
    }

    function threeDSHandler(resp) {
        var orderAmount = resp.orderAmount;
        var txId = resp.txId;
        var cardEncData = resp.cardEncData;
        var trExtId = resp.trExtId;
        var trMpiCounts = resp.trMpiCounts;

        if (!cardEncData) {
            handleFailure('3DS authentication data missing.');
            return;
        }

        showLoading(true);

        fetch(gpayConfig.createXidUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                trId: txId,
                trExtId: trExtId,
                trMpiCounts: trMpiCounts
            })
        })
            .then(function (fetchResp) { return fetchResp.text(); })
            .then(function (xid) {
                if (!xid) {
                    showLoading(false);
                    handleFailure('XID generation failed.');
                    return;
                }

                var description = 'CART ' + gpayConfig.cartId;

                var signPayload = {
                    mpiVersion: '4.0',
                    pan: '',
                    expiry: '',
                    cardEncData: cardEncData,
                    devCat: '0',
                    purchAmount: orderAmount,
                    exponent: '2',
                    description: description,
                    currMpi: gpayConfig.currencyNumeric,
                    merchantID: storedMid,
                    xidb64: xid,
                    okUrl: gpayConfig.threeDsSuccessUrl,
                    failUrl: gpayConfig.threeDsFailureUrl,
                    recurFreq: null,
                    recurEnd: null,
                    installments: null
                };

                fetch(gpayConfig.signDataUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(signPayload)
                })
                    .then(function (signFetchResp) { return signFetchResp.json(); })
                    .then(function (signResp) {
                        if (!signResp.signature || signResp.signature.indexOf('Error') === 0) {
                            showLoading(false);
                            handleFailure('Invalid signature received.');
                            return;
                        }

                        var form = document.createElement('form');
                        form.action = gpayConfig.mpiUrl;
                        form.method = 'POST';
                        form.name = 'VEReqForm';
                        form.id = 'VEReqForm1';

                        var addField = function (name, value) {
                            if (value == null || value === '') return;
                            var input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = name;
                            input.value = value;
                            form.appendChild(input);
                        };

                        var actualMpiVersion = signResp.mpiVersion || signPayload.mpiVersion;
                        var sigFieldName = (actualMpiVersion.charAt(0) === '2') ? 'digest' : 'signature';

                        addField('version', actualMpiVersion);
                        addField('cardEncData', signPayload.cardEncData);
                        addField('deviceCategory', signPayload.devCat);
                        addField('purchAmount', signResp.purchaseAmountFormatted);
                        addField('exponent', signPayload.exponent);
                        addField('description', signPayload.description);
                        addField('currency', signPayload.currMpi);
                        addField('merchantID', signPayload.merchantID);
                        addField('xid', signPayload.xidb64);
                        addField('okUrl', signPayload.okUrl);
                        addField('failUrl', signPayload.failUrl);
                        addField(sigFieldName, signResp.signature);

                        document.body.appendChild(form);
                        submitThreeDsForm(form);
                    })
                    .catch(function (err) {
                        showLoading(false);
                        handleFailure('Failed to sign MPI data: ' + err.message);
                    });
            })
            .catch(function (err) {
                showLoading(false);
                handleFailure('Failed to get XID: ' + err.message);
            });
    }

    function submitThreeDsForm(form) {
        if (!apayOrGpay3dsInModal()) {
            form.submit();
            return;
        }

        var frameName = 'cardlink-googlepay-3ds-frame';
        ensureThreeDsModal(frameName);
        form.setAttribute('target', frameName);
        form.submit();
    }

    function apayOrGpay3dsInModal() {
        return gpayConfig && gpayConfig.threeDsUiMode === 'iframe_modal';
    }

    function ensureThreeDsModal(frameName) {
        var existing = document.getElementById('cardlink-googlepay-3ds-modal');
        if (existing) {
            existing.style.display = 'block';
            return;
        }

        var overlay = document.createElement('div');
        overlay.id = 'cardlink-googlepay-3ds-modal';
        overlay.style.position = 'fixed';
        overlay.style.left = '0';
        overlay.style.top = '0';
        overlay.style.width = '100%';
        overlay.style.height = '100%';
        overlay.style.background = 'rgba(0,0,0,0.65)';
        overlay.style.zIndex = '99999';
        overlay.style.display = 'block';

        var container = document.createElement('div');
        container.style.position = 'absolute';
        container.style.left = '50%';
        container.style.top = '50%';
        container.style.transform = 'translate(-50%, -50%)';
        container.style.width = '90%';
        container.style.maxWidth = '900px';
        container.style.height = '90%';
        container.style.maxHeight = '820px';
        container.style.background = '#fff';
        container.style.borderRadius = '4px';
        container.style.overflow = 'hidden';

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.textContent = '×';
        closeBtn.style.position = 'absolute';
        closeBtn.style.right = '8px';
        closeBtn.style.top = '4px';
        closeBtn.style.zIndex = '2';
        closeBtn.style.background = 'transparent';
        closeBtn.style.border = '0';
        closeBtn.style.fontSize = '28px';
        closeBtn.style.cursor = 'pointer';
        closeBtn.addEventListener('click', function () {
            overlay.style.display = 'none';
        });

        var iframe = document.createElement('iframe');
        iframe.name = frameName;
        iframe.id = frameName;
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        iframe.style.border = '0';

        container.appendChild(closeBtn);
        container.appendChild(iframe);
        overlay.appendChild(container);
        document.body.appendChild(overlay);
    }

    function handleSuccess() {
        if (pendingRedirectUrl) {
            window.location.href = pendingRedirectUrl;
        } else {
            window.location.href = window.location.origin + '/index.php?controller=order-confirmation&id_cart=' + (gpayConfig ? gpayConfig.cartId : '');
        }
    }

    function handleFailure(message) {
        showError(message || 'Google Pay payment failed. Please try again or choose a different payment method.');
    }

    function generateOrderId() {
        var cartId = gpayConfig.cartId;
        var now = new Date();
        var timestamp = '' + now.getHours() + padZero(now.getMinutes()) + padZero(now.getSeconds());
        var charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        var suffix = '';
        for (var i = 0; i < 3; i++) {
            suffix += charset.charAt(Math.floor(Math.random() * charset.length));
        }
        return cartId + 'x' + timestamp + 'x' + suffix;
    }

    function padZero(n) {
        return n < 10 ? '0' + n : '' + n;
    }

    function showLoading(show) {
        var loadingEl = document.getElementById('cardlink-googlepay-loading');
        if (loadingEl) {
            loadingEl.style.display = show ? 'block' : 'none';
            loadingEl.innerHTML = '<span>' + (show ? 'Processing payment...' : '') + '</span>';
        }
    }

    function showError(message) {
        var errorEl = document.getElementById('cardlink-googlepay-error');
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.style.display = 'block';
        }
    }

    function checkAndInit() {
        if (!tryInitConfig()) {
            return;
        }

        attachGooglePayFormGuard();
        attachCheckoutGuards();
        attachTermsListener();
        onPaymentMethodChange();
    }

    setInterval(function () {
        checkAndInit();
    }, 500);

    document.addEventListener('click', function () {
        setTimeout(checkAndInit, 150);
    });

    document.addEventListener('change', function (e) {
        var target = e.target;
        if (target && target.matches && target.matches('input[type="radio"]')) {
            setTimeout(checkAndInit, 50);
        }
    });

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        setTimeout(checkAndInit, 500);
    } else {
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(checkAndInit, 500);
        });
    }
})();
