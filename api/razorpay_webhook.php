<?php
/* =====================================================================
   POST api/razorpay_webhook.php   (called by Razorpay's servers)

   Safety net: if the buyer paid but closed the app / lost signal before
   verify_payment.php ran, Razorpay still tells us here, so the order is
   saved and receipts still go out.

   Set it up: Razorpay Dashboard → Accounts & Settings → Webhooks → Add
     URL:     https://yourdomain.com/api/razorpay_webhook.php
     Secret:  any long random text → also put it in config 'webhook_secret'
     Events:  payment.captured  and  order.paid
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/razorpay.php';
require __DIR__ . '/../includes/orders.php';

$raw       = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

if (!rzp_verify_webhook($raw, $signature)) {
    log_msg('webhook: bad signature', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    json_out(['success' => false], 400);
}

$event   = json_decode($raw, true) ?: [];
$name    = $event['event'] ?? '';
$payment = $event['payload']['payment']['entity'] ?? null;

// We only care about successful payments
if (!in_array($name, ['payment.captured', 'order.paid'], true) || !$payment) {
    json_out(['success' => true, 'ignored' => $name]);
}

$order = find_order('razorpay_order_id', (string) ($payment['order_id'] ?? ''));
if (!$order) {
    log_msg('webhook: order not found', ['rzp_order' => $payment['order_id'] ?? null, 'payment' => $payment['id'] ?? null]);
    json_out(['success' => true, 'note' => 'unknown order']);   // 200 so Razorpay stops retrying
}

// Make sure the amount paid matches the order total
$expectedPaise = (int) round((float) $order['total_amount'] * 100);
if ((int) ($payment['amount'] ?? 0) !== $expectedPaise) {
    log_msg('webhook: AMOUNT MISMATCH', ['order' => $order['order_ref'], 'paid' => $payment['amount'] ?? null, 'expected' => $expectedPaise]);
    json_out(['success' => true, 'note' => 'amount mismatch logged']);
}

$result = finalize_paid_order($order, (string) $payment['id'], 'webhook');
if ($result['newly_paid']) {
    log_msg('PAID ' . $order['order_ref'] . ' ' . inr($order['total_amount']) . ' via webhook', ['payment' => $payment['id']]);
    notify_order_paid((int) $order['id'], $result['oversold']);
}

json_out(['success' => true]);
