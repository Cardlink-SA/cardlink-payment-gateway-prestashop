<?php

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Payment Response Handle Controller
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

class Cardlink_CheckoutResponseModuleFrontController extends ModuleFrontController
{
    // public $ssl = true;
    // public $auth = true;
    // public $guestAllowed = false;

    public const ERROR_REDIRECT_URI = 'index.php?controller=order&step=1';

    public function postProcess()
    {
        $success = false;

        /**
         * Get current cart object from session
         */
        $cart = $this->context->cart;

        // /**
        //  * Verify if this module is enabled and if the cart has
        //  * a valid customer, delivery address and invoice address
        //  */
        // if (
        //     !$this->module->active || $cart->id_customer == 0
        // ) {
        //     Tools::redirect(self::ERROR_REDIRECT_URI);
        // }

        // /** @var CustomerCore $customer */
        // $customer = new Customer($cart->id_customer);

        // /**
        //  * Check if this is a valid customer account
        //  */
        // if (!Validate::isLoadedObject($customer)) {
        //     Tools::redirect(self::ERROR_REDIRECT_URI);
        // }

        $responseData = Tools::getAllValues();

        // Verify that the response is coming from the payment gateway.
        $isValidPaymentGatewayResponse = Cardlink_Checkout\PaymentHelper::validateResponseData(
            $responseData,
            Configuration::get(Cardlink_Checkout\Constants::CONFIG_SHARED_SECRET, null, null, null, '')
        );

        if (!$isValidPaymentGatewayResponse) {
            Tools::redirect(self::ERROR_REDIRECT_URI);
        }

        $id_order = intval($responseData[Cardlink_Checkout\ApiFields::OrderId]);
        $order_details = new Order($id_order);

        $order_cart_id = Cart::getCartIdByOrderId($id_order);

        $customer = new Customer($order_details->id_customer);

        // /**
        //  * Check if the requested order is linked to the customer
        //  */
        // if ($order_details->id_customer != $cart->id_customer) {
        //     Tools::redirect(self::ERROR_REDIRECT_URI);
        // }

        // If the response identifies the transaction as either AUTHORIZED or CAPTURED.
        if (
            $responseData[Cardlink_Checkout\ApiFields::Status] === Cardlink_Checkout\Constants::TRANSACTION_STATUS_AUTHORIZED
            || $responseData[Cardlink_Checkout\ApiFields::Status] === Cardlink_Checkout\Constants::TRANSACTION_STATUS_CAPTURED
        ) {
            $isValidXlsBonusPaymentGatewayResponse = true;

            if (array_key_exists(Cardlink_Checkout\ApiFields::XlsBonusDigest, $responseData)) {
                $isValidXlsBonusPaymentGatewayResponse = Cardlink_Checkout\PaymentHelper::validateXlsBonusResponseData(
                    $responseData,
                    Configuration::get(Cardlink_Checkout\Constants::CONFIG_SHARED_SECRET, null, null, null, '')
                );
            }

            if ($isValidXlsBonusPaymentGatewayResponse == true) {
                // Set order as paid.
                $this->errors = Cardlink_Checkout\PaymentHelper::markSuccessfulPayment($this->module, $order_details, $responseData);
                $this->sendOrderConfirmationEmail($order_details);

                try {
                    if (array_key_exists(Cardlink_Checkout\ApiFields::ExtToken, $responseData)) {
                        $stored_token = new Cardlink_Checkout\StoredToken();
                        $stored_token->active = true;
                        $stored_token->id_customer = $order_details->id_customer;
                        $stored_token->token = $responseData[Cardlink_Checkout\ApiFields::ExtToken];
                        $stored_token->type = $responseData[Cardlink_Checkout\ApiFields::PaymentMethod];
                        $stored_token->last_4digits = $responseData[Cardlink_Checkout\ApiFields::ExtTokenPanEnd];
                        $stored_token->expiration = $responseData[Cardlink_Checkout\ApiFields::ExtTokenExpiration];
                        $stored_token->save();
                    }
                } catch (\Exception $e) {
                }

                $success = true;
            }
        } else if (
            $responseData[Cardlink_Checkout\ApiFields::Status] === Cardlink_Checkout\Constants::TRANSACTION_STATUS_CANCELED
            || $responseData[Cardlink_Checkout\ApiFields::Status] === Cardlink_Checkout\Constants::TRANSACTION_STATUS_REFUSED
            || $responseData[Cardlink_Checkout\ApiFields::Status] === Cardlink_Checkout\Constants::TRANSACTION_STATUS_ERROR
        ) {
            $this->errors = Cardlink_Checkout\PaymentHelper::markCanceledPayment($this->module, $order_details, true);
        }

        $id_shop = $order_details->id_shop;
        $id_lang = $order_details->id_lang;

        // If the payment flow executed inside the IFRAME, send out a redirection form page to force open the final response page in the parent frame (store window/tab).
        if (boolval(Configuration::get(Cardlink_Checkout\Constants::CONFIG_USE_IFRAME))) {
            if ($success) {
                $redirectParameters = [
                    'id_shop' => $id_shop,
                    'id_cart' => (int) $order_cart_id,
                    'id_module' => (int) $this->module->id,
                    'id_order' => $id_order,
                    'key' => $customer->secure_key
                ];

                $redirectParameters['key'] = $customer->secure_key;
                $redirectParameters['message'] = $responseData[Cardlink_Checkout\ApiFields::Message];

                $redirectUrl = Context::getContext()->link->getPageLink('order-confirmation', true, $id_lang, $redirectParameters, false, $id_shop);
            } else {
                $redirectParameters = [
                    'status' => $responseData[Cardlink_Checkout\ApiFields::Status],
                    'message' => $responseData[Cardlink_Checkout\ApiFields::Message],
                    'key' => $customer->secure_key
                ];

                $redirectUrl = $this->context->link->getModuleLink($this->module->name, 'payment', $redirectParameters, true);
            }

            $this->context->smarty->assign([
                'action' => explode('?', $redirectUrl)[0],
                'form_data' => $redirectParameters,
                'css_url' => $this->module->getPathUri() . 'views/css/front-custom.css',
                'use_iframe' => boolval(Configuration::get(Cardlink_Checkout\Constants::CONFIG_USE_IFRAME, '0'))
            ]);

            /**
             *  Load form template to be displayed in the checkout step
             */
            $this->setTemplate('module:cardlink_checkout/views/templates/front/response_form.tpl');
        } else {
            if ($success) {
                $redirectParameters = [
                    'controller' => 'order-confirmation',
                    'id_shop' => $id_shop,
                    'id_cart' => (int) $order_cart_id,
                    'id_module' => (int) $this->module->id,
                    'id_order' => $id_order,
                    'key' => $customer->secure_key
                ];

                Tools::redirect('index.php?' . http_build_query($redirectParameters));
            } else {
                $redirectParameters = [
                    'status' => $responseData[Cardlink_Checkout\ApiFields::Status],
                    'message' => $responseData[Cardlink_Checkout\ApiFields::Message],
                    'key' => $customer->secure_key
                ];

                $redirectUrl = $this->context->link->getModuleLink($this->module->name, 'payment', $redirectParameters, true);
                Tools::redirect($redirectUrl);
            }
            return;
        }
    }

    public function sendOrderConfirmationEmail(Order $order)
    {
        $customer = new Customer($order->id_customer);
        $order_status = new OrderState((int) $order->current_state, (int) $order->id_lang);

        // Join PDF invoice
        if ((int) Configuration::get('PS_INVOICE') && $order_status->invoice && $order->invoice_number) {
            $pdf = new PDF($order->getInvoicesCollection(), PDF::TEMPLATE_INVOICE, $this->context->smarty);
            $file_attachement['content'] = $pdf->render(false);
            $file_attachement['name'] = Configuration::get('PS_INVOICE_PREFIX', (int) $order->id_lang, null, $order->id_shop) . sprintf('%06d', $order->invoice_number) . '.pdf';
            $file_attachement['mime'] = 'application/pdf';
        } else {
            $file_attachement = null;
        }

        $data = $this->fillOrderConfirmationData($order->id);

        Mail::Send(
            (int) $order->id_lang,
            'order_conf',
            Mail::l('Order confirmation', (int) $order->id_lang),
            $data,
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname,
            null,
            null,
            $file_attachement,
            null,
            _PS_MAIL_DIR_,
            false,
            (int) $order->id_shop
        );
    }

    public static function _getFormatedAddress(Address $the_address, $line_sep, $fields_style = array())
    {
        return AddressFormat::generateAddress(
            $the_address,
            array('avoid' => array()),
            $line_sep,
            ' ',
            $fields_style
        );
    }

    public function fillOrderConfirmationData($id_order)
    {
        $order = new Order($id_order);
        $order_details = OrderDetail::getList($id_order);
        $customer = new Customer($order->id_customer);
        $invoice = new Address($order->id_address_invoice);
        $delivery = new Address($order->id_address_delivery);
        $delivery_state = $delivery->id_state ? new State($delivery->id_state) : false;
        $invoice_state = $invoice->id_state ? new State($invoice->id_state) : false;
        $carrier = new Carrier($order->id_carrier);
        $currency = new Currency($order->id_currency);

        $invoice = new Address((int) $order->id_address_invoice);
        $delivery = new Address((int) $order->id_address_delivery);
        $delivery_state = $delivery->id_state ? new State((int) $delivery->id_state) : false;
        $invoice_state = $invoice->id_state ? new State((int) $invoice->id_state) : false;
        $carrier = $order->id_carrier ? new Carrier($order->id_carrier) : false;
        $orderLanguage = new Language((int) $order->id_lang);

        $id_order_state = (int) $order->current_state;
        $order_status = new OrderState($id_order_state, (int) $this->context->language->id);

        // Construct order detail table for the email
        $virtual_product = true;

        $product_var_tpl_list = [];
        foreach ($order->getCartProducts() as $product) {
            $price = Product::getPriceStatic((int) $product['id_product'], false, ($product['id_product_attribute'] ? (int) $product['id_product_attribute'] : null), 6, null, false, true, $product['cart_quantity'], false, (int) $order->id_customer, (int) $order->id_cart, (int) $order->{Configuration::get('PS_TAX_ADDRESS_TYPE')}, $specific_price, true, true, null, true, $product['id_customization']);
            $price_wt = Product::getPriceStatic((int) $product['id_product'], true, ($product['id_product_attribute'] ? (int) $product['id_product_attribute'] : null), 2, null, false, true, $product['cart_quantity'], false, (int) $order->id_customer, (int) $order->id_cart, (int) $order->{Configuration::get('PS_TAX_ADDRESS_TYPE')}, $specific_price, true, true, null, true, $product['id_customization']);

            $product_price = Product::getTaxCalculationMethod() == PS_TAX_EXC ? Tools::ps_round($price, Context::getContext()->getComputingPrecision()) : $price_wt;

            $product_var_tpl = [
                'id_product' => $product['id_product'],
                'id_product_attribute' => $product['id_product_attribute'],
                'reference' => $product['reference'],
                'name' => $product['product_name'] . (isset($product['attributes']) ? ' - ' . $product['attributes'] : ''),
                'price' => Tools::getContextLocale($this->context)->formatPrice($product_price * $product['product_quantity'], $this->context->currency->iso_code),
                'quantity' => $product['product_quantity'],
                'customization' => [],
            ];

            if (isset($product['price']) && $product['price']) {
                $product_var_tpl['unit_price'] = Tools::getContextLocale($this->context)->formatPrice($product_price, $this->context->currency->iso_code);
                $product_var_tpl['unit_price_full'] = Tools::getContextLocale($this->context)->formatPrice($product_price, $this->context->currency->iso_code)
                    . ' ' . $product['unity'];
            } else {
                $product_var_tpl['unit_price'] = $product_var_tpl['unit_price_full'] = '';
            }

            $customized_datas = Product::getAllCustomizedDatas((int) $order->id_cart, null, true, null, (int) $product['id_customization']);
            if (isset($customized_datas[$product['id_product']][$product['id_product_attribute']])) {
                $product_var_tpl['customization'] = [];
                foreach ($customized_datas[$product['id_product']][$product['id_product_attribute']][$order->id_address_delivery] as $customization) {
                    $customization_text = '';
                    if (isset($customization['datas'][Product::CUSTOMIZE_TEXTFIELD])) {
                        foreach ($customization['datas'][Product::CUSTOMIZE_TEXTFIELD] as $text) {
                            $customization_text .= '<strong>' . $text['name'] . '</strong>: ' . $text['value'] . '<br />';
                        }
                    }

                    if (isset($customization['datas'][Product::CUSTOMIZE_FILE])) {
                        $customization_text .= $this->trans('%d image(s)', [count($customization['datas'][Product::CUSTOMIZE_FILE])], 'Admin.Payment.Notification') . '<br />';
                    }

                    $customization_quantity = (int) $customization['quantity'];

                    $product_var_tpl['customization'][] = [
                        'customization_text' => $customization_text,
                        'customization_quantity' => $customization_quantity,
                        'quantity' => Tools::getContextLocale($this->context)->formatPrice($customization_quantity * $product_price, $this->context->currency->iso_code),
                    ];
                }
            }

            $product_var_tpl_list[] = $product_var_tpl;
            // Check if is not a virtual product for the displaying of shipping
            if (!$product['is_virtual']) {
                $virtual_product &= false;
            }
        } // end foreach ($products)

        $product_list_txt = '';
        $product_list_html = '';
        if (count($product_var_tpl_list) > 0) {
            $product_list_txt = $this->module->getEmailTemplateContent('order_conf_product_list.txt', Mail::TYPE_TEXT, $product_var_tpl_list);
            $product_list_html = $this->module->getEmailTemplateContent('order_conf_product_list.tpl', Mail::TYPE_HTML, $product_var_tpl_list);
        }

        $total_reduction_value_ti = 0;
        $total_reduction_value_tex = 0;

        $cart_rules_list = $this->module->createOrderCartRules(
            $order,
            $this->context->cart,
            [$order],
            $total_reduction_value_ti,
            $total_reduction_value_tex,
            $id_order_state
        );

        $cart_rules_list_txt = '';
        $cart_rules_list_html = '';
        if (count($cart_rules_list) > 0) {
            $cart_rules_list_txt = $this->module->getEmailTemplateContent('order_conf_cart_rules.txt', Mail::TYPE_TEXT, $cart_rules_list);
            $cart_rules_list_html = $this->module->getEmailTemplateContent('order_conf_cart_rules.tpl', Mail::TYPE_HTML, $cart_rules_list);
        }


        // Join PDF invoice
        if ((int) Configuration::get('PS_INVOICE') && $order_status->invoice && $order->invoice_number) {
            $currentLanguage = $this->context->language;
            $this->context->language = $orderLanguage;
            $this->context->getTranslator()->setLocale($orderLanguage->locale);
            $order_invoice_list = $order->getInvoicesCollection();
            Hook::exec('actionPDFInvoiceRender', ['order_invoice_list' => $order_invoice_list]);
            $pdf = new PDF($order_invoice_list, PDF::TEMPLATE_INVOICE, $this->context->smarty);
            $file_attachement['content'] = $pdf->render(false);
            $file_attachement['name'] = Configuration::get('PS_INVOICE_PREFIX', (int) $order->id_lang, null, $order->id_shop) . sprintf('%06d', $order->invoice_number) . '.pdf';
            $file_attachement['mime'] = 'application/pdf';
            $this->context->language = $currentLanguage;
            $this->context->getTranslator()->setLocale($currentLanguage->locale);
        } else {
            $file_attachement = null;
        }

        $data = [
            '{firstname}' => $this->context->customer->firstname,
            '{lastname}' => $this->context->customer->lastname,
            '{email}' => $this->context->customer->email,
            '{delivery_block_txt}' => $this->_getFormatedAddress($delivery, AddressFormat::FORMAT_NEW_LINE),
            '{invoice_block_txt}' => $this->_getFormatedAddress($invoice, AddressFormat::FORMAT_NEW_LINE),
            '{delivery_block_html}' => $this->_getFormatedAddress($delivery, '<br />', [
                'firstname' => '<span style="font-weight:bold;">%s</span>',
                'lastname' => '<span style="font-weight:bold;">%s</span>',
            ]),
            '{invoice_block_html}' => $this->_getFormatedAddress($invoice, '<br />', [
                'firstname' => '<span style="font-weight:bold;">%s</span>',
                'lastname' => '<span style="font-weight:bold;">%s</span>',
            ]),
            '{delivery_company}' => $delivery->company,
            '{delivery_firstname}' => $delivery->firstname,
            '{delivery_lastname}' => $delivery->lastname,
            '{delivery_address1}' => $delivery->address1,
            '{delivery_address2}' => $delivery->address2,
            '{delivery_city}' => $delivery->city,
            '{delivery_postal_code}' => $delivery->postcode,
            '{delivery_country}' => $delivery->country,
            '{delivery_state}' => $delivery->id_state ? $delivery_state->name : '',
            '{delivery_phone}' => ($delivery->phone) ? $delivery->phone : $delivery->phone_mobile,
            '{delivery_other}' => $delivery->other,
            '{invoice_company}' => $invoice->company,
            '{invoice_vat_number}' => $invoice->vat_number,
            '{invoice_firstname}' => $invoice->firstname,
            '{invoice_lastname}' => $invoice->lastname,
            '{invoice_address2}' => $invoice->address2,
            '{invoice_address1}' => $invoice->address1,
            '{invoice_city}' => $invoice->city,
            '{invoice_postal_code}' => $invoice->postcode,
            '{invoice_country}' => $invoice->country,
            '{invoice_state}' => $invoice->id_state ? $invoice_state->name : '',
            '{invoice_phone}' => ($invoice->phone) ? $invoice->phone : $invoice->phone_mobile,
            '{invoice_other}' => $invoice->other,
            '{order_name}' => $order->getUniqReference(),
            '{id_order}' => $order->id,
            '{date}' => Tools::displayDate(date('Y-m-d H:i:s'), null, 1),
            '{carrier}' => ($virtual_product || !isset($carrier->name)) ? $this->trans('No carrier', [], 'Admin.Payment.Notification') : $carrier->name,
            '{payment}' => Tools::substr($order->payment, 0, 255) . ($order->hasBeenPaid() ? '' : '&nbsp;' . $this->trans('(waiting for validation)', [], 'Emails.Body')),
            '{products}' => $product_list_html,
            '{products_txt}' => $product_list_txt,
            '{discounts}' => $cart_rules_list_html,
            '{discounts_txt}' => $cart_rules_list_txt,
            '{total_paid}' => Tools::getContextLocale($this->context)->formatPrice($order->total_paid, $this->context->currency->iso_code),
            '{total_products}' => Tools::getContextLocale($this->context)->formatPrice(Product::getTaxCalculationMethod() == PS_TAX_EXC ? $order->total_products : $order->total_products_wt, $this->context->currency->iso_code),
            '{total_discounts}' => Tools::getContextLocale($this->context)->formatPrice($order->total_discounts, $this->context->currency->iso_code),
            '{total_shipping}' => Tools::getContextLocale($this->context)->formatPrice($order->total_shipping, $this->context->currency->iso_code),
            '{total_shipping_tax_excl}' => Tools::getContextLocale($this->context)->formatPrice($order->total_shipping_tax_excl, $this->context->currency->iso_code),
            '{total_shipping_tax_incl}' => Tools::getContextLocale($this->context)->formatPrice($order->total_shipping_tax_incl, $this->context->currency->iso_code),
            '{total_wrapping}' => Tools::getContextLocale($this->context)->formatPrice($order->total_wrapping, $this->context->currency->iso_code),
            '{total_tax_paid}' => Tools::getContextLocale($this->context)->formatPrice(($order->total_paid_tax_incl - $order->total_paid_tax_excl), $this->context->currency->iso_code),
        ];

        return $data;
    }
}