<?php
/* =====================================================================
   POST api/create_order.php
   Step 1 of payment. Called by script.js when the buyer taps "Pay".

   Receives (JSON):
   {
     "customer": { name, phone, email, address, notes, fulfilment, slot },
     "items":    [ { product_id, qty, cut }, ... ]
   }

   What it does:
     1. Validates every field again (never trust the browser).
     2. Looks up REAL prices and stock from the database.
     3. Saves the order as 'created' (not paid yet).
     4. Asks Razorpay for an order ID for the exact total.
     5. Replies with what Razorpay Checkout needs to open.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/razorpay.php';

$in = json_in();

/* ---------------------------------------------------------------------
   1. Is the shop open?
   --------------------------------------------------------------------- */
[$open, $reason] = booking_status();
if (!$open) json_error($reason, 409);

/* ---------------------------------------------------------------------
   2. Validate the customer
   --------------------------------------------------------------------- */
$c = is_array($in['customer'] ?? null) ? $in['customer'] : [];

$name       = trim((string) ($c['name'] ?? ''));
$email      = trim((string) ($c['email'] ?? ''));
$address    = trim((string) ($c['address'] ?? ''));
$notes      = trim((string) ($c['notes'] ?? ''));
$fulfilment = ($c['fulfilment'] ?? '') === 'pickup' ? 'pickup' : 'delivery';
$slot       = (string) ($c['slot'] ?? '');

// Phone: accept 9876543210 / +91 98765 43210 / 09876543210 → store as 919876543210
$phone = preg_replace('/\D/', '', (string) ($c['phone'] ?? ''));
if (strlen($phone) === 12 && str_starts_with($phone, '91')) $phone = substr($phone, 2);
if (strlen($phone) === 11 && str_starts_with($phone, '0'))  $phone = substr($phone, 1);

if (mb_strlen($name) < 2 || mb_strlen($name) > 100)            json_error('Please enter your name.');
if (!preg_match('/^[6-9]\d{9}$/', $phone))                      json_error('Please enter a valid 10-digit Indian mobile number.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) json_error('Please enter a valid email address.');
if ($fulfilment === 'delivery' && (mb_strlen($address) < 10 || mb_strlen($address) > 400)) json_error('Please enter your full delivery address.');
if (!in_array($slot, (array) cfg("shop.slots.$fulfilment", []), true)) json_error('Please choose a time slot.');
if (mb_strlen($notes) > 200) $notes = mb_substr($notes, 0, 200);
if ($fulfilment === 'pickup') $address = '';

/* ---------------------------------------------------------------------
   3. Validate items and price them from the DATABASE
   --------------------------------------------------------------------- */
$items = is_array($in['items'] ?? null) ? $in['items'] : [];
if (count($items) < 1 || count($items) > 20) json_error('Your basket is empty.');

$ids = array_values(array_unique(array_map(fn ($i) => (int) ($i['product_id'] ?? 0), $items)));
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = db()->prepare("SELECT * FROM products WHERE is_available = 1 AND id IN ($placeholders)");
$stmt->execute($ids);
$products = [];
foreach ($stmt->fetchAll() as $p) $products[(int) $p['id']] = $p;

$lines     = [];
$totalQty  = [];   // product_id => qty across all cuts (for the stock check)
$subtotal  = 0.0;

foreach ($items as $i) {
    $pid = (int) ($i['product_id'] ?? 0);
    $qty = round((float) ($i['qty'] ?? 0), 2);
    $cut = trim((string) ($i['cut'] ?? ''));

    $p = $products[$pid] ?? null;
    if (!$p) json_error('One of the fish in your basket is no longer available. Please refresh the page.', 409);

    $min  = (float) $p['min_qty'];
    $step = (float) $p['step_qty'];
    if ($qty < $min) json_error("Minimum for {$p['name']} is " . qty_label($min, $p['unit']) . '.');
    // quantity must be a multiple of the step (0.5 kg, 1 piece…)
    if ($step > 0 && abs(round($qty / $step) * $step - $qty) > 0.001) json_error("Please choose a valid quantity for {$p['name']}.");

    // The chosen cut decides the preparation charge. Taken from the
    // database, never from the browser, so nobody can ask for a cleaned
    // fish at the price of a whole one.
    $cutFees = cut_fee_map((string) $p['cut_options']);
    if (!array_key_exists($cut, $cutFees)) $cut = (string) array_key_first($cutFees);
    $cutFee = round((float) $cutFees[$cut], 2);

    $totalQty[$pid] = round(($totalQty[$pid] ?? 0) + $qty, 2);

    $unitPrice = round((float) $p['price'], 2);
    $lineTotal = round($qty * ($unitPrice + $cutFee), 2);
    $subtotal += $lineTotal;

    $lines[] = [
        'product_id' => $pid,
        'name'       => $p['name'],
        'unit'       => $p['unit'],
        'cut'        => $cut,
        'cut_fee'    => $cutFee,
        'qty'        => $qty,
        'unit_price' => $unitPrice,
        'line_total' => $lineTotal,
    ];
}

foreach ($totalQty as $pid => $qty) {
    $p = $products[$pid];
    if ($qty > (float) $p['stock_qty'] + 0.001) {
        $left = (float) $p['stock_qty'];
        json_error($left > 0
            ? "Only " . qty_label($left, $p['unit']) . " of {$p['name']} is left. Please reduce the quantity."
            : "Sorry, {$p['name']} just sold out. Please remove it from your basket.", 409);
    }
}

$subtotal    = round($subtotal, 2);
$deliveryFee = delivery_fee_for($subtotal, $fulfilment);
$total       = round($subtotal + $deliveryFee, 2);
if ($total < 1) json_error('Order total is too small.');

/* ---------------------------------------------------------------------
   4. Save the order (status 'created') + items
   --------------------------------------------------------------------- */
$pdo = db();
$pdo->beginTransaction();
try {
    // Temporary unique ref; replaced with MC-ddmmyy-0042 once we know the ID
    $tmpRef = 'TMP-' . bin2hex(random_bytes(8));

    $pdo->prepare(
        'INSERT INTO orders
            (order_ref, customer_name, customer_phone, customer_email, fulfilment, delivery_address,
             delivery_slot, notes, subtotal, delivery_fee, total_amount, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $tmpRef, $name, '91' . $phone, $email, $fulfilment, $address ?: null,
        $slot, $notes ?: null, $subtotal, $deliveryFee, $total, now_sql(),
    ]);
    $orderId  = (int) $pdo->lastInsertId();
    $orderRef = sprintf('MC-%s-%04d', date('dmy'), $orderId);
    $pdo->prepare('UPDATE orders SET order_ref = ? WHERE id = ?')->execute([$orderRef, $orderId]);

    $ins = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, product_name, unit, cut_type, cut_fee, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($lines as $l) {
        $ins->execute([$orderId, $l['product_id'], $l['name'], $l['unit'], $l['cut'], $l['cut_fee'], $l['qty'], $l['unit_price'], $l['line_total']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

/* ---------------------------------------------------------------------
   5. Create the Razorpay order for the exact amount (in paise)
   --------------------------------------------------------------------- */
$amountPaise = (int) round($total * 100);
try {
    $rzp = rzp_create_order($amountPaise, $orderRef, ['order_ref' => $orderRef, 'phone' => '91' . $phone]);
} catch (RuntimeException $e) {
    log_msg('create_order: ' . $e->getMessage(), ['order_ref' => $orderRef]);
    $pdo->prepare("UPDATE orders SET payment_status = 'failed' WHERE id = ?")->execute([$orderId]);
    json_error('Payment could not be started. Please try again in a minute.', 502);
}

$pdo->prepare('UPDATE orders SET razorpay_order_id = ? WHERE id = ?')->execute([$rzp['id'], $orderId]);

/* ---------------------------------------------------------------------
   6. Reply — everything Razorpay Checkout needs (no secrets!)
   --------------------------------------------------------------------- */
json_out([
    'success'           => true,
    'key_id'            => cfg('razorpay.key_id'),   // public key, safe to share
    'razorpay_order_id' => $rzp['id'],
    'amount'            => $amountPaise,
    'currency'          => 'INR',
    'order_ref'         => $orderRef,
]);
