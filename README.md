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
assets/js/script.js      basket, checkout, Razorpay
api/                     JSON endpoints called by the browser and Razorpay
admin/                   seller dashboard (orders, today's catch, settings)
includes/                shared PHP (blocked from the browser)
  config.sample.php      template for the secret config file
  bootstrap.php          loads config, helpers
  db.php                 MySQL connection
  settings.php           shop settings + booking cutoff
  razorpay.php           Razorpay API via cURL (no Composer needed)
  orders.php             mark paid, reduce stock, notify
  send_notifications.php HTML email (PHPMailer) + WhatsApp (UltraMsg / Meta / Twilio)
  lib/PHPMailer/         PHPMailer 6.9.3
database/schema.sql      tables + sample fish
```

Secret keys live in `morning-catch-config.php` **outside** `public_html`, never in this repo.
