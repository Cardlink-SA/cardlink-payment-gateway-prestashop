$(document).ready(function () {
    var $acceptInstallments = jQuery("#CARDLINK_CHECKOUT_CONFIG_ACCEPT_INSTALLMENTS");
    var opt = $acceptInstallments.val();
    $acceptInstallments.on('change', function (e) {
        opt = $acceptInstallments.val();
        switch (opt) {
            case 'no':
                $('input[name="CARDLINK_CHECKOUT_CONFIG_FIXED_MAX_INSTALLMENTS"]').closest('.form-group').hide();
                $('#form-cardlink_checkout_installments').hide();
                break;

            case 'fixed':
                $('input[name="CARDLINK_CHECKOUT_CONFIG_FIXED_MAX_INSTALLMENTS"]').closest('.form-group').show();
                $('#form-cardlink_checkout_installments').hide();
                break;

            case 'order_amount':
                $('input[name="CARDLINK_CHECKOUT_CONFIG_FIXED_MAX_INSTALLMENTS"]').closest('.form-group').hide();
                $('#form-cardlink_checkout_installments').show();
                break;
        }
    });
    $acceptInstallments.trigger('change');
});