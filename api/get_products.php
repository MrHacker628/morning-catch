<?php
/* =====================================================================
   GET api/get_products.php
   Returns today's catch + shop settings for script.js.

   Reply:
   {
     "success": true,
     "products": [ {id, name, local_name, ..., price, stock_qty, ...}, ... ],
     "settings": { SHOP_NAME, LANDED_AT, CUTOFF, DELIVERY_FEE,
                   FREE_DELIVERY_ABOVE, SLOTS, BOOKING_OPEN, CLOSED_REASON }
   }
   The settings keys match the CONFIG object at the top of script.js.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$products = db()->query(
    'SELECT id, name, local_name, description, category, unit, price, min_qty, step_qty,
            stock_qty, initial_stock, cut_options, image_url, badge
       FROM products
      WHERE is_available = 1
      ORDER BY sort_order, id'
)->fetchAll();

// MySQL returns DECIMAL as strings — send real numbers to the browser
foreach ($products as &$p) {
    foreach (['id', 'price', 'min_qty', 'step_qty', 'stock_qty', 'initial_stock'] as $k) {
        $p[$k] = $k === 'id' ? (int) $p[$k] : (float) $p[$k];
    }
}
unset($p);

$s = shop_settings();
[$open, $reason] = booking_status();

json_out([
    'success'  => true,
    'products' => $products,
    'settings' => [
        'SHOP_NAME'           => cfg('shop.name', 'Morning Catch'),
        'LANDED_AT'           => $s['landed_at'],
        'CUTOFF'              => $s['cutoff'],
        'DELIVERY_FEE'        => $s['delivery_fee'],
        'FREE_DELIVERY_ABOVE' => $s['free_delivery_above'],
        'SLOTS'               => cfg('shop.slots'),
        'BOOKING_OPEN'        => $open,
        'CLOSED_REASON'       => $reason,
        'IGNORE_CUTOFF'       => (bool) cfg('shop.ignore_cutoff', false),
    ],
]);
