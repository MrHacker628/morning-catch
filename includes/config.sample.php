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

        // --- Apartment society batch delivery ---------------------------
        // Flats in a listed society order one by one; once this many
        // DIFFERENT flats in the same society have ordered for the day,
        // the van makes one stop and delivery is free for all of them.
        // Set to 1 to give every society order free delivery outright.
        'society_threshold' => 3,
        // The single evening window the batch van runs in.
        'society_slot'      => '5:00 – 6:30 PM',

        // true = ignore the cutoff time (handy while testing in the evening)
        'ignore_cutoff' => false,
    ],

    /* -----------------------------------------------------------------
       BUSINESS IDENTITY — who the seller is, legally and on paper.

       These appear in the footer, on the five legal pages and on
       receipts. Razorpay checks the legal name, address, contact details
       and the policy pages during account activation, so fill all of
       this in with the seller's REAL details before switching to live
       keys. An empty value simply hides that line on the site.
       ----------------------------------------------------------------- */
    'business' => [
        // Name on the Razorpay account / shop licence. Falls back to shop.name.
        'legal_name' => 'Morning Catch Fish Supply',
        'owner_name' => 'Seller full name',
        'tagline'    => 'Fresh off the boat, kept aside for you',

        // Full postal address, one line per line break. Required by Razorpay.
        'address'    => "Stall 12, Municipal Fish Market\nPanaji, Goa 403001\nIndia",

        'phone'      => '',          // '+91 98765 43210' — shown on Contact us
        'whatsapp'   => '',          // '919876543210' (with 91, no +) — the "Chat on WhatsApp" button
        'email'      => '',          // 'orders@yourdomain.com'
        'hours'      => 'Every day, 5:30 AM – 12:00 PM',

        // FSSAI registration number — legally required on a food business
        // website in India. Get it at foscos.fssai.gov.in (Basic
        // Registration is enough for a small seller).
        'fssai'      => '',          // e.g. '11523998000123'

        // Leave empty if the seller is not GST registered (most small
        // fish sellers are not — fresh fish is GST-exempt anyway).
        'gstin'      => '',

        // Where the seller delivers, and how long it takes. Used on the
        // shipping/delivery policy page.
        'delivery_areas' => 'Panaji, Taleigao, Dona Paula and Porvorim',
        'delivery_note'  => 'Everything is delivered the same morning, in the slot chosen at checkout.',
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
         'brevo' → send over HTTPS through Brevo's API. Use this on free or
                   shared hosting, which usually blocks the SMTP ports and
                   PHP's mail(). Free tier covers a small shop. Sign up at
                   brevo.com, verify your from address under Senders &
                   Domains, then SMTP & API → API Keys → create one and
                   paste it into brevo_api_key below.
         'smtp'  → a real mailbox. Good on Hostinger (hPanel → Emails).
         'mail'  → PHP mail(). Often lands in spam, blocked on free hosts.
         'log'   → don't send; write the email to the log file (for testing).
                   Nothing reaches the customer and the receipt screen says so.
       ----------------------------------------------------------------- */
    'mail' => [
        'transport'  => 'smtp',

        // Only for transport 'brevo'
        'brevo_api_key' => 'YOUR_BREVO_API_KEY',
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
