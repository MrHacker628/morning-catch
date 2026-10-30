<?php
/* =====================================================================
   settings.php — shop settings the seller can change from /admin
   (stored in the `settings` table), falling back to the config file.
   ===================================================================== */

declare(strict_types=1);

/** All shop settings, merged: config defaults ← database overrides */
function shop_settings(): array
{
    static $settings = null;
    if ($settings !== null) return $settings;

    $settings = [
        'landed_at'           => (string) cfg('shop.landed_at', '5:45 AM'),
        'cutoff'              => (string) cfg('shop.cutoff', '10:30'),
        'delivery_fee'        => (float)  cfg('shop.delivery_fee', 40),
        'free_delivery_above' => (float)  cfg('shop.free_delivery_above', 0),
        'booking_open'        => true,
    ];

    try {
        $pdo = db_optional();
        if ($pdo === null) return $settings;      // database down — config defaults are fine
        foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $row) {
            $k = $row['setting_key'];
            if (!array_key_exists($k, $settings)) continue;
            $v = $row['setting_value'];
            $settings[$k] = match ($k) {
                'delivery_fee', 'free_delivery_above' => (float) $v,
                'booking_open' => $v === '1',
                default => $v,
            };
        }
    } catch (PDOException $e) {
        // settings table missing — config defaults are fine
        log_msg('settings table not readable: ' . $e->getMessage());
    }
    return $settings;
}

function save_setting(string $key, string $value): void
{
    // REPLACE works on MySQL (and SQLite) as "insert or overwrite"
    db()->prepare('REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute([$key, $value]);
}

/** Is the shop taking bookings right now? Returns [bool open, string reason] */
function booking_status(): array
{
    $s = shop_settings();
    if (!$s['booking_open']) return [false, 'Bookings are closed today.'];
    if (cfg('shop.ignore_cutoff', false)) return [true, ''];

    if (preg_match('/^(\d{1,2}):(\d{2})$/', $s['cutoff'], $m)) {
        $cutoff = (int) $m[1] * 60 + (int) $m[2];
        $nowMin = (int) date('G') * 60 + (int) date('i');
        if ($nowMin >= $cutoff) return [false, 'Bookings for today have closed. See you tomorrow morning!'];
    }
    return [true, ''];
}

/** Delivery fee for a given subtotal — must match script.js */
function delivery_fee_for(float $subtotal, string $fulfilment): float
{
    $s = shop_settings();
    if ($fulfilment === 'pickup' || $subtotal <= 0) return 0.0;
    if ($s['free_delivery_above'] > 0 && $subtotal >= $s['free_delivery_above']) return 0.0;
    return round($s['delivery_fee'], 2);
}
