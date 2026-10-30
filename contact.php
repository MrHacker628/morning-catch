<?php
/* =====================================================================
   contact.php — Contact us

   Razorpay asks for a contact page with a real address and working
   contact details before it will activate a live account. Everything
   on this page comes from the `business` block of the config file.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/page.php';

$b     = business();
$lines = business_address_lines();
$wa    = whatsapp_link("Hi! I have a question about today's catch.");
$maps  = $lines ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(implode(', ', $lines)) : '';

page_start('Contact us', 'The quickest way to reach the stall is WhatsApp — we read it while we are packing.');
?>

<p>
  Questions about today's catch, a cut you want, an order on its way, or a refund? Message us and
  the seller will answer in person. If it is about an existing order, please include your
  <strong>order ID</strong> (it looks like MC-201026-0042 and is on your receipt).
</p>

<?php if ($wa !== ''): ?>
  <p>
    <a href="<?= h($wa) ?>" rel="noopener"
       class="not-prose mt-2 inline-flex items-center gap-2 rounded-full bg-[#25D366] px-5 py-3 font-semibold text-white no-underline hover:brightness-95">
      <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 00-8.6 15.1L2 22l5-1.3A10 10 0 1012 2zm5.3 14.2c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .1-3.3-.8-2.8-1.1-4.6-4-4.7-4.2-.1-.2-1.1-1.5-1.1-2.9s.7-2 1-2.3c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.3.5-.4.4c-.1.1-.3.3-.1.6.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.3 2.4 1.5.3.1.5.1.6-.1l.9-1c.2-.3.4-.2.6-.1l1.9.9c.3.1.5.2.5.3.1.2.1.7-.1 1.2z"/></svg>
      Chat on WhatsApp
    </a>
  </p>
<?php endif; ?>

<h2>Shop details</h2>
<ul>
  <li><strong>Business name:</strong> <?= h($b['legal_name']) ?></li>
  <?php if ($b['owner_name'] !== ''): ?>
    <li><strong>Proprietor:</strong> <?= h($b['owner_name']) ?></li>
  <?php endif; ?>
  <?php if ($lines): ?>
    <li>
      <strong>Address:</strong> <?= h(implode(', ', $lines)) ?>
      <?php if ($maps !== ''): ?>
        — <a href="<?= h($maps) ?>" rel="noopener">get directions</a>
      <?php endif; ?>
    </li>
  <?php endif; ?>
  <?php if ($b['phone'] !== ''): ?>
    <li><strong>Phone:</strong> <a href="tel:<?= h(preg_replace('/[^\d+]/', '', $b['phone'])) ?>"><?= h($b['phone']) ?></a></li>
  <?php endif; ?>
  <?php if ($b['email'] !== ''): ?>
    <li><strong>Email:</strong> <a href="mailto:<?= h($b['email']) ?>"><?= h($b['email']) ?></a></li>
  <?php endif; ?>
  <?php if ($b['hours'] !== ''): ?>
    <li><strong>Open:</strong> <?= h($b['hours']) ?></li>
  <?php endif; ?>
  <?php if ($b['fssai'] !== ''): ?>
    <li><strong>FSSAI registration:</strong> <?= h($b['fssai']) ?></li>
  <?php endif; ?>
  <?php if ($b['gstin'] !== ''): ?>
    <li><strong>GSTIN:</strong> <?= h($b['gstin']) ?></li>
  <?php endif; ?>
</ul>

<h2>When we answer</h2>
<p>
  Mornings are the busiest part of our day — if you message between 5 AM and noon we may answer
  after the deliveries are done. We aim to reply the same day, and always within 2 working days.
</p>

<h2>Complaints</h2>
<p>
  If something went wrong with an order, tell us the same day with a photo and your order ID, and
  see our <a href="refund.php">refunds and cancellations policy</a>. Complaints come straight to
  <?= $b['owner_name'] !== '' ? h($b['owner_name']) . ', ' : '' ?>the owner — there is no call
  centre in between.
</p>

<?php page_end(); ?>
