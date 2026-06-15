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

    // Function to add order_price-type class to paid_real column
    function addPaidColumnClass() {
        var paidCells = document.querySelectorAll('td.column-paid_real');
        if (paidCells.length > 0) {
            paidCells.forEach(function(cell) {
                cell.classList.add('order_price-type');
            });
        }
    }

    // Add class on page load
    setTimeout(addPaidColumnClass, 100);

    // Use MutationObserver to watch for grid updates
    var gridTable = document.querySelector('.table');
    if (gridTable) {
        var observer = new MutationObserver(function(mutations) {
            addPaidColumnClass();
        });
        
        observer.observe(gridTable, {
            childList: true,
            subtree: true
        });
    }

    // Listen for any visibility changes (grid might be hidden initially)
    $(document).on('DOMContentLoaded', addPaidColumnClass);
    $(window).on('load', addPaidColumnClass);
});