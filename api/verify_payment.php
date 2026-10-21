<?php
/* =====================================================================
   POST api/verify_payment.php
   Step 2 of payment. Razorpay Checkout calls our JS handler after a
   successful payment; script.js forwards the three values here.

   Receives (JSON):
     { razorpay_order_id, razorpay_payment_id, razorpay_signature }

   What it does:
     1. Checks the signature with our key_secret (proves it's genuine).
     2. Marks the order PAID and reduces stock (only once).
     3. Sends the Email receipt + WhatsApp receipt + seller alert.
     4. Replies with the receipt details for the success screen.

   If the buyer's phone loses signal right after paying, this file never
   gets called — that's what api/razorpay_webhook.php is for.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/razorpay.php';
require __DIR__ . '/../includes/orders.php';

$in = json_in();

$rzpOrderId   = (string) ($in['razorpay_order_id'] ?? '');
$rzpPaymentId = (string) ($in['razorpay_payment_id'] ?? '');
$signature    = (string) ($in['razorpay_signature'] ?? '');

// Basic shape check before touching the database
if (!preg_match('/^order_\w{6,40}$/', $rzpOrderId) || !preg_match('/^pay_\w{6,40}$/', $rzpPaymentId) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
    json_error('Invalid payment details.');
}

/* 1. Genuine? */
if (!rzp_verify_signature($rzpOrderId, $rzpPaymentId, $signature)) {
    log_msg('verify_payment: BAD SIGNATURE', ['order' => $rzpOrderId, 'payment' => $rzpPaymentId, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    json_error('Payment could not be verified. If money was deducted, please contact us with your payment ID.', 400);
}

$order = find_order('razorpay_order_id', $rzpOrderId);
if (!$order) {
    log_msg('verify_payment: order not found', ['order' => $rzpOrderId, 'payment' => $rzpPaymentId]);
    json_error('Order not found. Please contact us with your payment ID.', 404);
}

/* 2. Mark paid + reduce stock (safe if the webhook already did it) */
$result = finalize_paid_order($order, $rzpPaymentId, $signature);

/* 3. Receipts — only the first time the order becomes paid */
if ($result['newly_paid']) {
    log_msg('PAID ' . $order['order_ref'] . ' ' . inr($order['total_amount']) . ' via checkout', ['payment' => $rzpPaymentId]);
    notify_order_paid((int) $order['id'], $result['oversold']);
}

/* 4. Reply with fresh data for the success screen */
json_out(order_public_view(find_order('id', (int) $order['id'])));
