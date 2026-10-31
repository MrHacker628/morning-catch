<?php
/* =====================================================================
   societies.php — apartment-society batch delivery

   Delivering one 500 g order to a 20-flat block costs more than the
   order earns, and the fish is warmer by the time it arrives. So flats
   order individually, and once enough of them in the same society have
   ordered for the same day, the van makes a single stop and nobody pays
   for delivery.

   The threshold lives in the config (shop.society_threshold). Set it to
   1 and every society order gets free delivery from the first flat.
   ===================================================================== */

declare(strict_types=1);

/** How many flats must order before delivery is free for that society. */
function society_threshold(): int
{
    return max(1, (int) cfg('shop.society_threshold', 3));
}

/** The one evening window the batch van runs in. */
function society_slot(): string
{
    return trim((string) cfg('shop.society_slot', '5:00 – 6:30 PM'));
}

/** Societies the seller currently delivers to, in display order. */
function societies_active(): array
{
    $pdo = db_optional();
    if ($pdo === null) return [];
    try {
        return $pdo->query(
            'SELECT id, name, area FROM societies WHERE is_active = 1 ORDER BY sort_order, name'
        )->fetchAll();
    } catch (PDOException $e) {
        log_msg('societies table not readable: ' . $e->getMessage());
        return [];
    }
}

/**
 * How many DIFFERENT flats in each society have a paid order for a given
 * day. Counting distinct flats, not orders, so one flat ordering three
 * times cannot unlock free delivery for the building on its own.
 *
 * @return array  society_id => number of flats
 */
function society_flat_counts(?string $date = null): array
{
    $pdo = db_optional();
    if ($pdo === null) return [];

    $date ??= date('Y-m-d');
    try {
        $st = $pdo->prepare(
            'SELECT society_id, COUNT(DISTINCT flat_number) AS flats
               FROM orders
              WHERE society_id IS NOT NULL
                AND payment_status = \'paid\'
                AND order_status <> \'cancelled\'
                AND paid_at >= ? AND paid_at < ?
           GROUP BY society_id'
        );
        $st->execute([$date . ' 00:00:00', $date . ' 23:59:59']);

        $counts = [];
        foreach ($st->fetchAll() as $row) $counts[(int) $row['society_id']] = (int) $row['flats'];
        return $counts;
    } catch (PDOException $e) {
        log_msg('society counts failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Does an order from this flat, placed now, get free delivery?
 *
 * The flat being counted is the one ordering: if they have already
 * ordered today they do not push the count up a second time.
 */
function society_delivery_is_free(int $societyId, string $flat, ?string $date = null): bool
{
    $pdo = db_optional();
    if ($pdo === null) return false;

    $date ??= date('Y-m-d');
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(DISTINCT flat_number)
               FROM orders
              WHERE society_id = ?
                AND payment_status = \'paid\'
                AND order_status <> \'cancelled\'
                AND paid_at >= ? AND paid_at < ?
                AND flat_number <> ?'
        );
        $st->execute([$societyId, $date . ' 00:00:00', $date . ' 23:59:59', $flat]);
        $others = (int) $st->fetchColumn();
    } catch (PDOException $e) {
        log_msg('society threshold check failed: ' . $e->getMessage());
        return false;
    }

    return ($others + 1) >= society_threshold();
}

/**
 * Societies as the shop page needs them: name, area, how many flats have
 * ordered today and how many more are needed.
 */
function societies_public(): array
{
    $counts    = society_flat_counts();
    $threshold = society_threshold();

    return array_map(static function (array $s) use ($counts, $threshold): array {
        $ordered = $counts[(int) $s['id']] ?? 0;
        return [
            'id'      => (int) $s['id'],
            'name'    => $s['name'],
            'area'    => $s['area'],
            'ordered' => $ordered,
            'needed'  => max(0, $threshold - $ordered),
        ];
    }, societies_active());
}

/**
 * Did this order pay a delivery fee that the society rule may hand back?
 * True while the building has not yet reached the threshold.
 */
function society_refund_pending(array $order): bool
{
    return !empty($order['society_id'])
        && (float) ($order['delivery_fee'] ?? 0) > 0
        && (int) ($order['delivery_waived'] ?? 0) === 0;
}

/** The promise, in one place so the receipt and the screen say the same thing. */
function society_refund_promise(): string
{
    $n = society_threshold();
    return "Delivery is on us once {$n} flats in your building order on the same day. "
         . "If that happens today, this delivery fee comes back to you on the drop.";
}

/**
 * Orders today whose delivery fee the seller still owes back: they paid,
 * and their society reached the threshold afterwards.
 */
function society_refunds_due(?string $date = null): array
{
    $pdo = db_optional();
    if ($pdo === null) return [];

    $date ??= date('Y-m-d');
    $threshold = society_threshold();
    try {
        $st = $pdo->prepare(
            'SELECT o.id, o.order_ref, o.flat_number, o.delivery_fee, o.customer_name,
                    o.customer_phone, s.name AS society_name
               FROM orders o
               JOIN societies s ON s.id = o.society_id
              WHERE o.society_id IS NOT NULL
                AND o.payment_status = \'paid\'
                AND o.order_status <> \'cancelled\'
                AND o.delivery_fee > 0
                AND o.delivery_waived = 0
                AND o.paid_at >= :from1 AND o.paid_at < :to1
                AND (SELECT COUNT(DISTINCT x.flat_number)
                       FROM orders x
                      WHERE x.society_id = o.society_id
                        AND x.payment_status = \'paid\'
                        AND x.order_status <> \'cancelled\'
                        AND x.paid_at >= :from2 AND x.paid_at < :to2) >= :threshold
           ORDER BY s.name, o.flat_number'
        );
        $st->bindValue(':from1', $date . ' 00:00:00');
        $st->bindValue(':to1',   $date . ' 23:59:59');
        $st->bindValue(':from2', $date . ' 00:00:00');
        $st->bindValue(':to2',   $date . ' 23:59:59');
        // Bound as an integer on purpose: bound as a string, SQLite ranks
        // every number below every text value and the comparison is always
        // false. MySQL coerces and hides it, so this only shows up on one
        // database — exactly the kind of bug to pin down rather than inherit.
        $st->bindValue(':threshold', $threshold, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (PDOException $e) {
        log_msg('society refunds query failed: ' . $e->getMessage());
        return [];
    }
}
