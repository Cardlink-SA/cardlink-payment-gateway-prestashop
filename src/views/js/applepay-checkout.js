(function () {
    'use strict';

    window.CardlinkApplePayCheckoutLoaded = true;

    var apayConfig = null;
    var scriptLoading = false;
    var scriptLoaded = false;
    var applePayInitialized = false;
    var scriptInitData = null;
    var apayIsActiveMethod = false;
    var apayRadioId = null;
    var apayFormGuardAttached = false;
    var apayCheckoutGuardAttached = false;
    var saleInterceptorInstalled = false;
    var saleResponseHandled = false;
    var applePayInitRetryCount = 0;
    var windowPropsBeforeScript = null;
    var windowPropsAfterScript = null;
    var applePayUnsupportedMessage = '';
    var pendingRedirectUrl = '';

    function tryInitConfig() {
        if (apayConfig) {
            return true;
        }

        var cfg = document.getElementById('cardlink-applepay-config');
        if (!cfg) {
            return false;
        }

        apayConfig = {
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

    function getPaymentConfirmation() {
        return document.getElementById('payment-confirmation');
    }

    function setNativePlaceOrderState(hidden) {
        var confirmSection = getPaymentConfirmation();
        if (!confirmSection) return;

        var submitNodes = confirmSection.querySelectorAll('button[type="submit"], input[type="submit"]');

        Array.prototype.forEach.call(submitNodes, function (node) {
            if (hidden) {
                if (!node.hasAttribute('data-apay-prev-disabled')) {
                    node.setAttribute('data-apay-prev-disabled', node.disabled ? '1' : '0');
                }
                node.disabled = true;
                node.style.setProperty('display', 'none', 'important');
            } else {
                var prevDisabled = node.getAttribute('data-apay-prev-disabled');
                if (prevDisabled !== null) {
                    node.disabled = prevDisabled === '1';
                    node.removeAttribute('data-apay-prev-disabled');
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

    function getApplePayRadio() {
        if (apayRadioId) {
            var storedRadio = document.getElementById(apayRadioId);
            if (storedRadio && storedRadio.matches && storedRadio.matches('input[type="radio"]')) {
                return storedRadio;
            }
        }

        var cfg = document.getElementById('cardlink-applepay-config');
        if (!cfg) return null;

        var radios = document.querySelectorAll('input[type="radio"]');
        for (var i = 0; i < radios.length; i++) {
            var radio = radios[i];
            var additionalInfo = getAdditionalInfoForRadio(radio);
            if (additionalInfo && additionalInfo.contains(cfg)) {
                if (radio.id) {
                    apayRadioId = radio.id;
                }
                return radio;
            }
        }

        return null;
    }

    function isApplePaySelected() {
        var radio = getApplePayRadio();
        if (radio && radio.checked) {
            return true;
        }

        var apayContainer = document.getElementById('cardlink-applepay-container');
        if (!apayContainer) {
            return false;
        }

        var style = window.getComputedStyle(apayContainer);
        return apayContainer.offsetParent !== null && style.display !== 'none' && style.visibility !== 'hidden';
    }

    function movApplePayBack() {
        var apContainer = document.getElementById('applePayDiv');
        setNativePlaceOrderState(false);

        if (apContainer) {
            apContainer.style.display = 'none';
            apContainer.style.pointerEvents = '';
            apContainer.style.opacity = '';
        }
    }

    function updateApplePayButtonVisibility() {
        var apContainer = document.getElementById('applePayDiv');
        var termsNotice = document.getElementById('cardlink-applepay-terms-notice');
        var accepted = areTermsAccepted();

        if (accepted) {
            setNativePlaceOrderState(true);
            if (apContainer) {
                apContainer.style.display = '';
                apContainer.style.pointerEvents = '';
                apContainer.style.opacity = '';
            }
            if (termsNotice) termsNotice.style.display = 'none';
        } else {
            setNativePlaceOrderState(true);
            if (apContainer) {
                apContainer.style.display = 'none';
                apContainer.style.pointerEvents = 'none';
                apContainer.style.opacity = '0.5';
            }
            if (termsNotice) termsNotice.style.display = '';
        }
    }

    function onPaymentMethodChange() {
        var selected = isApplePaySelected();
        var accepted = areTermsAccepted();

        if (selected) {
            var supportIssue = getApplePayEnvironmentIssue();
            if (supportIssue) {
                applePayUnsupportedMessage = supportIssue;
                apayIsActiveMethod = false;
                setNativePlaceOrderState(false);
                showError(supportIssue);
                movApplePayBack();
                return;
            }

            applePayUnsupportedMessage = '';
            clearError();
        }

        if (selected && !apayIsActiveMethod) {
            apayIsActiveMethod = true;
            updateApplePayButtonVisibility();
            if (accepted) {
                initApplePayFlow();
            }
        } else if (selected && apayIsActiveMethod) {
            setNativePlaceOrderState(true);
            if (accepted && !scriptLoaded && !scriptLoading) {
                initApplePayFlow();
            }
        } else if (!selected && apayIsActiveMethod) {
            apayIsActiveMethod = false;
            movApplePayBack();
        }
    }

    function attachTermsListener() {
        var cb = getTermsCheckbox();
        if (cb && !cb._apayListenerAttached) {
            cb._apayListenerAttached = true;
            cb.addEventListener('change', function () {
                if (apayIsActiveMethod) {
                    updateApplePayButtonVisibility();
                    if (cb.checked && !scriptLoaded && !scriptLoading) {
                        initApplePayFlow();
                    }
                }
            });
        }
    }

    function attachApplePayFormGuard() {
        if (apayFormGuardAttached) {
            return;
        }

        var form = document.getElementById('cardlink-applepay-form');
        if (!form) {
            return;
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        });

        apayFormGuardAttached = true;
    }

    function attachCheckoutGuards() {
        if (apayCheckoutGuardAttached) {
            return;
        }

        document.addEventListener('submit', function (e) {
            if (!apayIsActiveMethod) {
                return;
            }

            var form = e.target;
            if (!form) {
                return;
            }

            if (form.id === 'VEReqForm1') {
                return;
            }

            if (form.closest && form.closest('#applePayDiv')) {
                return;
            }

            e.preventDefault();
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === 'function') {
                e.stopImmediatePropagation();
            }
        }, true);

        apayCheckoutGuardAttached = true;
    }

    function initApplePayFlow() {
        if (!tryInitConfig()) {
            return;
        }

        if (applePayUnsupportedMessage) {
            showError(applePayUnsupportedMessage);
            return;
        }

        var supportIssue = getApplePayEnvironmentIssue();
        if (supportIssue) {
            applePayUnsupportedMessage = supportIssue;
            showError(supportIssue);
            return;
        }

        if (scriptLoading || scriptLoaded) {
            return;
        }

        scriptLoading = true;
        windowPropsBeforeScript = getWindowPropertyNamesSnapshot();

        var loadingEl = document.getElementById('cardlink-applepay-loading');
        if (loadingEl) loadingEl.style.display = 'block';

        fetch(apayConfig.scriptInfoUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mid: '' })
        })
            .then(function (resp) { return resp.json(); })
            .then(function (data) {
                if (!data.success) {
                    showError('Failed to initialize Apple Pay.');
                    scriptLoading = false;
                    return;
                }

                var container = document.getElementById('applePayDiv');
                if (container) container.innerHTML = '';

                var scriptUrl = apayConfig.directScriptUrl + data.queryString;
                var script = document.createElement('script');
                script.type = 'text/javascript';
                script.src = scriptUrl;

                script.onload = function () {
                    scriptLoaded = true;
                    scriptLoading = false;
                    scriptInitData = data;
                    windowPropsAfterScript = getWindowPropertyNamesSnapshot();
                    saleResponseHandled = false;
                    if (loadingEl) loadingEl.style.display = 'none';
                    waitForContainerAndInit(data);
                    if (apayIsActiveMethod) {
                        updateApplePayButtonVisibility();
                    }
                };

                script.onerror = function () {
                    scriptLoading = false;
                    if (loadingEl) loadingEl.style.display = 'none';
                    showError('Failed to load Apple Pay script.');
                };

                document.body.appendChild(script);
            })
            .catch(function (err) {
                scriptLoading = false;
                if (loadingEl) loadingEl.style.display = 'none';
                showError('Failed to initialize Apple Pay: ' + err.message);
            });
    }

    function getWindowPropertyNamesSnapshot() {
        try {
            if (typeof Object.getOwnPropertyNames === 'function') {
                return Object.getOwnPropertyNames(window);
            }
        } catch (e) {
            // ignore
        }

        var names = [];
        for (var key in window) {
            names.push(key);
        }
        return names;
    }

    function functionLooksLikeAppleInitializer(fn) {
        if (typeof fn !== 'function') {
            return false;
        }

        if (fn.length >= 7) {
            return true;
        }

        var body = '';
        try {
            body = Function.prototype.toString.call(fn).toLowerCase();
        } catch (e) {
            body = '';
        }

        if (!body) {
            return false;
        }

        return body.indexOf('applepay') !== -1
            || body.indexOf('apple pay') !== -1
            || body.indexOf('start-session') !== -1
            || body.indexOf('startsession') !== -1
            || body.indexOf('applepaysession') !== -1
            || body.indexOf('wallet') !== -1;
    }

    function logInitializerDebug() {
        if (!(window.console && console.warn)) {
            return;
        }

        var added = [];
        if (windowPropsBeforeScript && windowPropsAfterScript) {
            var existing = {};
            for (var i = 0; i < windowPropsBeforeScript.length; i++) {
                existing[windowPropsBeforeScript[i]] = true;
            }

            for (var j = 0; j < windowPropsAfterScript.length; j++) {
                var key = windowPropsAfterScript[j];
                if (!existing[key]) {
                    added.push(key);
                }
            }
        }

        var interesting = [];
        for (var k = 0; k < added.length; k++) {
            var name = added[k];
            var lowName = String(name).toLowerCase();
            if (lowName.indexOf('apple') !== -1
                || lowName.indexOf('pay') !== -1
                || lowName.indexOf('wallet') !== -1
                || lowName.indexOf('vpos') !== -1
                || lowName.indexOf('session') !== -1) {
                interesting.push(name);
            }
        }

        console.warn('[Cardlink ApplePay] initializer unresolved; new globals:', added.slice(0, 50));
        if (interesting.length) {
            console.warn('[Cardlink ApplePay] interesting new globals:', interesting.slice(0, 50));
        }
    }

    function waitForContainerAndInit(initData) {
        var container = document.getElementById('applePayDiv');
        if (container) {
            initializeApplePayButton(initData);
            return;
        }

        var attempts = 0;
        var maxAttempts = 20;
        var poll = setInterval(function () {
            attempts++;
            container = document.getElementById('applePayDiv');
            if (container) {
                clearInterval(poll);
                initializeApplePayButton(initData);
            } else if (attempts >= maxAttempts) {
                clearInterval(poll);
                showError('Apple Pay container was not found in checkout.');
            }
        }, 250);
    }

    function installSaleResponseInterceptor() {
        if (saleInterceptorInstalled || !apayConfig) {
            return;
        }

        saleInterceptorInstalled = true;
        var walletUrl = (apayConfig.walletUrl || '').replace(/\/+$/, '');

        function isSaleUrl(url) {
            return typeof url === 'string'
                && url.indexOf(walletUrl) !== -1
                && url.indexOf('sale') !== -1;
        }

        var origOpen = XMLHttpRequest.prototype.open;
        var origSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function (method, url) {
            if (method && method.toUpperCase() === 'POST' && isSaleUrl(url)) {
                this._isApplePaySale = true;
            }
            return origOpen.apply(this, arguments);
        };

        XMLHttpRequest.prototype.send = function () {
            if (this._isApplePaySale) {
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
                var isApplePaySale = fetchMethod === 'POST' && isSaleUrl(fetchUrl);

                var promise = origFetch.apply(this, arguments);
                if (isApplePaySale) {
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
                handleSuccess();
            } else if (resp.status === 'fail') {
                saleResponseHandled = true;
                handleFailure(resp.error || 'Apple Pay payment failed. Please try again.');
            }
        } catch (e) {
            // ignore
        }
    }

    function initializeApplePayButton(initData) {
        if (applePayInitialized) {
            return;
        }

        var initFn = resolveApplePayInitializer();
        if (window.console && console.log) {
            console.log('[Cardlink ApplePay] initializer found:', !!initFn, 'retry:', applePayInitRetryCount);
        }
        if (!initFn) {
            if (applePayInitRetryCount === 0) {
                logInitializerDebug();
            }
            if (applePayInitRetryCount < 20) {
                applePayInitRetryCount++;
                setTimeout(function () {
                    initializeApplePayButton(initData);
                }, 200);
                return;
            }

            showError('Apple Pay is not available.');
            return;
        }

        applePayInitRetryCount = 0;

        try {
            var orderTotal = parseFloat(apayConfig.orderTotal).toFixed(2);
            var currencyCode = apayConfig.currencyCode || 'EUR';
            var xmlVersion = (initData.vposVersion || '2') + '.1';
            var orderId = generateOrderId();

            if (typeof window.paymentResponse === 'undefined') {
                window.paymentResponse = {
                    complete: function (status) {
                        if (status === 'success') {
                            handleSuccess();
                        } else {
                            handleFailure('Apple Pay payment was not completed.');
                        }
                    }
                };
            }

            window.threeDSHandler = function (resp) {
                threeDSHandler(resp);
            };
            window.applePayOnSuccess = handleSuccess;
            window.applePayOnFailure = function () { handleFailure('Apple Pay payment failed. Please try again.'); };
            window.applePayOnCancel = function () { handleFailure('Apple Pay was canceled.'); };
            window.applePayOnError = function () { handleFailure('Apple Pay failed. Please try again.'); };

            installSaleResponseInterceptor();

            var walletBaseUrl = apayConfig.walletUrl.replace(/\/+$/, '');
            if (window.console && console.log) {
                console.log('[Cardlink ApplePay] init params', {
                    xmlVersion: xmlVersion,
                    orderId: orderId,
                    orderTotal: orderTotal,
                    currencyCode: currencyCode,
                    merchantId: initData.mid,
                    walletBaseUrl: walletBaseUrl
                });
            }
            try {
                initFn(
                    xmlVersion,
                    orderId,
                    orderTotal,
                    currencyCode,
                    'Order Total',
                    'Total',
                    initData.mid,
                    walletBaseUrl
                );
                if (window.console && console.log) {
                    console.log('[Cardlink ApplePay] init invoked with base URL');
                }
            } catch (e1) {
                if (window.console && console.warn) {
                    console.warn('[Cardlink ApplePay] base URL init failed, retrying with trailing slash', e1);
                }
                initFn(
                    xmlVersion,
                    orderId,
                    orderTotal,
                    currencyCode,
                    'Order Total',
                    'Total',
                    initData.mid,
                    walletBaseUrl + '/'
                );
                if (window.console && console.log) {
                    console.log('[Cardlink ApplePay] init invoked with slash URL');
                }
            }

            applePayInitialized = true;
        } catch (error) {
            showError('Error initializing Apple Pay: ' + error.message);
        }
    }

    function resolveApplePayInitializer() {
        if (typeof initApplePay === 'function') {
            if (window.console && console.log) {
                console.log('[Cardlink ApplePay] using global initApplePay');
            }
            return initApplePay;
        }

        if (typeof initApplepay === 'function') {
            if (window.console && console.log) {
                console.log('[Cardlink ApplePay] using global initApplepay');
            }
            return initApplepay;
        }

        if (typeof initializeApplePay === 'function') {
            if (window.console && console.log) {
                console.log('[Cardlink ApplePay] using global initializeApplePay');
            }
            return initializeApplePay;
        }

        if (typeof startApplePay === 'function') {
            if (window.console && console.log) {
                console.log('[Cardlink ApplePay] using global startApplePay');
            }
            return startApplePay;
        }

        if (typeof window.initApplePay === 'function') {
            if (window.console && console.log) {
                console.log('[Cardlink ApplePay] using window.initApplePay');
            }
            return window.initApplePay;
        }

        if (typeof window.initApplepay === 'function') {
            if (window.console && console.log) {
                console.log('[Cardlink ApplePay] using window.initApplepay');
            }
            return window.initApplepay;
        }

        if (typeof window.initializeApplePay === 'function') {
            if (window.console && console.log) {
                console.log('[Cardlink ApplePay] using window.initializeApplePay');
            }
            return window.initializeApplePay;
        }

        if (typeof window.startApplePay === 'function') {
            if (window.console && console.log) {
                console.log('[Cardlink ApplePay] using window.startApplePay');
            }
            return window.startApplePay;
        }

        if (window.ApplePayDirect && typeof window.ApplePayDirect.initApplePay === 'function') {
            if (window.console && console.log) {
                console.log('[Cardlink ApplePay] using window.ApplePayDirect.initApplePay');
            }
            return function () {
                return window.ApplePayDirect.initApplePay.apply(window.ApplePayDirect, arguments);
            };
        }

        if (window.applePayDirect && typeof window.applePayDirect.initApplePay === 'function') {
            if (window.console && console.log) {
                console.log('[Cardlink ApplePay] using window.applePayDirect.initApplePay');
            }
            return function () {
                return window.applePayDirect.initApplePay.apply(window.applePayDirect, arguments);
            };
        }

        var fromWindowProps = resolveInitializerFromWindowProperties();
        if (fromWindowProps) {
            return fromWindowProps;
        }

        var fromNewGlobals = resolveInitializerFromNewGlobals();
        if (fromNewGlobals) {
            return fromNewGlobals;
        }

        for (var key in window) {
            if (!Object.prototype.hasOwnProperty.call(window, key)) {
                continue;
            }

            var lowKey = String(key).toLowerCase();
            if (lowKey.indexOf('initapple') !== -1 && typeof window[key] === 'function') {
                if (window.console && console.log) {
                    console.log('[Cardlink ApplePay] using dynamic initializer key:', key);
                }
                return window[key];
            }
        }

        return null;
    }

    function resolveInitializerFromWindowProperties() {
        var names = getWindowPropertyNamesSnapshot();

        for (var i = 0; i < names.length; i++) {
            var key = names[i];
            var value;

            try {
                value = window[key];
            } catch (e) {
                continue;
            }

            if (typeof value === 'function') {
                var lowFnName = String(key).toLowerCase();
                if ((lowFnName.indexOf('apple') !== -1 && lowFnName.indexOf('init') !== -1)
                    || (lowFnName.indexOf('applepay') !== -1 && lowFnName.indexOf('start') !== -1)
                    || (lowFnName.indexOf('applepay') !== -1 && lowFnName.indexOf('setup') !== -1)
                    || ((lowFnName.indexOf('apple') !== -1 || lowFnName.indexOf('wallet') !== -1 || lowFnName.indexOf('session') !== -1) && functionLooksLikeAppleInitializer(value))) {
                    if (window.console && console.log) {
                        console.log('[Cardlink ApplePay] using window function:', key);
                    }
                    return value;
                }
            }

            var initializer = resolveInitializerOnObject(value, key);
            if (initializer) {
                return initializer;
            }
        }

        return null;
    }

    function resolveInitializerFromNewGlobals() {
        if (!windowPropsBeforeScript || !windowPropsAfterScript || !windowPropsAfterScript.length) {
            return null;
        }

        var existing = {};
        for (var i = 0; i < windowPropsBeforeScript.length; i++) {
            existing[windowPropsBeforeScript[i]] = true;
        }

        for (var j = 0; j < windowPropsAfterScript.length; j++) {
            var key = windowPropsAfterScript[j];
            if (existing[key]) {
                continue;
            }

            var value;
            try {
                value = window[key];
            } catch (e) {
                continue;
            }

            if (typeof value === 'function') {
                var lowName = String(key).toLowerCase();
                if (lowName.indexOf('apple') !== -1 && (lowName.indexOf('init') !== -1 || lowName.indexOf('start') !== -1 || lowName.indexOf('setup') !== -1)) {
                    if (window.console && console.log) {
                        console.log('[Cardlink ApplePay] using new global function:', key);
                    }
                    return value;
                }

                if ((lowName.indexOf('apple') !== -1 || lowName.indexOf('wallet') !== -1 || lowName.indexOf('session') !== -1)
                    && functionLooksLikeAppleInitializer(value)) {
                    if (window.console && console.log) {
                        console.log('[Cardlink ApplePay] using heuristic new function:', key);
                    }
                    return value;
                }
            }

            var initializer = resolveInitializerOnObject(value, key);
            if (initializer) {
                return initializer;
            }
        }

        return null;
    }

    function resolveInitializerOnObject(obj, objectName) {
        if (!obj || (typeof obj !== 'object' && typeof obj !== 'function')) {
            return null;
        }

        var objectNameLower = String(objectName || '').toLowerCase();
        var isAppleScopedObject = objectNameLower.indexOf('apple') !== -1
            || objectNameLower.indexOf('apay') !== -1
            || objectNameLower.indexOf('wallet') !== -1
            || objectNameLower.indexOf('vpos') !== -1;

        var preferred = ['initApplePay', 'initApplepay', 'initializeApplePay', 'startApplePay', 'setupApplePay'];
        if (isAppleScopedObject) {
            preferred = preferred.concat(['init', 'initialize', 'start']);
        }
        for (var i = 0; i < preferred.length; i++) {
            var methodName = preferred[i];
            if (typeof obj[methodName] === 'function') {
                if (window.console && console.log) {
                    console.log('[Cardlink ApplePay] using object method:', objectName + '.' + methodName);
                }
                return function (targetObj, fnName) {
                    return function () {
                        return targetObj[fnName].apply(targetObj, arguments);
                    };
                }(obj, methodName);
            }
        }

        var propNames = [];
        try {
            propNames = Object.getOwnPropertyNames(obj);
        } catch (e) {
            return null;
        }

        for (var j = 0; j < propNames.length; j++) {
            var key = propNames[j];
            var value;

            try {
                value = obj[key];
            } catch (e2) {
                continue;
            }

            if (typeof value !== 'function') {
                continue;
            }

            var lowKey = String(key).toLowerCase();
            if ((lowKey.indexOf('apple') !== -1 && lowKey.indexOf('init') !== -1)
                || (lowKey.indexOf('applepay') !== -1 && lowKey.indexOf('start') !== -1)
                || (lowKey.indexOf('applepay') !== -1 && lowKey.indexOf('setup') !== -1)
                || (isAppleScopedObject && (lowKey === 'init' || lowKey === 'initialize' || lowKey === 'start'))
                || ((lowKey.indexOf('session') !== -1 || lowKey.indexOf('wallet') !== -1 || isAppleScopedObject) && functionLooksLikeAppleInitializer(value))) {
                if (window.console && console.log) {
                    console.log('[Cardlink ApplePay] using discovered method:', objectName + '.' + key);
                }
                return function (targetObj, fnName) {
                    return function () {
                        return targetObj[fnName].apply(targetObj, arguments);
                    };
                }(obj, key);
            }
        }

        return null;
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

        fetch(apayConfig.createXidUrl, {
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

                var signPayload = {
                    mpiVersion: '4.0',
                    pan: '',
                    expiry: '',
                    cardEncData: cardEncData,
                    devCat: '0',
                    purchAmount: orderAmount,
                    exponent: '2',
                    description: 'CART ' + apayConfig.cartId,
                    currMpi: apayConfig.currencyNumeric,
                    merchantID: scriptInitData.mid,
                    xidb64: xid,
                    okUrl: apayConfig.threeDsSuccessUrl,
                    failUrl: apayConfig.threeDsFailureUrl,
                    recurFreq: null,
                    recurEnd: null,
                    installments: null
                };

                fetch(apayConfig.signDataUrl, {
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
                        form.action = apayConfig.mpiUrl;
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
        if (!isThreeDsInModal()) {
            form.submit();
            return;
        }

        var frameName = 'cardlink-applepay-3ds-frame';
        ensureThreeDsModal(frameName);
        form.setAttribute('target', frameName);
        form.submit();
    }

    function isThreeDsInModal() {
        return apayConfig && apayConfig.threeDsUiMode === 'iframe_modal';
    }

    function ensureThreeDsModal(frameName) {
        var existing = document.getElementById('cardlink-applepay-3ds-modal');
        if (existing) {
            existing.style.display = 'block';
            return;
        }

        var overlay = document.createElement('div');
        overlay.id = 'cardlink-applepay-3ds-modal';
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
            window.location.href = window.location.origin + '/index.php?controller=order-confirmation&id_cart=' + (apayConfig ? apayConfig.cartId : '');
        }
    }

    function handleFailure(message) {
        showError(message || 'Apple Pay payment failed. Please try again or choose a different payment method.');
    }

    function generateOrderId() {
        var cartId = apayConfig.cartId;
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
        var loadingEl = document.getElementById('cardlink-applepay-loading');
        if (loadingEl) {
            loadingEl.style.display = show ? 'block' : 'none';
            loadingEl.innerHTML = '<span>' + (show ? 'Processing payment...' : '') + '</span>';
        }
    }

    function showError(message) {
        var errorEl = document.getElementById('cardlink-applepay-error');
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.style.display = 'block';
        }
    }

    function clearError() {
        var errorEl = document.getElementById('cardlink-applepay-error');
        if (errorEl) {
            errorEl.textContent = '';
            errorEl.style.display = 'none';
        }
    }

    function getApplePayEnvironmentIssue() {
        if (typeof window.ApplePaySession === 'undefined') {
            return 'Apple Pay is not supported on this device/browser. Please use Safari on a compatible Apple device.';
        }

        try {
            if (typeof window.ApplePaySession.canMakePayments === 'function'
                && !window.ApplePaySession.canMakePayments()) {
                return 'Apple Pay is not available on this device. Please ensure Wallet is configured and try again.';
            }
        } catch (e) {
            return 'Apple Pay cannot be initialized on this device/browser session.';
        }

        return null;
    }

    function checkAndInit() {
        if (!tryInitConfig()) {
            return;
        }

        attachApplePayFormGuard();
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
