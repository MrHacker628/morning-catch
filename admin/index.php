<?php
/* =====================================================================
   admin/index.php — the seller's page (yourdomain.com/admin)

   Three tabs, built for a phone:
     Orders   → today's paid orders = packing list. Change status,
                call/WhatsApp the buyer, resend a receipt.
     Catch    → update this morning's prices and stock, hide/show fish,
                add a new fish.
     Settings → landing time, booking cutoff, delivery fee, and an
                "open / closed today" switch (rough sea, no catch…).

   Login uses the password in the config file ('admin' → 'password').
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/orders.php';
require __DIR__ . '/../includes/photos.php';

/* ---------------------------------------------------------------------
   Session + security
   --------------------------------------------------------------------- */
session_name('mc_admin');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
$isAdmin = !empty($_SESSION['is_admin']);

function redirect(string $query = ''): void
{
    header('Location: ./' . ($query ? '?' . $query : ''));
    exit;
}

function flash(string $msg): void { $_SESSION['flash'] = $msg; }

/** The photo a fish currently has, so we can delete it when it is replaced. */
function current_photo(int $id): ?string
{
    $st = db()->prepare('SELECT image_url FROM products WHERE id = ?');
    $st->execute([$id]);
    $url = $st->fetchColumn();
    return $url === false || $url === null ? null : (string) $url;
}

/* ---------------------------------------------------------------------
   POST actions
   --------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $expected = (string) cfg('admin.password', '');
        $given    = (string) ($_POST['password'] ?? '');
        if ($expected !== '' && $expected !== 'CHANGE_ME_to_a_long_password' && hash_equals($expected, $given)) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            redirect();
        }
        sleep(1);   // slows down password guessing
        flash('Wrong password.');
        redirect();
    }

    // Everything below needs a logged-in seller + a valid form token
    if (!$isAdmin || !hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        flash('Session expired. Please log in again.');
        redirect();
    }

    $tab = $_POST['tab'] ?? 'orders';
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date'] ?? '') ? $_POST['date'] : '';

    switch ($action) {
        case 'logout':
            $_SESSION = [];
            session_destroy();
            redirect();

        case 'order_status':
            $allowed = ['pending', 'kept_aside', 'out_for_delivery', 'completed', 'cancelled'];
            $status = $_POST['status'] ?? '';
            if (in_array($status, $allowed, true)) {
                db()->prepare('UPDATE orders SET order_status = ? WHERE id = ?')->execute([$status, (int) $_POST['order_id']]);
                flash('Order updated.');
            }
            break;

        case 'resend':
            $order = find_order('id', (int) $_POST['order_id']);
            if ($order && $order['payment_status'] === 'paid') {
                $r = notify_order_paid((int) $order['id']);
                flash('Receipt resent — email ' . ($r['email_sent'] ? '✓' : '✗') . ', WhatsApp ' . ($r['whatsapp_sent'] ? '✓' : '✗'));
            }
            break;

        case 'save_products':
            $upd = db()->prepare(
                'UPDATE products SET name = ?, local_name = ?, price = ?, stock_qty = ?, initial_stock = ?,
                        badge = ?, is_available = ?, sort_order = ?, cut_options = ?, description = ?
                  WHERE id = ?'
            );
            foreach ((array) ($_POST['p'] ?? []) as $id => $p) {
                $stock   = max(0, (float) ($p['stock_qty'] ?? 0));
                $landed  = max($stock, (float) ($p['initial_stock'] ?? 0));
                $upd->execute([
                    trim((string) $p['name']) ?: 'Fish',
                    trim((string) ($p['local_name'] ?? '')) ?: null,
                    max(0, round((float) $p['price'], 2)),
                    $stock,
                    $landed,
                    trim((string) ($p['badge'] ?? '')) ?: null,
                    isset($p['is_available']) ? 1 : 0,
                    (int) ($p['sort_order'] ?? 0),
                    trim((string) ($p['cut_options'] ?? '')) ?: 'Whole',
                    trim((string) ($p['description'] ?? '')) ?: null,
                    (int) $id,
                ]);
            }

            // Photos: one optional file per fish, plus a "remove" tick
            $setPhoto = db()->prepare('UPDATE products SET image_url = ? WHERE id = ?');
            $photoErrors = [];
            foreach ((array) ($_POST['p'] ?? []) as $id => $p) {
                $id   = (int) $id;
                $file = $_FILES['photo_' . $id] ?? null;

                if (!empty($p['remove_photo'])) {
                    photo_delete(current_photo($id));
                    $setPhoto->execute([null, $id]);
                    continue;
                }
                if (!photo_was_chosen($file)) continue;

                [$url, $error] = photo_store($file, (string) ($p['name'] ?? 'fish'));
                if ($url === null) {
                    $photoErrors[] = trim((string) ($p['name'] ?? 'A fish')) . ': ' . $error;
                    continue;
                }
                photo_delete(current_photo($id));      // the one it replaces
                $setPhoto->execute([$url, $id]);
            }

            flash($photoErrors ? "Catch saved, but: " . implode(' ', $photoErrors) : "Today's catch saved.");
            break;

        case 'add_product':
            $unit  = ($_POST['unit'] ?? 'kg') === 'piece' ? 'piece' : 'kg';
            $stock = max(0, (float) ($_POST['stock_qty'] ?? 0));
            $name  = trim((string) ($_POST['name'] ?? ''));
            if ($name !== '' && (float) ($_POST['price'] ?? 0) > 0) {
                db()->prepare(
                    'INSERT INTO products (name, local_name, description, category, unit, price, min_qty, step_qty,
                                           stock_qty, initial_stock, cut_options, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $name,
                    trim((string) ($_POST['local_name'] ?? '')) ?: null,
                    trim((string) ($_POST['description'] ?? '')) ?: null,
                    ($_POST['category'] ?? '') === 'shellfish' ? 'shellfish' : 'fish',
                    $unit,
                    round((float) $_POST['price'], 2),
                    $unit === 'kg' ? 0.5 : 1,
                    $unit === 'kg' ? 0.5 : 1,
                    $stock, $stock,
                    trim((string) ($_POST['cut_options'] ?? '')) ?: 'Whole,Cleaned',
                    99,
                ]);
                $newId = (int) db()->lastInsertId();
                $file   = $_FILES['photo'] ?? null;
                if ($newId > 0 && photo_was_chosen($file)) {
                    [$url, $error] = photo_store($file, $name);
                    if ($url !== null) {
                        db()->prepare('UPDATE products SET image_url = ? WHERE id = ?')->execute([$url, $newId]);
                    } else {
                        flash("$name added, but the photo did not work: $error");
                        break;
                    }
                }
                flash("$name added.");
            } else {
                flash('Please give the fish a name and a price.');
            }
            break;

        case 'delete_product':
            $id   = (int) ($_POST['product_id'] ?? 0);
            $name = trim((string) ($_POST['product_name'] ?? 'That fish'));

            // A fish that has been ordered must stay: order_items points at it,
            // and deleting it would tear a hole in the seller's past receipts.
            $used = db()->prepare('SELECT COUNT(*) FROM order_items WHERE product_id = ?');
            $used->execute([$id]);

            if ($id <= 0) {
                flash('Could not work out which fish to remove.');
            } elseif ((int) $used->fetchColumn() > 0) {
                flash("$name has been ordered before, so it cannot be deleted — past receipts would lose it. Untick \"On sale\" to take it off the shop instead.");
            } else {
                photo_delete(current_photo($id));
                db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
                flash("$name removed.");
            }
            break;

        case 'save_settings':
            save_setting('landed_at', trim((string) ($_POST['landed_at'] ?? '')) ?: '5:45 AM');
            $cutoff = (string) ($_POST['cutoff'] ?? '10:30');
            save_setting('cutoff', preg_match('/^\d{2}:\d{2}$/', $cutoff) ? $cutoff : '10:30');
            save_setting('delivery_fee', (string) max(0, (float) ($_POST['delivery_fee'] ?? 0)));
            save_setting('free_delivery_above', (string) max(0, (float) ($_POST['free_delivery_above'] ?? 0)));
            save_setting('booking_open', isset($_POST['booking_open']) ? '1' : '0');
            flash('Settings saved.');
            break;
    }
    redirect('tab=' . urlencode($tab) . ($date ? '&date=' . $date : ''));
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

/* ---------------------------------------------------------------------
   Data for the page
   --------------------------------------------------------------------- */
$tab  = in_array($_GET['tab'] ?? '', ['orders', 'catch', 'settings'], true) ? $_GET['tab'] : 'orders';
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');

$statusLabels = [
    'pending'          => ['New',              'bg-sun-light text-ink'],
    'kept_aside'       => ['Kept aside',       'bg-sea-light text-sea'],
    'out_for_delivery' => ['Out for delivery', 'bg-coral-light text-coral-dark'],
    'completed'        => ['Done',             'bg-ink/10 text-ink-soft'],
    'cancelled'        => ['Cancelled',        'bg-ink/10 text-ink-soft line-through'],
];

if ($isAdmin) {
    // Paid orders for the chosen day, with items
    $stmt = db()->prepare(
        "SELECT * FROM orders
          WHERE payment_status = 'paid' AND paid_at >= ? AND paid_at < ?
          ORDER BY delivery_slot, id"
    );
    $stmt->execute([$date . ' 00:00:00', date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00']);
    $orders = $stmt->fetchAll();
    $itemsBy = [];
    if ($orders) {
        $ids = array_column($orders, 'id');
        $q = db()->prepare('SELECT * FROM order_items WHERE order_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
        $q->execute($ids);
        foreach ($q->fetchAll() as $it) $itemsBy[$it['order_id']][] = $it;
    }
    $revenue = array_sum(array_map(fn ($o) => $o['order_status'] === 'cancelled' ? 0 : (float) $o['total_amount'], $orders));

    // Packing totals: how much of each fish to set aside
    $packing = [];
    foreach ($orders as $o) {
        if ($o['order_status'] === 'cancelled') continue;
        foreach ($itemsBy[$o['id']] ?? [] as $it) {
            $k = $it['product_name'];
            $packing[$k] = ['qty' => ($packing[$k]['qty'] ?? 0) + (float) $it['quantity'], 'unit' => $it['unit']];
        }
    }

    $products = db()->query('SELECT * FROM products ORDER BY sort_order, id')->fetchAll();
    $settings = shop_settings();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow" />
  <title>Seller · <?= h(cfg('shop.name', 'Morning Catch')) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <script>
    tailwind.config = { theme: { extend: {
      colors: {
        ink: { DEFAULT: '#0B2733', soft: '#33505C' }, sea: { DEFAULT: '#0E6B74', light: '#DDF0EE' },
        coral: { DEFAULT: '#F2643D', dark: '#D24E2A', light: '#FDE7DF' }, sun: { DEFAULT: '#F5B53F', light: '#FDF1D6' },
        sand: { DEFAULT: '#FBF6EE', dark: '#F1E8DA' },
      },
      fontFamily: { display: ['Fraunces', 'Georgia', 'serif'], sans: ['"Plus Jakarta Sans"', 'system-ui', 'sans-serif'] },
      boxShadow: { card: '0 1px 2px rgba(11,39,51,.06), 0 8px 24px -12px rgba(11,39,51,.18)' },
    } } };
  </script>
  <style>
    .field { width: 100%; border-radius: .75rem; border: 0; background: #fff; padding: .6rem .75rem; font-size: 1rem; box-shadow: inset 0 0 0 1px rgba(11,39,51,.12); }
    .field:focus { outline: none; box-shadow: inset 0 0 0 2px #0E6B74; }
    .lbl { display: block; font-size: .75rem; font-weight: 600; color: #33505C; margin-bottom: .25rem; }
  </style>
</head>
<body class="min-h-screen bg-sand font-sans text-ink antialiased">

<?php if (!$isAdmin): ?>
  <!-- ============================ LOGIN ============================ -->
  <main class="grid min-h-screen place-items-center px-4">
    <form method="post" class="w-full max-w-sm rounded-3xl bg-white p-6 shadow-card">
      <p class="text-xs font-bold uppercase tracking-[0.18em] text-coral"><?= h(cfg('shop.name', 'Morning Catch')) ?></p>
      <h1 class="mt-1 font-display text-3xl font-bold">Seller login</h1>
      <?php if ($flash): ?><p class="mt-4 rounded-xl bg-coral-light px-3 py-2 text-sm font-medium text-coral-dark"><?= h($flash) ?></p><?php endif; ?>
      <input type="hidden" name="action" value="login" />
      <label class="lbl mt-5" for="password">Password</label>
      <input class="field" id="password" name="password" type="password" autocomplete="current-password" required autofocus />
      <button class="mt-4 w-full rounded-2xl bg-ink py-3 font-semibold text-sand hover:bg-sea">Log in</button>
    </form>
  </main>

<?php else: ?>
  <!-- ============================ HEADER ============================ -->
  <header class="sticky top-0 z-20 bg-ink text-sand">
    <div class="mx-auto flex max-w-4xl items-center justify-between px-4 py-3">
      <div>
        <p class="font-display text-lg font-bold leading-tight"><?= h(cfg('shop.name', 'Morning Catch')) ?></p>
        <p class="text-[11px] text-sand/60">Seller dashboard · <a class="underline" href="../" target="_blank">view shop</a></p>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>" /><input type="hidden" name="action" value="logout" />
        <button class="rounded-full bg-sand/10 px-3 py-1.5 text-sm font-semibold hover:bg-sand/20">Log out</button>
      </form>
    </div>
    <nav class="mx-auto flex max-w-4xl gap-1 px-4 pb-2">
      <?php foreach (['orders' => 'Orders', 'catch' => "Today's catch", 'settings' => 'Settings'] as $k => $label): ?>
        <a href="?tab=<?= $k ?>" class="rounded-full px-4 py-2 text-sm font-semibold <?= $tab === $k ? 'bg-sand text-ink' : 'text-sand/80 hover:bg-sand/10' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </nav>
  </header>

  <main class="mx-auto max-w-4xl px-4 pb-16 pt-5">
    <?php if ($flash): ?>
      <p class="mb-4 rounded-2xl bg-sea-light px-4 py-3 text-sm font-semibold text-sea"><?= h($flash) ?></p>
    <?php endif; ?>

    <?php if (!$settings['booking_open']): ?>
      <p class="mb-4 rounded-2xl bg-coral-light px-4 py-3 text-sm font-semibold text-coral-dark">Bookings are switched OFF. Turn them on in Settings.</p>
    <?php endif; ?>

    <?php if ($tab === 'orders'): ?>
      <!-- ============================ ORDERS ============================ -->
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 class="font-display text-3xl font-bold"><?= $date === date('Y-m-d') ? "Today's orders" : 'Orders' ?></h1>
          <p class="text-sm text-ink-soft"><?= count($orders) ?> paid · <?= h(inr($revenue)) ?></p>
        </div>
        <form method="get" class="flex items-center gap-2">
          <input type="hidden" name="tab" value="orders" />
          <input class="field !w-auto" type="date" name="date" value="<?= h($date) ?>" onchange="this.form.submit()" />
        </form>
      </div>

      <?php if ($packing): ?>
        <section class="mt-5 rounded-3xl bg-white p-4 shadow-card">
          <h2 class="text-sm font-bold uppercase tracking-wider text-ink-soft">Set aside in total</h2>
          <div class="mt-2 flex flex-wrap gap-2">
            <?php foreach ($packing as $fish => $p): ?>
              <span class="rounded-full bg-sand px-3 py-1.5 text-sm"><strong><?= h($fish) ?></strong> · <?= h(qty_label($p['qty'], $p['unit'])) ?></span>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if (!$orders): ?>
        <p class="mt-6 rounded-3xl bg-white p-8 text-center text-ink-soft shadow-card">No paid orders for this day yet.</p>
      <?php endif; ?>

      <div class="mt-5 space-y-3">
        <?php foreach ($orders as $o): [$sLabel, $sClass] = $statusLabels[$o['order_status']]; ?>
          <article class="rounded-3xl bg-white p-4 shadow-card">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <p class="font-mono text-sm font-bold"><?= h($o['order_ref']) ?></p>
                <p class="text-lg font-semibold"><?= h($o['customer_name']) ?></p>
              </div>
              <span class="rounded-full px-3 py-1 text-xs font-bold <?= $sClass ?>"><?= $sLabel ?></span>
            </div>

            <p class="mt-1 text-sm font-semibold text-sea"><?= $o['fulfilment'] === 'pickup' ? '🏪 Pickup' : '🛵 Delivery' ?> · <?= h($o['delivery_slot']) ?></p>
            <?php if ($o['delivery_address']): ?><p class="mt-1 text-sm text-ink-soft"><?= nl2br(h($o['delivery_address'])) ?></p><?php endif; ?>
            <?php if ($o['notes']): ?><p class="mt-1 rounded-lg bg-sun-light px-2 py-1 text-sm">📝 <?= h($o['notes']) ?></p><?php endif; ?>

            <ul class="mt-3 divide-y divide-sand-dark text-sm">
              <?php foreach ($itemsBy[$o['id']] ?? [] as $it): ?>
                <li class="flex justify-between gap-2 py-1.5">
                  <span><strong><?= h($it['product_name']) ?></strong> · <?= h($it['cut_type']) ?> · <?= h(qty_label($it['quantity'], $it['unit'])) ?></span>
                  <span class="tabular-nums"><?= h(inr($it['line_total'])) ?></span>
                </li>
              <?php endforeach; ?>
              <li class="flex justify-between py-1.5 font-bold"><span>Paid</span><span class="tabular-nums"><?= h(inr($o['total_amount'])) ?></span></li>
            </ul>

            <div class="mt-3 flex flex-wrap items-center gap-2">
              <a href="https://wa.me/<?= h($o['customer_phone']) ?>" target="_blank" rel="noopener" class="rounded-full bg-[#25D366] px-3 py-2 text-xs font-bold text-white">WhatsApp</a>
              <a href="tel:+<?= h($o['customer_phone']) ?>" class="rounded-full bg-sand px-3 py-2 text-xs font-bold">Call +<?= h($o['customer_phone']) ?></a>

              <form method="post" class="flex items-center gap-1">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>" /><input type="hidden" name="action" value="order_status" />
                <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>" /><input type="hidden" name="tab" value="orders" /><input type="hidden" name="date" value="<?= h($date) ?>" />
                <select name="status" class="field !w-auto !py-1.5 text-sm" onchange="this.form.submit()" aria-label="Order status">
                  <?php foreach ($statusLabels as $k => [$label]): ?>
                    <option value="<?= $k ?>" <?= $o['order_status'] === $k ? 'selected' : '' ?>><?= $label ?></option>
                  <?php endforeach; ?>
                </select>
              </form>

              <form method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>" /><input type="hidden" name="action" value="resend" />
                <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>" /><input type="hidden" name="tab" value="orders" /><input type="hidden" name="date" value="<?= h($date) ?>" />
                <button class="rounded-full px-3 py-2 text-xs font-bold ring-1 ring-ink/15 hover:bg-sand">Resend receipt</button>
              </form>
              <span class="text-xs text-ink-soft">Email <?= $o['email_sent'] ? '✓' : '✗' ?> · WhatsApp <?= $o['whatsapp_sent'] ? '✓' : '✗' ?></span>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

    <?php elseif ($tab === 'catch'): ?>
      <!-- ============================ TODAY'S CATCH ============================ -->
      <h1 class="font-display text-3xl font-bold">Today's catch</h1>
      <p class="text-sm text-ink-soft">Update prices and stock each morning. "Left" goes down by itself as people pay.</p>
      <p class="mt-1 text-sm text-ink-soft">A real photo sells far more than a drawing — add one for each fish when you have a minute.</p>

      <form method="post" enctype="multipart/form-data" class="mt-5 space-y-3">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>" /><input type="hidden" name="action" value="save_products" /><input type="hidden" name="tab" value="catch" />
        <?php foreach ($products as $p): $id = (int) $p['id']; $u = $p['unit'] === 'kg' ? 'kg' : 'pcs'; ?>
          <fieldset class="rounded-3xl bg-white p-4 shadow-card <?= $p['is_available'] ? '' : 'opacity-60' ?>">
            <div class="flex items-center justify-between gap-3">
              <legend class="font-display text-xl font-bold"><?= h($p['name']) ?></legend>
              <label class="flex items-center gap-2 text-sm font-semibold">
                <input type="checkbox" name="p[<?= $id ?>][is_available]" class="h-5 w-5 rounded text-sea" <?= $p['is_available'] ? 'checked' : '' ?> /> On sale
              </label>
            </div>
            <div class="mt-3 grid grid-cols-3 gap-2">
              <div><label class="lbl" for="pr<?= $id ?>">Price ₹/<?= $p['unit'] === 'kg' ? 'kg' : 'pc' ?></label><input class="field" id="pr<?= $id ?>" name="p[<?= $id ?>][price]" type="number" step="0.01" min="0" inputmode="decimal" value="<?= h((string) (float) $p['price']) ?>" /></div>
              <div><label class="lbl" for="ls<?= $id ?>">Landed (<?= $u ?>)</label><input class="field" id="ls<?= $id ?>" name="p[<?= $id ?>][initial_stock]" type="number" step="0.5" min="0" inputmode="decimal" value="<?= h((string) (float) $p['initial_stock']) ?>" /></div>
              <div><label class="lbl" for="st<?= $id ?>">Left (<?= $u ?>)</label><input class="field" id="st<?= $id ?>" name="p[<?= $id ?>][stock_qty]" type="number" step="0.5" min="0" inputmode="decimal" value="<?= h((string) (float) $p['stock_qty']) ?>" /></div>
            </div>
            <!-- Photo: what the customer sees on the shop page -->
            <div class="mt-3 flex items-center gap-3 rounded-2xl bg-sand p-3">
              <?php if (!empty($p['image_url'])): ?>
                <img src="<?= h('../' . $p['image_url']) ?>" alt="" class="h-16 w-16 shrink-0 rounded-xl object-cover" />
              <?php else: ?>
                <span class="grid h-16 w-16 shrink-0 place-items-center rounded-xl bg-sand-dark text-ink-soft" aria-hidden="true">
                  <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="M21 16l-5-5-5 5-2-2-3 3"/></svg>
                </span>
              <?php endif; ?>
              <div class="min-w-0 flex-1">
                <label class="lbl" for="ph<?= $id ?>"><?= empty($p['image_url']) ? 'Add a photo' : 'Replace the photo' ?></label>
                <input class="field !py-2 text-sm" id="ph<?= $id ?>" name="photo_<?= $id ?>" type="file" accept="image/*" capture="environment" />
                <?php if (!empty($p['image_url'])): ?>
                  <label class="mt-1.5 flex items-center gap-2 text-xs font-medium text-ink-soft">
                    <input type="checkbox" name="p[<?= $id ?>][remove_photo]" value="1" class="h-4 w-4 rounded text-coral" /> Remove this photo
                  </label>
                <?php endif; ?>
              </div>
            </div>

            <details class="mt-3">
              <summary class="cursor-pointer text-sm font-semibold text-sea">More details</summary>
              <div class="mt-3 grid gap-2 sm:grid-cols-2">
                <div><label class="lbl" for="nm<?= $id ?>">Name</label><input class="field" id="nm<?= $id ?>" name="p[<?= $id ?>][name]" value="<?= h($p['name']) ?>" /></div>
                <div><label class="lbl" for="ln<?= $id ?>">Local name</label><input class="field" id="ln<?= $id ?>" name="p[<?= $id ?>][local_name]" value="<?= h($p['local_name']) ?>" /></div>
                <div class="sm:col-span-2"><label class="lbl" for="ds<?= $id ?>">Description</label><input class="field" id="ds<?= $id ?>" name="p[<?= $id ?>][description]" value="<?= h($p['description']) ?>" /></div>
                <div class="sm:col-span-2"><label class="lbl" for="ct<?= $id ?>">Cut options (comma separated)</label><input class="field" id="ct<?= $id ?>" name="p[<?= $id ?>][cut_options]" value="<?= h($p['cut_options']) ?>" /></div>
                <div><label class="lbl" for="bd<?= $id ?>">Badge (e.g. Just landed)</label><input class="field" id="bd<?= $id ?>" name="p[<?= $id ?>][badge]" value="<?= h($p['badge']) ?>" /></div>
                <div><label class="lbl" for="so<?= $id ?>">Position (lower = first)</label><input class="field" id="so<?= $id ?>" name="p[<?= $id ?>][sort_order]" type="number" value="<?= (int) $p['sort_order'] ?>" /></div>
              </div>
              <div class="mt-4 border-t border-ink/10 pt-3">
                <button type="submit" form="del<?= $id ?>"
                        class="text-sm font-semibold text-coral-dark underline hover:no-underline"
                        onclick="return confirm('Remove <?= h(addslashes($p['name'])) ?> from the shop for good? To just take it off sale for today, untick On sale instead.')">
                  Remove this fish permanently
                </button>
                <p class="mt-1 text-xs text-ink-soft">Only possible if nobody has ordered it. To hide it for today, untick "On sale".</p>
              </div>
            </details>
          </fieldset>
        <?php endforeach; ?>
        <button class="sticky bottom-3 w-full rounded-2xl bg-coral py-4 text-base font-bold text-white shadow-card hover:bg-coral-dark">Save today's catch</button>
      </form>

      <?php foreach ($products as $p): ?>
        <form method="post" id="del<?= (int) $p['id'] ?>" class="hidden">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
          <input type="hidden" name="action" value="delete_product" />
          <input type="hidden" name="tab" value="catch" />
          <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>" />
          <input type="hidden" name="product_name" value="<?= h($p['name']) ?>" />
        </form>
      <?php endforeach; ?>

      <form method="post" enctype="multipart/form-data" class="mt-8 rounded-3xl bg-white p-4 shadow-card">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>" /><input type="hidden" name="action" value="add_product" /><input type="hidden" name="tab" value="catch" />
        <h2 class="font-display text-xl font-bold">Add a new fish</h2>
        <div class="mt-3 grid gap-2 sm:grid-cols-2">
          <div><label class="lbl" for="n_name">Name</label><input class="field" id="n_name" name="name" placeholder="Red Snapper" required /></div>
          <div><label class="lbl" for="n_local">Local name</label><input class="field" id="n_local" name="local_name" placeholder="Tamoshi" /></div>
          <div><label class="lbl" for="n_cat">Type</label>
            <select class="field" id="n_cat" name="category"><option value="fish">Fish</option><option value="shellfish">Prawns / crabs</option></select></div>
          <div><label class="lbl" for="n_unit">Sold by</label>
            <select class="field" id="n_unit" name="unit"><option value="kg">Kg (0.5 kg steps)</option><option value="piece">Piece</option></select></div>
          <div><label class="lbl" for="n_price">Price ₹</label><input class="field" id="n_price" name="price" type="number" step="0.01" min="1" inputmode="decimal" required /></div>
          <div><label class="lbl" for="n_stock">Landed today</label><input class="field" id="n_stock" name="stock_qty" type="number" step="0.5" min="0" inputmode="decimal" /></div>
          <div class="sm:col-span-2"><label class="lbl" for="n_desc">Description</label><input class="field" id="n_desc" name="description" placeholder="Firm white flesh, great for curry" /></div>
          <div class="sm:col-span-2"><label class="lbl" for="n_cuts">Cut options</label><input class="field" id="n_cuts" name="cut_options" value="Whole,Cleaned,Curry cut,Fry cut" /></div>
          <div class="sm:col-span-2">
            <label class="lbl" for="n_photo">Photo <span class="font-normal text-ink-soft">(optional — you can add it later)</span></label>
            <input class="field !py-2" id="n_photo" name="photo" type="file" accept="image/*" capture="environment" />
            <p class="mt-1 text-xs text-ink-soft">Shoot it on your phone in daylight. We shrink it for you.</p>
          </div>
        </div>
        <button class="mt-4 w-full rounded-2xl bg-ink py-3 font-semibold text-sand hover:bg-sea">Add fish</button>
      </form>

    <?php else: ?>
      <!-- ============================ SETTINGS ============================ -->
      <h1 class="font-display text-3xl font-bold">Shop settings</h1>
      <form method="post" class="mt-5 space-y-4 rounded-3xl bg-white p-4 shadow-card">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>" /><input type="hidden" name="action" value="save_settings" /><input type="hidden" name="tab" value="settings" />

        <label class="flex items-center justify-between gap-3 rounded-2xl bg-sand p-4">
          <span><span class="block font-semibold">Taking bookings today</span><span class="block text-sm text-ink-soft">Switch off on days with no catch.</span></span>
          <input type="checkbox" name="booking_open" class="h-6 w-6 rounded text-sea" <?= $settings['booking_open'] ? 'checked' : '' ?> />
        </label>

        <div class="grid gap-3 sm:grid-cols-2">
          <div><label class="lbl" for="landed_at">Catch landed at (shown on site)</label><input class="field" id="landed_at" name="landed_at" value="<?= h($settings['landed_at']) ?>" placeholder="5:45 AM" /></div>
          <div><label class="lbl" for="cutoff">Bookings close at</label><input class="field" id="cutoff" name="cutoff" type="time" value="<?= h(str_pad($settings['cutoff'], 5, '0', STR_PAD_LEFT)) ?>" /></div>
          <div><label class="lbl" for="delivery_fee">Delivery fee ₹</label><input class="field" id="delivery_fee" name="delivery_fee" type="number" min="0" step="1" value="<?= h((string) $settings['delivery_fee']) ?>" /></div>
          <div><label class="lbl" for="free_delivery_above">Free delivery above ₹ (0 = never)</label><input class="field" id="free_delivery_above" name="free_delivery_above" type="number" min="0" step="1" value="<?= h((string) $settings['free_delivery_above']) ?>" /></div>
        </div>
        <button class="w-full rounded-2xl bg-coral py-3.5 font-bold text-white hover:bg-coral-dark">Save settings</button>
      </form>

      <section class="mt-5 rounded-3xl bg-white p-4 text-sm shadow-card">
        <h2 class="font-semibold">Connections</h2>
        <ul class="mt-2 space-y-1 text-ink-soft">
          <li>Razorpay: <strong><?= str_starts_with((string) cfg('razorpay.key_id'), 'rzp_live_') ? 'LIVE mode' : 'TEST mode' ?></strong></li>
          <li>Email: <strong><?= h((string) cfg('mail.transport')) ?></strong></li>
          <li>WhatsApp: <strong><?= h((string) cfg('whatsapp.provider')) ?></strong></li>
        </ul>
      </section>
    <?php endif; ?>
  </main>
<?php endif; ?>
</body>
</html>
