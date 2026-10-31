# Morning Catch

Fish pre-booking site for a local fish seller. Buyers book this morning's catch, pay with Razorpay, and get a receipt by email and WhatsApp. The seller manages stock and orders from `/admin`.

**Setup and going live: see [DEPLOY.md](DEPLOY.md).**

## How it works

1. `index.html` + `assets/js/script.js` show today's catch from `api/get_products.php`.
2. Pay → `api/create_order.php` prices the basket from the database and creates a Razorpay order.
3. Razorpay Checkout → `api/verify_payment.php` checks the signature, marks the order paid, reduces stock, and sends the receipts.
4. `api/razorpay_webhook.php` does the same if the buyer's phone drops off right after paying.

Opening `index.html` without the PHP backend runs **preview mode** (sample fish, simulated payment).

## Folders

```
index.html               shop page
setup-check.php          one-page host check (delete once live)
terms.php                terms & conditions          ┐
privacy.php              privacy policy              │ Razorpay will not
refund.php               refunds & cancellations     │ activate a live
shipping.php             delivery policy             │ account without
contact.php              contact us                  ┘ these five
assets/js/script.js      basket, checkout, Razorpay
assets/js/tailwind-config.js  one colour palette for every page
assets/img/products/     fish photos the seller uploads from /admin
api/                     JSON endpoints called by the browser and Razorpay
admin/                   seller dashboard (orders, today's catch, settings)
includes/                shared PHP (blocked from the browser)
  config.sample.php      template for the secret config file
  bootstrap.php          loads config, helpers
  db.php                 MySQL connection
  settings.php           shop settings + booking cutoff
  business.php           the seller's name, address, FSSAI, contact details
  societies.php          apartment-society batch delivery and its threshold
  page.php               header/footer shared by the five policy pages
  photos.php             resizes and stores uploaded fish photos
  razorpay.php           Razorpay API via cURL (no Composer needed)
  orders.php             mark paid, reduce stock, notify
  send_notifications.php HTML email (PHPMailer) + WhatsApp (UltraMsg / Meta / Twilio)
  lib/PHPMailer/         PHPMailer 6.9.3
database/schema.sql      tables + sample fish
```

## Setting it up for a seller

Everything that identifies the shop — name, tagline, address, phone, WhatsApp, email, opening
hours, FSSAI and GST numbers, delivery area — lives in the `business` block of the config file.
The footer, the five policy pages and the contact page all read from there, so **you never edit
HTML to rebrand the site**. Anything left blank is simply left off the page.

Secret keys live in `morning-catch-config.php` **outside** `public_html`, never in this repo.

## Society batch delivery

Delivering one 500 g order to a 20-flat block costs more than it earns, so flats
order individually and the van waits. Once `shop.society_threshold` different
flats in the same society have ordered for the day (3 by default), delivery is
free for all of them and the van makes one stop at `shop.society_slot`.

The threshold is decided on the server from the database, never from the
browser, and distinct **flats** are counted — one flat ordering three times does
not unlock free delivery for the building. Flats who paid before the threshold
was reached are listed in `/admin` → Orders as money to hand back.

Set the threshold to `1` to give every society order free delivery outright.

## Photos

The seller adds a photo per fish from `/admin` → *Today's catch*. Uploads are checked, turned the
right way up from the phone's EXIF data, scaled to 1400px and re-encoded as WebP, then stored in
`assets/img/products/` under a random name. That folder can't execute anything, and the photos
are not committed to this repo — they stay on the seller's own server.
