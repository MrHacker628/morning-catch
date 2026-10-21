<?php
/* =====================================================================
   razorpay.php — talks to Razorpay's REST API with plain cURL.

   Why not the Razorpay PHP SDK? The SDK needs Composer and a vendor/
   folder. These three functions are all this shop needs, they work
   on any Hostinger plan, and there's nothing extra to install.

   Razorpay docs:
     Orders API      https://razorpay.com/docs/api/orders/create/
     Verify payment  https://razorpay.com/docs/payments/server-integration/php/payment-gateway/build-integration/#verify-payment-signature
     Webhooks        https://razorpay.com/docs/webhooks/validate-test/
   ===================================================================== */

declare(strict_types=1);

const RAZORPAY_API = 'https://api.razorpay.com/v1';

/**
 * Create a Razorpay order.
 * @param int    $amountPaise  amount in paise (₹1 = 100 paise)
 * @param string $receipt      our order_ref (max 40 chars)
 * @return array               Razorpay's order object (we need ['id'])
 * @throws RuntimeException    if Razorpay refuses or can't be reached
 */
function rzp_create_order(int $amountPaise, string $receipt, array $notes = []): array
{
    $base = cfg('razorpay.api_base', RAZORPAY_API);   // api_base override is only for tests

    $ch = curl_init($base . '/orders');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => cfg('razorpay.key_id') . ':' . cfg('razorpay.key_secret'),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'amount'          => $amountPaise,
            'currency'        => 'INR',
            'receipt'         => substr($receipt, 0, 40),
            'notes'           => $notes,
        ]),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
    ]);

    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Could not reach Razorpay: ' . $err);
    }
    $data = json_decode((string) $body, true);
    if ($status !== 200 || empty($data['id'])) {
        $reason = $data['error']['description'] ?? ('HTTP ' . $status);
        throw new RuntimeException('Razorpay order failed: ' . $reason);
    }
    return $data;
}

/**
 * Check the signature Razorpay Checkout gives the browser after payment.
 * If it matches, the payment really happened for this order — nobody
 * can fake it without our key_secret.
 */
function rzp_verify_signature(string $razorpayOrderId, string $razorpayPaymentId, string $signature): bool
{
    $expected = hash_hmac('sha256', $razorpayOrderId . '|' . $razorpayPaymentId, (string) cfg('razorpay.key_secret'));
    return hash_equals($expected, $signature);
}

/**
 * Check a webhook call really came from Razorpay.
 * @param string $rawBody   the exact request body (do not json_decode first)
 * @param string $signature the X-Razorpay-Signature header
 */
function rzp_verify_webhook(string $rawBody, string $signature): bool
{
    $secret = (string) cfg('razorpay.webhook_secret', '');
    if ($secret === '' || $signature === '') return false;
    $expected = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, $signature);
}
