<?php
/* =====================================================================
   privacy.php — Privacy policy

   Razorpay asks for this page before it will activate a live account,
   and India's DPDP Act 2023 expects a named contact for complaints.

   A sensible starting point, not legal advice. Check it against what
   the seller actually does with customer details.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/page.php';

$b       = business();
$shop    = $b['shop_name'];
$contact = $b['email'] !== '' ? $b['email'] : ($b['phone'] !== '' ? $b['phone'] : 'the contact details on our contact page');

page_start('Privacy policy', 'What we collect when you book fish here, why we need it, and who else sees it.');
?>

<p>
  <strong><?= h($b['legal_name']) ?></strong> runs this website. We only ask for what we need to
  get your fish to you, and we do not sell your details to anyone.
</p>

<h2>1. What we collect</h2>
<ul>
  <li><strong>Your name, WhatsApp number and email</strong> — so we can confirm the order and send your receipt.</li>
  <li><strong>Your delivery address</strong> — only if you choose home delivery. We do not ask for it for stall pickup.</li>
  <li><strong>Your order</strong> — the items, quantities, cuts, chosen slot and any note you leave for the seller.</li>
  <li><strong>Payment reference</strong> — the Razorpay order and payment IDs, so we can match your payment and issue a refund if needed.</li>
</ul>
<p>
  We never see or store your card number, CVV, UPI PIN or bank login. Those go straight to
  Razorpay, which is certified to handle them.
</p>

<h2>2. What stays on your own phone</h2>
<p>
  To save you typing the same thing every morning, this site keeps your basket and — if you tick
  "remember my details" — your name, phone and address in your browser's own storage on your
  device. That information stays on your phone, is not sent to us until you place an order, and
  you can clear it any time by clearing your browser data or unticking the box.
</p>
<p>
  We do not use advertising cookies and we do not track you across other websites.
</p>

<h2>3. Why we use it</h2>
<ul>
  <li>To prepare, pack and deliver the order you paid for.</li>
  <li>To send you a receipt and order updates on WhatsApp and email.</li>
  <li>To handle refunds, quality complaints and payment disputes.</li>
  <li>To keep the shop's own sales and stock records, as any business must.</li>
</ul>

<h2>4. Who else sees it</h2>
<p>We share only what each of these needs to do its job:</p>
<ul>
  <li><strong>Razorpay</strong> — to take the payment and process refunds.</li>
  <li><strong>Our email provider</strong> — to deliver your receipt.</li>
  <li><strong>Our WhatsApp message provider</strong> — to deliver your receipt on WhatsApp.</li>
  <li><strong>Our web host</strong> — which stores the site and its database.</li>
  <li><strong>The person delivering your order</strong> — who gets your name, address, phone and slot, and nothing else.</li>
</ul>
<p>
  We will also share details if the law or a court requires it. We do not sell or rent your
  details, and we do not pass them to any other seller.
</p>

<h2>5. Messages you will get</h2>
<p>
  When you place an order you will get a receipt and delivery updates on WhatsApp and email,
  because they are part of the order itself. If we ever start sending a daily "today's catch"
  message, it will be opt-in, and every such message will tell you how to stop it. Reply
  <strong>STOP</strong> and we will take your number off that list.
</p>

<h2>6. How long we keep it</h2>
<p>
  Order records are kept for as long as we need them for accounts, tax and payment-dispute
  purposes — normally up to eight years, as Indian accounting rules expect. If you ask us to
  delete your details and there is no order still open, we will remove your name, phone, email
  and address and keep only the anonymous sales figures.
</p>

<h2>7. Keeping it safe</h2>
<p>
  The site runs over HTTPS, the database sits behind a password that is not published anywhere,
  and the seller's admin page needs its own password. No website can promise perfect security,
  but we keep the amount of information we hold deliberately small.
</p>

<h2>8. Children</h2>
<p>
  This site is meant for adults placing household orders. We do not knowingly collect details
  from children.
</p>

<h2>9. Your choices</h2>
<p>You can ask us at any time to:</p>
<ul>
  <li>tell you what details we hold about you,</li>
  <li>correct something that is wrong,</li>
  <li>delete your details, or</li>
  <li>stop sending you messages that are not about an order.</li>
</ul>
<p>
  Write to <?= $b['email'] !== '' ? '<a href="mailto:' . h($b['email']) . '">' . h($b['email']) . '</a>' : h($contact) ?>
  and we will reply within a few working days.
</p>

<h2>10. Complaints</h2>
<p>
  If you are unhappy with how your details have been handled, contact
  <?= $b['owner_name'] !== '' ? '<strong>' . h($b['owner_name']) . '</strong>, ' : '' ?>the owner of
  <?= h($b['legal_name']) ?>, through our <a href="contact.php">contact page</a>. We would much
  rather hear it from you directly and put it right.
</p>

<h2>11. Changes</h2>
<p>
  If this policy changes, the new version will appear on this page with a new date at the top.
</p>

<?php page_end(); ?>
