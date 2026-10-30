<?php
/* =====================================================================
   shipping.php — Delivery policy

   Razorpay asks for a "shipping policy" before it will activate a live
   account. For a fish stall that means same-morning local delivery, so
   this page says exactly that rather than pretending to be a courier.

   Delivery areas and the note below come from the config file. Not
   legal advice.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/page.php';

$b     = business();
$s     = shop_settings();
$slots = (array) cfg('shop.slots', []);

page_start('Delivery policy', 'Where we deliver, when it arrives, and what it costs.');
?>

<h2>Same morning, not a courier</h2>
<p>
  We are a local fish stall, not a parcel service. Everything is caught overnight, landed in the
  morning, cleaned and cut to your order, and delivered to you <strong>the same morning on
  ice</strong>. We do not ship fish by courier, by post, or outside our delivery area, and we do
  not deliver to other states.
</p>

<h2>Where we deliver</h2>
<?php if ($b['delivery_areas'] !== ''): ?>
  <p>We deliver to <strong><?= h($b['delivery_areas']) ?></strong>.</p>
<?php else: ?>
  <p>We deliver locally around the stall. Message us if you are not sure whether your address is covered.</p>
<?php endif; ?>
<p>
  If your address turns out to be outside the area after you have ordered, we will call you and
  either arrange pickup at the stall or refund the order in full.
</p>

<h2>When it arrives</h2>
<p>
  You pick a time slot at checkout and we deliver within it.
  <?= $b['delivery_note'] !== '' ? h($b['delivery_note']) : '' ?>
</p>
<?php if (!empty($slots['delivery'])): ?>
  <p>Delivery slots:</p>
  <ul>
    <?php foreach ((array) $slots['delivery'] as $slot): ?>
      <li><?= h((string) $slot) ?></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<p>
  Orders close at <strong><?= h(pretty_time($s['cutoff'])) ?></strong> each morning. Anything ordered after
  that is for the next day's catch.
</p>
<p>
  On a busy morning, in heavy rain, or if a road is blocked, a delivery can run late. We will
  message you if it does. If we are going to miss your slot badly, you can ask for a full refund
  instead.
</p>

<h2>What delivery costs</h2>
<ul>
  <li><strong>Home delivery:</strong> <?= h(inr($s['delivery_fee'])) ?> per order.</li>
  <?php if ($s['free_delivery_above'] > 0): ?>
    <li><strong>Free delivery</strong> on orders of <?= h(inr($s['free_delivery_above'])) ?> or more.</li>
  <?php endif; ?>
  <li><strong>Stall pickup:</strong> free.</li>
</ul>
<p>The fee is shown in your bill before you pay — there is nothing extra to pay on delivery.</p>

<h2>Picking up at the stall</h2>
<?php if ($b['pickup_address'] !== ''): ?>
  <p>
    Choose "pick up at stall" at checkout and collect your order from
    <strong><?= h($b['pickup_address']) ?></strong> in the slot you picked.
    Please bring your order ID.
  </p>
<?php else: ?>
  <p>
    Choose "pick up at stall" at checkout and collect your order in the slot you picked. Please
    bring your order ID.
  </p>
<?php endif; ?>
<?php if (!empty($slots['pickup'])): ?>
  <p>Pickup slots:</p>
  <ul>
    <?php foreach ((array) $slots['pickup'] as $slot): ?>
      <li><?= h((string) $slot) ?></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<p>
  Orders not collected by the end of the day cannot be held over — fresh fish does not keep. See
  our <a href="refund.php">refunds policy</a> for what happens then.
</p>

<h2>Receiving your order</h2>
<ul>
  <li>Please give a complete address with a landmark, and a phone number that is switched on that morning.</li>
  <li>Somebody needs to be there to take it — we cannot leave fish at the door.</li>
  <li>Check the order when it arrives. If anything looks wrong, tell the delivery person straight away.</li>
  <li>Put it in the fridge as soon as you get it, and cook it the same day.</li>
</ul>

<h2>Questions</h2>
<p>
  Anything unclear about delivery? Ask us on the <a href="contact.php">contact page</a> before
  you order — we would rather answer first than refund later.
</p>

<?php page_end(); ?>
