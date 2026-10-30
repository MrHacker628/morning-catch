<?php
/* =====================================================================
   refund.php — Refunds & cancellations policy

   Razorpay asks for this page before it will activate a live account.

   Fresh fish is perishable, so the window for a free cancellation is
   short by nature. Check the timings below against how the seller
   actually works before going live. Not legal advice.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/page.php';

$b    = business();
$s    = shop_settings();
$wa   = whatsapp_link('Hi, I need help with my order. My order ID is ');

page_start('Refunds & cancellations', 'Fresh fish is perishable, so the rules are simple — and we would rather fix a problem than argue about it.');
?>

<h2>Cancelling an order</h2>
<ul>
  <li>
    <strong>Before the daily cutoff (<?= h($s['cutoff']) ?>)</strong> — cancel for any reason and
    get a full refund, including the delivery fee. Nothing has been cut or packed yet, so this
    costs us nothing.
  </li>
  <li>
    <strong>After the cutoff</strong> — by then your fish has been weighed, cleaned and cut to
    your order, and it cannot be sold to anyone else. We cannot normally cancel at this stage.
    Message us anyway: if it has not yet been cut, we will still refund you.
  </li>
  <li>
    <strong>Once the order is out for delivery</strong> — it cannot be cancelled.
  </li>
</ul>

<h2>If we cannot supply what you ordered</h2>
<p>
  Some mornings the boats land less than expected, or an item sells out between your order and
  packing. If that happens we will contact you before delivery and you choose:
</p>
<ul>
  <li>a <strong>full refund of that item</strong> (the rest of the order comes as normal), or</li>
  <li>a <strong>replacement</strong> of similar type and value, or</li>
  <li>a <strong>full refund of the whole order</strong>, including delivery, if the replacement does not suit you.</li>
</ul>
<p>
  If the boats do not come in at all, or bad weather or a fishing ban stops us delivering, every
  affected order is refunded in full automatically. You do not have to ask.
</p>

<h2>If you are not happy with the fish</h2>
<p>
  Fish is judged by eye and smell, and we would rather replace it than have you cook something
  you are unsure about. If something is not right:
</p>
<ul>
  <li>Tell us the <strong>same day</strong>, ideally within <strong>4 hours</strong> of delivery or pickup.</li>
  <li>Send a <strong>photo</strong> of the fish on WhatsApp with your order ID.</li>
  <li>Please keep it refrigerated until we have had a look.</li>
</ul>
<p>
  Where the complaint is genuine we will refund that item in full, or replace it with the next
  morning's catch — your choice. We cannot take back fish that was left out of the fridge, or
  cooked and then complained about, simply because we have no way to judge what went wrong.
</p>

<h2>Missed deliveries</h2>
<p>
  Fresh fish cannot wait outside your door. If nobody can receive the order in the slot you
  chose and we cannot reach you by phone, we will bring it back to the stall and hold it for
  that day only. You can collect it, or we can deliver it again for a second delivery fee.
  If you simply were not there and never got in touch, we cannot refund the fish.
</p>

<h2>Payment problems</h2>
<ul>
  <li>
    <strong>Money taken but no order shown</strong> — this usually means the payment went through
    just as your phone lost signal. Our system picks these up automatically and either confirms
    the order or refunds you. If nothing happens within 2 hours, message us with the order ID
    or the UPI reference.
  </li>
  <li><strong>Charged twice</strong> — the duplicate is refunded in full as soon as we see it.</li>
  <li><strong>Failed payment</strong> — no order is created, and any amount your bank held is released by them, normally within 5 to 7 working days.</li>
</ul>

<h2>How refunds reach you</h2>
<p>
  All refunds go back through Razorpay to the <strong>same method you paid with</strong> — the
  same UPI ID, card or bank account. We cannot refund to a different account, and we do not
  refund in cash.
</p>
<p>
  We raise the refund within <strong>24 hours</strong> of agreeing it. After that it depends on
  your bank: UPI refunds usually land within 1 to 3 working days, cards and netbanking within
  <strong>5 to 7 working days</strong>. You will get a confirmation message when we raise it.
</p>

<h2>How to ask for a refund</h2>
<p>
  Message us with your <strong>order ID</strong> (it looks like MC-201026-0042 and is on your
  receipt), what went wrong, and a photo if it is about quality.
  <?php if ($wa !== ''): ?>
    <a href="<?= h($wa) ?>" rel="noopener">WhatsApp is the fastest way</a>.
  <?php endif; ?>
  All the ways to reach us are on the <a href="contact.php">contact page</a>.
</p>
<p>
  We aim to answer the same day, and always within 2 working days.
</p>

<?php page_end(); ?>
