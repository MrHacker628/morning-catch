<?php
/* =====================================================================
   orders.php — shared order logic used by:
     api/verify_payment.php    (browser says "I paid")
     api/razorpay_webhook.php  (Razorpay says "they paid" — backup path)
     admin/index.php           (resend receipt)

   Both payment paths call finalize_paid_order(). It is safe to call
   twice: only the FIRST call marks the order paid, reduces stock and
   sends receipts. The second call sees it's already done.
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/send_notifications.php';

/** Fetch one order (with its items) by a column: 'id' or 'razorpay_order_id' */
function find_order(string $column, $value): ?array
{
    if (!in_array($column, ['id', 'razorpay_order_id', 'order_ref'], true)) {
        throw new InvalidArgumentException('Bad column');
    }
    $stmt = db()->prepare("SELECT * FROM orders WHERE $column = ? LIMIT 1");
    $stmt->execute([$value]);
    $order = $stmt->fetch();
    if (!$order) return null;

    $items = db()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
    $items->execute([$order['id']]);
    $order['items'] = $items->fetchAll();
    return $order;
}

/**
 * Mark an order as paid and take the fish out of stock.
 *
 * @return array ['newly_paid' => bool, 'oversold' => string[]]
 *   newly_paid = false means it was already paid before (nothing done).
 *   oversold   = fish names where two buyers paid for the last stock at
 *                the same moment. Rare, but the seller is alerted.
 */
function finalize_paid_order(array $order, string $paymentId, string $signature): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // The "AND payment_status = 'created'" makes this a one-time switch:
        // if the webhook and the browser arrive together, only one wins.
        $upd = $pdo->prepare(
            "UPDATE orders
                SET payment_status = 'paid', razorpay_payment_id = ?, razorpay_signature = ?, paid_at = ?
              WHERE id = ? AND payment_status IN ('created', 'failed')"
        );
        $upd->execute([$paymentId, $signature, now_sql(), $order['id']]);

        if ($upd->rowCount() === 0) {
            $pdo->rollBack();
            return ['newly_paid' => false, 'oversold' => []];
        }

        // Reduce stock. The "stock_qty >= ?" check stops stock going negative.
        $oversold = [];
        $take = $pdo->prepare('UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?');
        $zero = $pdo->prepare('UPDATE products SET stock_qty = 0 WHERE id = ?');
        foreach ($order['items'] as $item) {
            $take->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
            if ($take->rowCount() === 0) {
                $zero->execute([$item['product_id']]);
                $oversold[] = $item['product_name'];
            }
        }

        $pdo->commit();
        return ['newly_paid' => true, 'oversold' => $oversold];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Send customer receipts (email + WhatsApp) and the seller alert,
 * then remember which ones went through.
 * @return array ['email_sent' => bool, 'whatsapp_sent' => bool]
 */
function notify_order_paid(int $orderId, array $oversold = []): array
{
    $order = find_order('id', $orderId);
    if (!$order) return ['email_sent' => false, 'whatsapp_sent' => false];

    $emailOk = send_email_receipt($order);
    $waOk    = send_whatsapp_receipt($order);
    send_seller_alert($order, $oversold);

    db()->prepare('UPDATE orders SET email_sent = ?, whatsapp_sent = ? WHERE id = ?')
        ->execute([$emailOk ? 1 : 0, $waOk ? 1 : 0, $orderId]);

    return ['email_sent' => $emailOk, 'whatsapp_sent' => $waOk];
}

/** What the browser's success screen needs (no private payment data) */
function order_public_view(array $order): array
{
    return [
        'success'       => true,
        'order_ref'     => $order['order_ref'],
        'total_amount'  => (float) $order['total_amount'],
        'fulfilment'    => $order['fulfilment'],
        'slot'          => $order['delivery_slot'],
        'refund_promise' => society_refund_pending($order) ? society_refund_promise() : '',
        'email_sent'    => (bool) $order['email_sent'],
        'whatsapp_sent' => (bool) $order['whatsapp_sent'],
        'items'         => array_map(fn ($i) => [
            'product_name' => $i['product_name'],
            'cut_type'     => $i['cut_type'],
            'quantity'     => (float) $i['quantity'],
            'unit'         => $i['unit'],
            'line_total'   => (float) $i['line_total'],
        ], $order['items']),
    ];
}
