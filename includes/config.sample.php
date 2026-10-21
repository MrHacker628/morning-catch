<?php
/* =====================================================================
   MORNING CATCH — CONFIGURATION (secret keys + shop settings)

   ⚠️  THIS IS ONLY A TEMPLATE. Do not put real keys in this file.

   HOW TO USE
   ----------
   ON HOSTINGER (live site):
     1. hPanel → File Manager. Go ONE LEVEL ABOVE public_html
        (the folder that contains public_html, usually
         /home/u123456789/domains/yourdomain.com/).
     2. Create a new file there named:   morning-catch-config.php
     3. Paste the contents of this file into it and fill in your real values.
     Because it sits outside public_html:
       • nobody can open it in a browser, and
       • a GitHub push/auto-deploy can never overwrite or publish it.

   ON YOUR LAPTOP (XAMPP):
     Copy this file to includes/config.php and fill it in.
     includes/config.php is listed in .gitignore, so it never reaches GitHub.

   The app looks for the config in this order:
     1. ../morning-catch-config.php   (outside public_html — recommended)
     2. includes/config.php           (local testing)
   ===================================================================== */

return [

    /* -----------------------------------------------------------------
       SHOP
       ----------------------------------------------------------------- */
    'shop' => [
        'name'     => 'Morning Catch',
        'site_url' => 'https://yourdomain.com',        // no trailing slash
        'timezone' => 'Asia/Kolkata',

        // Defaults — the seller can change these any day from /admin
        'landed_at'           => '5:45 AM',
        'cutoff'              => '10:30',              // 24h HH:MM, bookings close after this
        'delivery_fee'        => 40,                   // ₹
        'free_delivery_above' => 999,                  // ₹ (0 = never free)

        // Time slots shown at checkout (the server only accepts these)
        'slots' => [
            'delivery' => ['7:00 – 8:00 AM', '8:00 – 9:00 AM', '9:00 – 10:00 AM', '10:00 – 11:00 AM', '11:00 AM – 12:00 PM'],
            'pickup'   => ['7:00 – 9:00 AM', '9:00 – 11:00 AM', '11:00 AM – 1:00 PM'],
        ],

        // Where the seller's stall is (shown on pickup receipts)
        'pickup_address' => 'Stall 12, Fish Market, Panaji',

        // true = ignore the cutoff time (handy while testing in the evening)
        'ignore_cutoff' => false,
    ],

    /* -----------------------------------------------------------------
       DATABASE — hPanel → Databases → MySQL Databases
       Hostinger names look like u123456789_morningcatch
       ----------------------------------------------------------------- */
    'db' => [
        'host'     => 'localhost',
        'name'     => 'u123456789_morningcatch',
        'user'     => 'u123456789_mcuser',
        'password' => 'YOUR_DB_PASSWORD',
    ],

    /* -----------------------------------------------------------------
       RAZORPAY — dashboard.razorpay.com → Account & Settings → API Keys
       Start with TEST keys (rzp_test_...). Switch to LIVE keys
       (rzp_live_...) only after KYC is approved and testing is done.
       ----------------------------------------------------------------- */
    'razorpay' => [
        'key_id'         => 'rzp_test_XXXXXXXXXXXXXX',
        'key_secret'     => 'YOUR_RAZORPAY_KEY_SECRET',
        // Dashboard → Webhooks → Add: https://yourdomain.com/api/razorpay_webhook.php
        // Events: payment.captured, order.paid. Paste the secret you chose here.
        'webhook_secret' => 'YOUR_WEBHOOK_SECRET',
    ],

    /* -----------------------------------------------------------------
       EMAIL RECEIPTS
       transport:
         'smtp' → recommended. Create a mailbox in hPanel → Emails
                  (e.g. orders@yourdomain.com) and use its password.
         'mail' → PHP mail(). Works on Hostinger but often lands in spam.
         'log'  → don't send; write the email to the log file (for testing).
       ----------------------------------------------------------------- */
    'mail' => [
        'transport'  => 'smtp',
        'host'       => 'smtp.hostinger.com',
        'port'       => 465,
        'secure'     => 'ssl',                         // 'ssl' for 465, 'tls' for 587
        'username'   => 'orders@yourdomain.com',
        'password'   => 'YOUR_EMAIL_PASSWORD',
        'from_email' => 'orders@yourdomain.com',
        'from_name'  => 'Morning Catch',
        'reply_to'   => 'orders@yourdomain.com',
    ],

    /* -----------------------------------------------------------------
       WHATSAPP RECEIPTS
       provider: 'ultramsg' | 'meta' | 'twilio' | 'log' | 'none'
         'log' → don't send; write the message to the log file (for testing)
       Only fill in the block for the provider you use.
       ----------------------------------------------------------------- */
    'whatsapp' => [
        'provider' => 'log',

        // UltraMsg — ultramsg.com → your instance page
        'ultramsg' => [
            'instance_id' => 'instance12345',
            'token'       => 'YOUR_ULTRAMSG_TOKEN',
        ],

        // Meta WhatsApp Cloud API — developers.facebook.com → your app → WhatsApp → API Setup
        // Needs an APPROVED template (see DEPLOY.md for the exact template text).
        'meta' => [
            'phone_number_id' => '123456789012345',
            'access_token'    => 'YOUR_PERMANENT_ACCESS_TOKEN',
            'template_name'   => 'order_receipt',
            'template_lang'   => 'en',
            'graph_version'   => 'v21.0',
        ],

        // Twilio — console.twilio.com
        'twilio' => [
            'account_sid' => 'ACXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
            'auth_token'  => 'YOUR_TWILIO_AUTH_TOKEN',
            'from'        => '+14155238886',           // your Twilio WhatsApp number
        ],
    ],

    /* -----------------------------------------------------------------
       SELLER ALERTS — the seller gets a WhatsApp + email for every new
       paid order (leave empty to switch off).
       ----------------------------------------------------------------- */
    'seller' => [
        'whatsapp' => '',                              // e.g. '919876543210' (with 91, no +)
        'email'    => '',                              // e.g. 'seller@gmail.com'
    ],

    /* -----------------------------------------------------------------
       ADMIN PAGE (yourdomain.com/admin) — pick a long password
       ----------------------------------------------------------------- */
    'admin' => [
        'password' => 'CHANGE_ME_to_a_long_password',
    ],

    /* -----------------------------------------------------------------
       LOG FILE — errors and 'log' mode messages go here.
       null = next to this config file (outside public_html).
       ----------------------------------------------------------------- */
    'log_file' => null,
];
