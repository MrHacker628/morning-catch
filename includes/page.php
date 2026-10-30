<?php
/* =====================================================================
   page.php — shared header and footer for the plain content pages
   (terms, privacy, refunds, delivery, contact).

   Those pages are PHP, not HTML, so every seller detail — name,
   address, FSSAI number, WhatsApp — comes from the config file and
   nobody has to edit markup to rebrand the site.

   Usage:
       require __DIR__ . '/includes/bootstrap.php';
       require __DIR__ . '/includes/page.php';
       page_start('Terms & conditions', 'The rules for booking fish here.');
       ... content ...
       page_end();
   ===================================================================== */

declare(strict_types=1);

/** Opening markup: <head>, the top bar and the start of the article. */
function page_start(string $title, string $intro = ''): void
{
    $b         = business();
    $shop      = $b['shop_name'];
    $pageTitle = $title . ' · ' . $shop;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title><?= h($pageTitle) ?></title>
  <meta name="description" content="<?= h($intro !== '' ? $intro : $title . ' for ' . $shop) ?>" />
  <meta name="theme-color" content="#0B2733" />
  <meta name="robots" content="index, follow" />

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />

  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <script src="assets/js/tailwind-config.js"></script>
  <style>
    html { scroll-behavior: smooth; -webkit-tap-highlight-color: transparent; }
    /* Readable long-form text without pulling in the typography plugin */
    .prose h2 { font-size: 1.25rem; font-weight: 700; margin-top: 2rem; margin-bottom: .5rem; }
    .prose p, .prose li { line-height: 1.7; }
    .prose p { margin-bottom: .9rem; }
    .prose ul { list-style: disc; padding-left: 1.3rem; margin-bottom: .9rem; }
    .prose li { margin-bottom: .35rem; }
    .prose strong { font-weight: 600; }
    .prose a:not(.not-prose) { color: #0E6B74; text-decoration: underline; }
  </style>
</head>

<body class="bg-sand font-sans text-ink antialiased">

  <a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-full focus:bg-ink focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-sand">Skip to content</a>

  <header class="bg-ink text-sand">
    <div class="mx-auto flex max-w-3xl items-center justify-between px-4 py-3">
      <a href="index.html" class="flex items-center gap-2.5">
        <svg width="34" height="34" viewBox="0 0 40 40" aria-hidden="true">
          <circle cx="20" cy="20" r="20" fill="#F2643D"/>
          <circle cx="20" cy="22" r="8" fill="#F5B53F"/>
          <path d="M0 26c5 0 5-3 10-3s5 3 10 3 5-3 10-3 5 3 10 3v14H0z" fill="#0E6B74"/>
          <path d="M0 31c5 0 5-3 10-3s5 3 10 3 5-3 10-3 5 3 10 3v9H0z" fill="#0B2733" opacity=".55"/>
        </svg>
        <div class="leading-tight">
          <p class="font-display text-lg font-bold tracking-tight"><?= h($shop) ?></p>
          <?php if ($b['tagline'] !== ''): ?>
            <p class="text-[11px] text-sand/60"><?= h($b['tagline']) ?></p>
          <?php endif; ?>
        </div>
      </a>
      <a href="index.html" class="rounded-full bg-sand/10 px-4 py-2 text-sm font-semibold hover:bg-sand/20">Today's catch</a>
    </div>
  </header>

  <main id="content" class="mx-auto max-w-3xl px-4 py-10">
    <article class="rounded-3xl bg-white p-6 shadow-card sm:p-9">
      <p class="text-xs font-bold uppercase tracking-[0.18em] text-coral"><?= h($shop) ?></p>
      <h1 class="mt-1 font-display text-3xl font-bold tracking-tight sm:text-4xl"><?= h($title) ?></h1>
      <?php if ($intro !== ''): ?>
        <p class="mt-3 text-[15px] leading-relaxed text-ink-soft"><?= h($intro) ?></p>
      <?php endif; ?>
      <p class="mt-3 text-xs text-ink-soft">Last updated <?= h(date('j F Y', @filemtime($_SERVER['SCRIPT_FILENAME'] ?? '') ?: time())) ?></p>
      <hr class="my-6 border-ink/10" />
      <div class="prose text-[15px] text-ink-soft">
    <?php
}

/** Closing markup: end of the article, the footer and the legal line. */
function page_end(): void
{
    $b     = business();
    $pages = legal_pages();
    $here  = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $wa    = whatsapp_link("Hi! I have a question about today's catch.");
    ?>
      </div>
    </article>

    <nav class="mt-6 flex flex-wrap justify-center gap-x-4 gap-y-2 text-sm" aria-label="Policies">
      <?php foreach ($pages as $file => $label): ?>
        <?php if ($file === $here): ?>
          <span class="font-semibold text-ink"><?= h($label) ?></span>
        <?php else: ?>
          <a href="<?= h($file) ?>" class="text-ink-soft underline hover:text-sea"><?= h($label) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  </main>

  <footer class="bg-ink py-10 text-sm text-sand/80">
    <div class="mx-auto grid max-w-3xl gap-6 px-4 sm:grid-cols-2">
      <div>
        <p class="font-display text-lg font-bold text-sand"><?= h($b['legal_name']) ?></p>
        <?php if ($lines = business_address_lines()): ?>
          <address class="mt-2 not-italic leading-relaxed"><?= implode('<br />', array_map('h', $lines)) ?></address>
        <?php endif; ?>
        <?php if ($b['hours'] !== ''): ?>
          <p class="mt-2"><?= h($b['hours']) ?></p>
        <?php endif; ?>
      </div>
      <div>
        <p class="font-semibold text-sand">Get in touch</p>
        <ul class="mt-2 space-y-1">
          <?php if ($b['phone'] !== ''): ?>
            <li><a class="hover:text-sand" href="tel:<?= h(preg_replace('/[^\d+]/', '', $b['phone'])) ?>"><?= h($b['phone']) ?></a></li>
          <?php endif; ?>
          <?php if ($b['email'] !== ''): ?>
            <li><a class="hover:text-sand" href="mailto:<?= h($b['email']) ?>"><?= h($b['email']) ?></a></li>
          <?php endif; ?>
          <?php if ($wa !== ''): ?>
            <li><a class="hover:text-sand" href="<?= h($wa) ?>" rel="noopener">Chat on WhatsApp</a></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>

    <div class="mx-auto mt-8 max-w-3xl px-4 text-xs text-sand/45">
      <?php if ($b['fssai'] !== ''): ?>
        <p>FSSAI registration no. <?= h($b['fssai']) ?></p>
      <?php endif; ?>
      <?php if ($b['gstin'] !== ''): ?>
        <p>GSTIN <?= h($b['gstin']) ?></p>
      <?php endif; ?>
      <p class="mt-1">&copy; <?= date('Y') ?> <?= h($b['legal_name']) ?> · Payments secured by Razorpay</p>
    </div>
  </footer>
</body>
</html>
    <?php
}
