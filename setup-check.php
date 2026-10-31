<?php
/* =====================================================================
   setup-check.php — "will this host actually run the shop?"

   Upload the site to a new host, open this page, type the admin
   password, and it tells you in one screen what works and what does
   not: PHP version, the extensions the shop needs, the database, the
   photo folder, and — the one that catches people out on free hosting —
   whether the server is allowed to reach Razorpay at all.

   It never prints a password or an API key, only whether one is set.

   DELETE THIS FILE once the shop is live. It is a setup tool, not part
   of the shop.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/photos.php';

session_name('mc_setup');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

/* ---------------------------------------------------------------------
   Password gate — the same admin password, so there is nothing new to
   remember and the page cannot be read by a stranger.
   --------------------------------------------------------------------- */
$expected = (string) cfg('admin.password', '');
$usable   = $expected !== '' && $expected !== 'CHANGE_ME_to_a_long_password';

if (($_POST['password'] ?? null) !== null) {
    if ($usable && hash_equals($expected, (string) $_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['setup_ok'] = true;
    } else {
        sleep(1);
        $loginError = $usable ? 'Wrong password.' : 'Set a real admin.password in your config file first.';
    }
}

if (empty($_SESSION['setup_ok'])) {
    render_login($loginError ?? '');
    exit;
}

/* ---------------------------------------------------------------------
   The checks. Each returns [status, headline, what to do about it].
   status: 'ok' | 'warn' | 'fail'
   --------------------------------------------------------------------- */
$groups = [];

/* ---- 1. The server itself ---- */
$checks = [];

$php = PHP_VERSION;
$checks[] = version_compare($php, '8.0', '>=')
    ? ['ok', "PHP $php", 'Modern enough for everything here.']
    : ['fail', "PHP $php is too old", 'The shop needs PHP 8.0 or newer. Look for a "PHP version" or "Select PHP version" setting in your control panel.'];

$needed = [
    'pdo_mysql' => 'talking to the database',
    'curl'      => 'calling Razorpay',
    'gd'        => 'resizing uploaded photos',
    'openssl'   => 'HTTPS and signatures',
    'mbstring'  => 'handling non-English text',
    'json'      => 'everything',
];
foreach ($needed as $ext => $why) {
    $checks[] = extension_loaded($ext)
        ? ['ok', "$ext is on", "Needed for $why."]
        : ['fail', "$ext is missing", "The shop needs it for $why. Turn it on in the control panel's PHP extensions page, or use a different host."];
}
$checks[] = extension_loaded('exif')
    ? ['ok', 'exif is on', 'Phone photos will be turned the right way up.']
    : ['warn', 'exif is off', 'Photos still upload, but one taken sideways on a phone may stay sideways.'];

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$checks[] = $https
    ? ['ok', 'HTTPS is on', 'Razorpay will not take live payments without it.']
    : ['fail', 'This page is not on HTTPS', 'Turn on the free SSL certificate in your control panel, then open this page again with https:// in front.'];

$checks[] = ['ok', 'Config file found', 'Loaded from ' . h(basename((string) ($GLOBALS['MC_CONFIG_FILE'] ?? '?'))) . '.'];

$inWebRoot = str_starts_with((string) ($GLOBALS['MC_CONFIG_FILE'] ?? ''), __DIR__);
$checks[] = $inWebRoot
    ? ['warn', 'Config sits inside the website folder', 'It works, and .htaccess hides it, but it is safer one level above the public folder where the browser can never reach it.']
    : ['ok', 'Config is outside the website folder', 'The browser cannot reach it at all. This is the right place for it.'];

$groups['The server'] = $checks;

/* ---- 2. Database ---- */
$checks = [];
$pdo = db_optional();
if ($pdo === null) {
    $checks[] = ['fail', 'Cannot connect to the database', 'Check db.host, db.name, db.user and db.password in your config. On most free hosts the host is not "localhost" — copy the exact MySQL hostname from the control panel.'];
} else {
    $checks[] = ['ok', 'Connected to the database', 'The name, user and password in your config are correct.'];

    $wanted = ['products', 'orders', 'order_items', 'settings'];
    $found  = [];
    try {
        foreach ($pdo->query('SHOW TABLES') as $row) $found[] = (string) reset($row);
    } catch (Throwable $e) { /* some hosts restrict SHOW TABLES */ }

    $missing = array_diff($wanted, $found);
    if ($found === []) {
        $checks[] = ['warn', 'Could not list the tables', 'Not necessarily a problem — open phpMyAdmin and check the four tables are there.'];
    } elseif ($missing) {
        $checks[] = ['fail', 'Missing tables: ' . h(implode(', ', $missing)), 'Open phpMyAdmin, pick your database, go to Import and upload database/schema.sql.'];
    } else {
        $checks[] = ['ok', 'All four tables are there', 'products, orders, order_items and settings.'];
        try {
            $n = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
            $checks[] = $n > 0
                ? ['ok', "$n fish in the catch", 'The shop page will have something to show.']
                : ['warn', 'No fish yet', 'Add them from /admin, or re-import schema.sql to get the seven samples.'];
        } catch (Throwable $e) {
            $checks[] = ['warn', 'Could not count the products', h($e->getMessage())];
        }

        // Added later than the first release, so a shop set up before it
        // exists will have orders fail on every checkout until it is added.
        try {
            $pdo->query('SELECT cut_fee FROM order_items LIMIT 1');
            $checks[] = ['ok', 'Preparation charges are supported', 'order_items has the cut_fee column.'];
        } catch (Throwable $e) {
            $checks[] = ['fail', 'order_items is missing the cut_fee column', 'Orders will fail until you add it. In phpMyAdmin pick your database, open the SQL tab and run: ALTER TABLE order_items ADD COLUMN cut_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER cut_type;'];
        }

        try {
            $pdo->query('SELECT id, name, area, is_active FROM societies LIMIT 1');
            $n = (int) $pdo->query('SELECT COUNT(*) FROM societies WHERE is_active = 1')->fetchColumn();
            $checks[] = $n > 0
                ? ['ok', "$n societies on the delivery list", 'Flats can pick their building at checkout.']
                : ['warn', 'No societies listed yet', 'Batch delivery stays hidden until you add one in /admin → Societies.'];
        } catch (Throwable $e) {
            $checks[] = ['fail', 'The societies table is missing', 'Batch delivery cannot work. Run the CREATE TABLE societies statement from database/schema.sql in phpMyAdmin → SQL.'];
        }

        try {
            $pdo->query('SELECT society_id, flat_number, delivery_waived FROM orders LIMIT 1');
            $checks[] = ['ok', 'Orders can record a society and flat', 'orders has society_id, flat_number and delivery_waived.'];
        } catch (Throwable $e) {
            $checks[] = ['fail', 'orders is missing the society columns', 'Checkout will fail. Run: ALTER TABLE orders ADD COLUMN society_id INT UNSIGNED NULL AFTER delivery_slot, ADD COLUMN flat_number VARCHAR(20) NULL AFTER society_id, ADD COLUMN delivery_waived TINYINT(1) NOT NULL DEFAULT 0 AFTER flat_number;'];
        }
    }
}
$groups['Database'] = $checks;

/* ---- 3. Razorpay — the one that decides whether a free host is usable ---- */
$checks = [];
$keyId     = trim((string) cfg('razorpay.key_id', ''));
$keySecret = trim((string) cfg('razorpay.key_secret', ''));
$webhook   = trim((string) cfg('razorpay.webhook_secret', ''));

if ($keyId === '' || str_contains($keyId, 'XXXX') || $keySecret === '' || str_starts_with($keySecret, 'YOUR_')) {
    $checks[] = ['fail', 'Razorpay keys are not filled in', 'Put your Key ID and Key Secret into the config file. Start with Test Mode keys (rzp_test_...).'];
} else {
    $mode = str_starts_with($keyId, 'rzp_live_') ? 'LIVE' : 'Test';
    $checks[] = ['ok', "$mode keys are set", 'Key ID ends in ' . h(substr($keyId, -4)) . '. The secret is set but never shown here.'];

    // A read-only call. It proves three things at once: the host is
    // allowed out, TLS works, and the keys are accepted.
    $ch = curl_init('https://api.razorpay.com/v1/orders?count=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $keyId . ':' . $keySecret,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errNo  = curl_errno($ch);
    $errMsg = curl_error($ch);
    curl_close($ch);

    if ($body === false && $errNo === 6) {
        $checks[] = ['fail', 'This host cannot look up api.razorpay.com', 'The host is blocking outbound connections (cURL error 6 — DNS). Payments cannot work here at all. You need a different host.'];
    } elseif ($body === false && $errNo === 28) {
        $checks[] = ['fail', 'Connection to Razorpay timed out', 'The host is blocking the outbound port (cURL error 28). Payments cannot work here. Ask the host whether outbound HTTPS is allowed, or move hosts.'];
    } elseif ($body === false) {
        $checks[] = ['fail', 'Could not reach Razorpay', 'cURL error ' . $errNo . ': ' . h($errMsg)];
    } elseif ($status === 401) {
        $checks[] = ['fail', 'Razorpay refused the keys', 'The server reached Razorpay, so the host is fine — but the Key ID and Key Secret do not match. Copy them again from the dashboard.'];
    } elseif ($status === 200) {
        $checks[] = ['ok', 'Razorpay answered', 'Outbound connections work and your keys are accepted. Payments will work on this host.'];
    } else {
        $checks[] = ['warn', "Razorpay answered with HTTP $status", 'Not the expected 200. The connection itself works, so this is about the keys or the account.'];
    }
}

$checks[] = $webhook !== '' && !str_starts_with($webhook, 'YOUR_')
    ? ['ok', 'Webhook secret is set', 'Make sure the same text is saved in the Razorpay dashboard under Webhooks.']
    : ['warn', 'No webhook secret', 'Without it, an order is lost if the buyer\'s phone drops off right after paying. Add a webhook in the Razorpay dashboard pointing at api/razorpay_webhook.php.'];

$groups['Razorpay'] = $checks;

/* ---- 4. Photos ---- */
$checks = [];
if (!photo_dir_prepare()) {
    $checks[] = ['fail', 'The photo folder is not writable', 'Set assets/img/products to permissions 755 in the file manager.'];
} else {
    $checks[] = ['ok', 'Photo folder is writable', 'assets/img/products is ready for uploads.'];

    if (function_exists('imagewebp')) {
        $probe = photo_dir() . '/.setup-probe.webp';
        $im = @imagecreatetruecolor(40, 40);
        $wrote = $im && @imagewebp($im, $probe, 80);
        if ($im) imagedestroy($im);
        if (is_file($probe)) @unlink($probe);
        $checks[] = $wrote
            ? ['ok', 'WebP photos can be written', 'A 5 MB phone photo will come out around 40 KB.']
            : ['warn', 'WebP failed to write', 'Photos will fall back to JPEG — bigger, but it still works.'];
    } else {
        $checks[] = ['warn', 'No WebP support', 'Photos will be saved as JPEG instead. Larger files, but fine.'];
    }

    $checks[] = is_file(photo_dir() . '/.htaccess')
        ? ['ok', 'Photo folder is locked down', 'Nothing in it can be executed.']
        : ['warn', 'Guard file missing', 'Re-upload assets/img/products/.htaccess — some file managers skip dot-files.'];
}

$maxUpload = ini_get('upload_max_filesize') ?: '?';
$checks[] = ['ok', "Uploads up to $maxUpload", 'A phone photo is usually 2–6 MB. Below 4M, the seller may hit the limit.'];
$groups['Photos'] = $checks;

/* ---- 5. Receipts ---- */
$checks = [];
$transport = (string) cfg('mail.transport', 'log');
$brevoKey = trim((string) cfg('mail.brevo_api_key', ''));
$checks[] = match (true) {
    $transport === 'brevo' && ($brevoKey === '' || str_starts_with($brevoKey, 'YOUR_'))
        => ['fail', 'Brevo is selected but there is no API key', 'Put your key in mail.brevo_api_key, or set mail.transport back to \'log\'.'],
    $transport === 'brevo'
        => ['ok', 'Email by Brevo', 'Sends over HTTPS, so it works where SMTP is blocked. Brevo only sends from an address verified under Senders & Domains.'],
    $transport === 'smtp'
        => ['ok', 'Email by SMTP', 'Fine on normal hosting. Free hosts usually block the SMTP ports — if receipts never arrive, switch to \'brevo\'.'],
    $transport === 'mail'
        => ['warn', 'Email by PHP mail()', 'Often lands in spam, and most free hosts disable it. \'brevo\' is more reliable.'],
    default
        => ['warn', 'Email is in log mode', 'Nothing is sent — messages go to the log file and the buyer is told to keep their Order ID. Change this before going live.'],
};
$wa = (string) cfg('whatsapp.provider', 'none');
$checks[] = in_array($wa, ['ultramsg', 'meta', 'twilio'], true)
    ? ['ok', "WhatsApp via $wa", 'Receipts will go out on WhatsApp.']
    : ['warn', 'WhatsApp is in ' . h($wa) . ' mode', 'Nothing is sent yet. Pick a provider in the config when you are ready — see DEPLOY.md step 7.'];
$groups['Receipts'] = $checks;

/* ---- 6. Shop details ---- */
$checks = [];
$b = business();
foreach ([
    'legal_name' => 'Business name',
    'address'    => 'Address',
    'phone'      => 'Phone number',
    'whatsapp'   => 'WhatsApp number',
    'email'      => 'Email address',
    'fssai'      => 'FSSAI registration number',
] as $key => $label) {
    $value = (string) ($b[$key] ?? '');
    $sample = $value === ''
        || str_contains($value, 'Seller full name')
        || str_contains($value, 'Municipal Fish Market');
    $checks[] = $sample
        ? ['warn', "$label is not set yet", 'Razorpay checks these during activation. Fill in business.' . $key . ' in your config.']
        : ['ok', "$label is set", 'Shows in the footer and on the policy pages.'];
}
foreach (legal_pages() as $file => $label) {
    $checks[] = is_file(__DIR__ . '/' . $file)
        ? ['ok', "$label page is there", h($file) . ' uploaded.']
        : ['fail', "$label page is missing", 'Upload ' . h($file) . ' — Razorpay will not activate without all five.'];
}
$groups['Shop details'] = $checks;

/* ---- 7. Security ---- */
$checks = [];
$checks[] = strlen($expected) >= 12
    ? ['ok', 'Admin password is a decent length', 'Keep it to yourself.']
    : ['warn', 'Admin password is short', 'Anyone who guesses it can change prices and read every order. Make it 12 characters or more.'];
$checks[] = is_file(__DIR__ . '/includes/.htaccess')
    ? ['ok', 'includes/ is protected', 'Open yourdomain.com/includes/config.sample.php in a browser — you should get an error page, not code.']
    : ['fail', 'includes/.htaccess is missing', 'Re-upload it. Without it your config could be readable. Some file managers skip dot-files — turn on "show hidden files".'];
$checks[] = is_file(__DIR__ . '/.htaccess')
    ? ['ok', 'Main .htaccess is there', 'Forces HTTPS and hides private folders.']
    : ['warn', 'Main .htaccess is missing', 'Re-upload it from the repo root.'];
$groups['Security'] = $checks;

/* ---------------------------------------------------------------------
   Tally and output
   --------------------------------------------------------------------- */
$tally = ['ok' => 0, 'warn' => 0, 'fail' => 0];
foreach ($groups as $checks) foreach ($checks as [$status]) $tally[$status]++;

render_report($groups, $tally);

/* ---------------------------------------------------------------------
   Views
   --------------------------------------------------------------------- */
function setup_head(string $title): void
{
    ?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow" />
  <title><?= h($title) ?></title>
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <script src="assets/js/tailwind-config.js"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
</head>
<body class="bg-sand font-sans text-ink antialiased">
<div class="mx-auto max-w-3xl px-4 py-10">
    <?php
}

function render_login(string $error): void
{
    setup_head('Setup check');
    ?>
  <div class="mx-auto max-w-sm rounded-3xl bg-white p-6 shadow-card">
    <p class="text-xs font-bold uppercase tracking-[0.18em] text-coral">Morning Catch</p>
    <h1 class="mt-1 font-display text-3xl font-bold">Setup check</h1>
    <p class="mt-2 text-sm text-ink-soft">Checks this server can run the shop. Uses your admin password.</p>
    <?php if ($error !== ''): ?>
      <p class="mt-4 rounded-xl bg-coral-light px-3 py-2 text-sm font-medium text-coral-dark"><?= h($error) ?></p>
    <?php endif; ?>
    <form method="post" class="mt-5">
      <label class="mb-1.5 block text-sm font-semibold" for="password">Admin password</label>
      <input class="w-full rounded-xl border-0 bg-sand px-4 py-3 text-base ring-1 ring-ink/10 focus:ring-2 focus:ring-sea"
             id="password" name="password" type="password" autocomplete="current-password" required autofocus />
      <button class="mt-4 w-full rounded-2xl bg-coral py-3.5 font-bold text-white hover:bg-coral-dark">Run the check</button>
    </form>
  </div>
</div></body></html>
    <?php
}

function render_report(array $groups, array $tally): void
{
    $badge = [
        'ok'   => ['bg-sea-light text-sea', '✓'],
        'warn' => ['bg-sun-light text-[#8A5D06]', '!'],
        'fail' => ['bg-coral-light text-coral-dark', '✕'],
    ];
    setup_head('Setup check');
    ?>
  <p class="text-xs font-bold uppercase tracking-[0.18em] text-coral">Morning Catch</p>
  <h1 class="mt-1 font-display text-3xl font-bold">Setup check</h1>

  <div class="mt-5 flex flex-wrap gap-2 text-sm font-semibold">
    <span class="rounded-full bg-sea-light px-4 py-2 text-sea"><?= $tally['ok'] ?> working</span>
    <?php if ($tally['warn']): ?><span class="rounded-full bg-sun-light px-4 py-2 text-[#8A5D06]"><?= $tally['warn'] ?> to look at</span><?php endif; ?>
    <?php if ($tally['fail']): ?><span class="rounded-full bg-coral-light px-4 py-2 text-coral-dark"><?= $tally['fail'] ?> blocking</span><?php endif; ?>
  </div>

  <?php if ($tally['fail'] === 0): ?>
    <p class="mt-5 rounded-2xl bg-sea-light p-4 text-sm text-sea">
      Nothing is blocking you. Place one test order to see it end to end, then delete this file.
    </p>
  <?php else: ?>
    <p class="mt-5 rounded-2xl bg-coral-light p-4 text-sm text-coral-dark">
      Fix the red rows below before testing an order. Anything about reaching Razorpay means this host will not do — the rest are usually a setting or a missing file.
    </p>
  <?php endif; ?>

  <?php foreach ($groups as $name => $checks): ?>
    <section class="mt-7">
      <h2 class="font-display text-xl font-bold"><?= h($name) ?></h2>
      <ul class="mt-3 space-y-2">
        <?php foreach ($checks as [$status, $headline, $detail]): [$cls, $mark] = $badge[$status]; ?>
          <li class="flex gap-3 rounded-2xl bg-white p-3.5 shadow-card">
            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full text-sm font-bold <?= $cls ?>"><?= $mark ?></span>
            <div class="min-w-0">
              <p class="font-semibold"><?= $headline ?></p>
              <p class="mt-0.5 text-sm text-ink-soft"><?= $detail ?></p>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>

  <p class="mt-8 rounded-2xl bg-ink p-4 text-sm text-sand/80">
    <strong class="text-sand">Delete setup-check.php once the shop is live.</strong>
    It is a setup tool. Leaving it up tells strangers what your server runs.
  </p>
</div></body></html>
    <?php
}
