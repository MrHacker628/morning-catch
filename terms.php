<?php
/* =====================================================================
   terms.php — Terms & conditions

   Razorpay asks for this page before it will activate a live account.

   This is a sensible starting point for a small fresh-fish business in
   India, not legal advice. Read it through with the seller, correct
   anything that does not match how they actually work, and have it
   checked by a lawyer if the business grows.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/page.php';

$b    = business();
$s    = shop_settings();
$shop = $b['shop_name'];

page_start('Terms & conditions', 'The rules for booking fish on this website. By placing an order you agree to them.');
?>

<h2>1. Who you are buying from</h2>
<p>
  This website is run by <strong><?= h($b['legal_name']) ?></strong><?= $b['owner_name'] !== '' ? ', ' . h($b['owner_name']) : '' ?><?php
  if ($lines = business_address_lines()) { echo ', ' . h(implode(', ', $lines)); } ?>.
  In these terms, "we" and "us" mean that business, and "you" means the person placing the order.
</p>

<h2>2. What we sell</h2>
<p>
  We sell fresh fish, prawns and crab landed the same morning. Because this is a natural
  product caught at sea, no two days are the same: sizes, cuts and availability change daily,
  and the fish shown on the site is only what is actually in the ice box that morning.
</p>
<p>
  Weights are as accurate as we can make them on the scale, but fish is cleaned and cut by
  hand. The weight you receive may differ slightly from the weight you booked. Where the
  difference is more than about 10%, we will tell you before delivery and refund or adjust
  the difference.
</p>

<h2>3. Booking and the daily cutoff</h2>
<p>
  Orders for a given morning close at <strong><?= h(pretty_time($s['cutoff'])) ?></strong>. After that the
  site stops taking bookings until the next day's catch is listed. We may also close bookings
  earlier if the boats do not come in, if the weather is bad, or if everything has sold out.
</p>
<p>
  An order is confirmed only once your payment succeeds and you see the order ID on screen.
  Until then, nothing is held for you.
</p>

<h2>4. Prices and payment</h2>
<ul>
  <li>All prices are in Indian Rupees (₹) and include all applicable taxes.</li>
  <li>Prices are set per kilogram or per piece, as shown on each item.</li>
  <li>Prices change daily with the market. The price you pay is the price shown when you pay.</li>
  <li>
    <?php if ($s['free_delivery_above'] > 0): ?>
      A delivery fee of <?= h(inr($s['delivery_fee'])) ?> applies to home delivery, free on orders
      of <?= h(inr($s['free_delivery_above'])) ?> or more. There is no fee for stall pickup.
    <?php else: ?>
      A delivery fee of <?= h(inr($s['delivery_fee'])) ?> applies to home delivery. There is no fee
      for stall pickup.
    <?php endif; ?>
  </li>
  <li>
    Payments are handled by Razorpay (UPI, cards, netbanking and wallets). We never see or store
    your card number, UPI PIN or bank login.
  </li>
</ul>

<h2>5. If something is not available</h2>
<p>
  Occasionally a boat lands less than expected, or an item sells out between your order and
  packing. If we cannot supply something you have paid for, we will contact you and either
  refund that item in full or, with your agreement, replace it with something similar of the
  same value. See our <a href="refund.php">refunds and cancellations policy</a>.
</p>

<h2>6. Delivery and pickup</h2>
<p>
  Delivery areas, time slots and pickup details are set out in our
  <a href="shipping.php">delivery policy</a>. Please make sure the address and phone number you
  give us are correct and that someone can receive the order in the slot you choose — fresh fish
  cannot wait at the door.
</p>

<h2>7. Food safety and your responsibility</h2>
<p>
  We clean, cut and pack the fish on ice the same morning. Once it reaches you, please refrigerate
  it immediately and cook it the same day. We cannot take responsibility for fish that has been
  left out of refrigeration after delivery.
</p>
<p>
  If you have a seafood allergy, please do not rely on this site — our fish is handled in a shared
  market space and cross-contact between species is possible.
</p>

<h2>8. Cancellations and refunds</h2>
<p>
  Fresh fish is perishable, so cancellation is only possible within a short window. The full rules,
  including what happens if you are unhappy with the quality, are in our
  <a href="refund.php">refunds and cancellations policy</a>.
</p>

<h2>9. Your account details and misuse</h2>
<p>
  Please do not place fake or repeated orders you do not intend to collect, use someone else's
  payment details, or try to break, scrape or overload this website. We may refuse service to
  anyone doing so.
</p>

<h2>10. Limits on what we are responsible for</h2>
<p>
  We will always try to put a genuine problem right. Beyond that, our responsibility for any
  order is limited to the amount you paid for that order. We are not responsible for losses
  outside our reasonable control — rough seas, fishing bans, strikes, power or internet failures,
  or a payment provider being down.
</p>

<h2>11. Changes to these terms</h2>
<p>
  We may update these terms as the business changes. The version on this page at the time you
  place an order is the one that applies to it.
</p>

<h2>12. Governing law</h2>
<p>
  These terms are governed by the laws of India, and the courts of Goa have jurisdiction over
  any dispute.
</p>

<h2>13. Contact</h2>
<p>
  Questions about these terms? See our <a href="contact.php">contact page</a> — WhatsApp is the
  quickest way to reach us.
</p>

<?php page_end(); ?>
