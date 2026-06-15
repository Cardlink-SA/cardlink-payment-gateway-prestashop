<?php

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * English Translation File
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

return array(
    // Payment operation messages
    'Payment has been successfully captured' => 'Payment has been successfully captured',
    'Payment has been successfully voided' => 'Payment has been successfully voided',
    'Payment has been successfully refunded' => 'Payment has been successfully refunded',

    // Error messages
    'Only authorized transactions can be captured' => 'Only authorized transactions can be captured',
    'Only captured transactions can be refunded' => 'Only captured transactions can be refunded',
    'Captured transactions cannot be voided. Please refund instead.' => 'Captured transactions cannot be voided. Please refund instead.',
    'No payment transaction found for this order' => 'No payment transaction found for this order',
    'Unknown action' => 'Unknown action',
    'Capture failed: ' => 'Capture failed: ',
    'Void failed: ' => 'Void failed: ',
    'Refund failed: ' => 'Refund failed: ',

    // Refund-specific error messages
    'Transaction is in settlement transit. Please wait until settlement is complete (usually the next business day) and try again.' => 'Transaction is in settlement transit. Please wait until settlement is complete (usually the next business day) and try again.',
    'Partial refund is not possible on unsettled transactions. You can only void the full amount.' => 'Partial refund is not possible on unsettled transactions. You can only void the full amount.',
    'Original transaction is not refundable and partial void is not supported. Please wait for settlement or create a credit memo for the full amount.' => 'Original transaction is not refundable and partial void is not supported. Please wait for settlement or create a credit memo for the full amount.',
    'Both refund and void operations failed. Refund error: %1. Void error: %2.' => 'Both refund and void operations failed. Refund error: %1. Void error: %2.',
    'Partial refund is not allowed. The transaction has not been settled yet. You can only void the full amount.' => 'Partial refund is not allowed. The transaction has not been settled yet. You can only void the full amount.',

    // Generic error messages
    'Capture request failed' => 'Capture request failed',
    'Void request failed' => 'Void request failed',
    'Refund request failed' => 'Refund request failed',
    'Unable to check transaction status' => 'Unable to check transaction status',
    'Capture failed: Unknown error' => 'Capture failed: Unknown error',
    'Void failed: Unknown error' => 'Void failed: Unknown error',
    'Refund failed: Unknown error' => 'Refund failed: Unknown error',

    // Background confirmation messages
    'Invalid User-Agent' => 'Invalid User-Agent',
    'Empty request' => 'Empty request',
    'Invalid order ID format' => 'Invalid order ID format',
    'Cart not found' => 'Cart not found',
    'Merchant ID mismatch' => 'Merchant ID mismatch',
    'HTTPS required in production' => 'HTTPS required in production',
    'Invalid signature' => 'Invalid signature',
    'Order already in final state' => 'Order already in final state',
    'Order updated successfully' => 'Order updated successfully',
    'Request processed' => 'Request processed',
);
