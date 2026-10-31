# Going live on Hostinger

Follow these in order. Each step says where to click.

You need a Hostinger plan that has **PHP 8.1 or newer** and **MySQL** (Premium or Business shared hosting both do), plus a domain pointed at it.

---

## 1. Turn on HTTPS and check PHP

1. hPanel → **Websites** → **Dashboard** → **Security** → **SSL** → install the free SSL.
2. hPanel → **Advanced** → **PHP Configuration** → choose **PHP 8.2** (8.1+ works).

## 2. Connect GitHub (auto-deploy)

1. hPanel → **Websites** → **Dashboard** → **Advanced** → **Git**.
2. **Connect with GitHub** → allow access to `MrHacker628/morning-catch`.
3. Branch `main`, folder `public_html` → **Deploy**. Keep **Auto-deployment** on.

If Hostinger put a default `default.php` or `index.php` in `public_html`, delete it in File Manager first.

From now on, `git push` updates the live site within a minute or so.

## 3. Create the database

1. hPanel → **Databases** → **MySQL Databases** → create a database and a user. Write down all three names and the password.
   Hostinger adds a prefix, so they look like `u123456789_morningcatch`.
2. hPanel → **Databases** → **phpMyAdmin** → open your new database → **Import** → choose `database/schema.sql` from your laptop → **Import**.

You should now see 4 tables: `products`, `orders`, `order_items`, `settings`, with 7 sample fish.

## 4. Create the config file (your secret keys)

The keys never go on GitHub. They live in one file outside `public_html`.

1. hPanel → **File Manager**. Open `public_html`, then click the folder **one level up** (it is usually `domains/yourdomain.com`).
2. Create a new file there named exactly **`morning-catch-config.php`**.
3. Open `public_html/includes/config.sample.php`, copy everything, paste it into your new file.
4. Fill in for now:
   - `shop` → `name`, `site_url`, `pickup_address`
   - `business` → the seller's **real** legal name, address, phone, WhatsApp, email, opening hours and **FSSAI number**. These fill the footer, the five policy pages and the contact page — you never edit any HTML to rebrand the site. Razorpay checks them during activation, so do not leave them as the samples.
   - `db` → the database name, user and password from step 3
   - `admin` → `password` (pick a long one)
   - `seller` → the seller's WhatsApp number (`91` + 10 digits) and email
5. Leave `mail.transport` = `'log'` and `whatsapp.provider` = `'log'` for the first test.

Anything in `business` you leave blank is simply left off the page — so an unregistered seller with no GST number gets a footer with no GST line, not an empty one.

### Photo folder permissions

The seller uploads fish photos from `/admin`, so `public_html/assets/img/products` has to be writable. In **File Manager**, right-click it → **Permissions** → set **755**. The folder ships with its own `.htaccess` that stops anything in it from ever being executed — leave that file alone.

Open `https://yourdomain.com/api/get_products.php`. You should see the fish list as text (JSON). If it says "Shop is not configured yet", the file name or folder is wrong.

## 5. Razorpay (start in Test Mode)

1. Sign up at [dashboard.razorpay.com](https://dashboard.razorpay.com). Make sure the toggle at the top says **Test Mode**.
2. **Account & Settings** → **API Keys** → **Generate Test Key**. Copy the Key ID (`rzp_test_…`) and Key Secret into `razorpay.key_id` and `razorpay.key_secret`.
3. **Account & Settings** → **Webhooks** → **Add New Webhook**:
   - URL: `https://yourdomain.com/api/razorpay_webhook.php`
   - Secret: make up a long random text and paste the same text into `razorpay.webhook_secret`
   - Events: tick **payment.captured** and **order.paid**
4. Keep **automatic capture** on (it is the default).

**Test a payment:** open your site, book a fish, pay. In test mode no real money moves. Choose Netbanking and press **Success** on Razorpay's test bank page.

You should see the green "It's yours!" screen. In **File Manager** next to your config file there is now `morning-catch.log`: it contains the email and WhatsApp messages that *would* have been sent.

## 6. Email receipts

### On free or shared hosting, use Brevo (not SMTP)

Free hosts almost always block the SMTP ports (25, 465, 587) and disable PHP's
`mail()`, so a receipt simply never leaves the server. Port 443 is open though —
the shop already talks to Razorpay over it — so send email through an HTTPS API
instead.

1. Sign up at [brevo.com](https://www.brevo.com). The free tier covers a small
   shop; check the current daily limit when you sign up.
2. **Senders, Domains & Dedicated IPs** → add your from address and verify it.
   Brevo will not send from an address it has not verified — this is the single
   most common reason a first attempt fails.
3. **SMTP & API** → **API Keys** → create a key.
4. In your config file:
   ```php
   'mail' => [
       'transport'     => 'brevo',
       'brevo_api_key' => 'xkeysib-...',
       'from_email'    => 'the address you verified',
       'from_name'     => 'Morning Catch',
       'reply_to'      => 'where replies should go',
   ],
   ```
5. Place a test order. If no email arrives, open the log file next to your config
   — the failure is written there in plain words (rejected key, unverified
   sender, or the host blocking outbound).

### On normal hosting, SMTP is fine


1. hPanel → **Emails** → create a mailbox such as `orders@yourdomain.com`.
2. In the config file:
   ```php
   'transport'  => 'smtp',
   'host'       => 'smtp.hostinger.com',
   'port'       => 465,
   'secure'     => 'ssl',
   'username'   => 'orders@yourdomain.com',
   'password'   => 'the mailbox password',
   'from_email' => 'orders@yourdomain.com',
   ```
3. Place a test order with your own email. Check the spam folder the first time and mark it "Not spam".

## 7. WhatsApp receipts — pick one

### Option A: UltraMsg (quickest)

1. Sign up at [ultramsg.com](https://ultramsg.com), create an instance, and scan the QR code with the **seller's business WhatsApp** (like WhatsApp Web).
2. Copy the **Instance ID** and **Token** into the config:
   ```php
   'provider' => 'ultramsg',
   'ultramsg' => ['instance_id' => 'instance12345', 'token' => '...'],
   ```

UltraMsg is not an official WhatsApp product. Use a separate business number, not a personal one.

### Option B: Meta WhatsApp Cloud API (official)

1. [developers.facebook.com](https://developers.facebook.com) → create an app → add **WhatsApp**. Add and verify the business phone number.
2. **WhatsApp Manager** → **Message templates** → create a template:
   - Name: `order_receipt` · Category: **Utility** · Language: **English**
   - Body:
     ```
     Hi {{1}}, thank you for your order! 🐟
     Order ID: {{2}}
     Items: {{3}}
     Total paid: {{4}}
     {{5}}
     We are keeping your fish aside. Show this Order ID when you collect.
     ```
   - Sample values: `Anita` · `MC-211026-0042` · `Kingfish · Curry cut — 1 kg — ₹900` · `₹900` · `Delivery today, 8:00 – 9:00 AM`
3. Wait for approval (usually minutes to a day). Create a **System User** permanent access token.
4. In the config:
   ```php
   'provider' => 'meta',
   'meta' => ['phone_number_id' => '...', 'access_token' => '...', 'template_name' => 'order_receipt', 'template_lang' => 'en', 'graph_version' => 'v21.0'],
   ```

With Meta, the seller's own "new order" WhatsApp only arrives if the seller has messaged the business number in the last 24 hours. The seller's email alert always works.

### Option C: Twilio

Fill in `twilio` → `account_sid`, `auth_token`, `from` and set `'provider' => 'twilio'`. Twilio's sandbox works for testing; for real customers Twilio also needs an approved template.

## 8. Go live

**Before you apply for activation**, open these five pages and read them with the seller. Razorpay will not activate a live account without them, and they must describe how the seller actually works:

| Page | What Razorpay calls it |
|---|---|
| `https://yourdomain.com/terms.php` | Terms & Conditions |
| `https://yourdomain.com/privacy.php` | Privacy Policy |
| `https://yourdomain.com/refund.php` | Refund & Cancellation Policy |
| `https://yourdomain.com/shipping.php` | Shipping / Delivery Policy |
| `https://yourdomain.com/contact.php` | Contact Us |

They are filled from the `business` block of your config file, so correcting the seller's address or FSSAI number fixes all five at once. The text itself is a sensible starting point for a small fish business — it is **not legal advice**, so read it through and change anything that does not match reality (delivery area, complaint window, refund timings).

The seller also needs an **FSSAI registration** to sell food in India. Basic Registration is enough for a small stall and is applied for at [foscos.fssai.gov.in](https://foscos.fssai.gov.in). Put the number in `business.fssai` — it then shows in the footer and on the contact page, which is where a customer (and an inspector) expects it.

1. Razorpay: finish **KYC** / account activation.
2. Switch the dashboard to **Live Mode** → generate **Live** keys (`rzp_live_…`) → put them in the config.
3. Add the webhook again in **Live Mode** (test and live webhooks are separate).
4. Make sure `shop.ignore_cutoff` is `false`.
5. Do one real ₹-small order yourself and refund it from the Razorpay dashboard.

---

## Showing it to a client before they pay for hosting

A client wants to see the seller dashboard and a real payment screen, and neither
runs on static hosting. Put a throwaway copy on a free PHP host for the pitch, then
move it to the seller's own hosting once they say yes.

### The one thing that decides whether a free host will do

The shop calls `api.razorpay.com` from the server. Many free hosts block outbound
connections, and when they do, **no payment can ever work there** — it is not
something you can configure around. Everything else (PHP version, extensions,
permissions) is usually fixable; this one is not.

So check it before you spend an evening uploading. That is what `setup-check.php`
is for.

### Steps

1. **Sign up** for a free host that runs PHP 8 and MySQL. InfinityFree, Byet.host
   and TinkerHost are the usual ones. You get a free subdomain, which is fine for a
   demo.
2. **Turn on the free SSL certificate** for that subdomain. Razorpay's checkout
   will not load over plain HTTP.
3. **Create a MySQL database** and note the database name, user, password and
   **hostname**. On free hosts the hostname is almost never `localhost` — copy the
   exact one the panel shows, e.g. `sql123.epizy.com`.
4. **Import `database/schema.sql`** through phpMyAdmin → Import.
5. **Upload the site** into `htdocs` (or `public_html`). Turn on **"show hidden
   files"** in the file manager first — the `.htaccess` files are what protect
   `includes/` and the photo folder, and most file managers skip them silently.
6. **Create the config file.** Free hosts rarely let you write above the web root,
   so put it at `includes/config.php` instead. The `.htaccess` in that folder
   blocks it from the browser. Fill in `db`, `admin.password`, `business` and your
   Razorpay **test** keys.
7. **Set `assets/img/products` to 755** so photo uploads can be saved.
8. **Open `https://yoursubdomain/setup-check.php`** and type your admin password.

### Reading the result

The page checks the PHP version, every extension the shop needs, the database and
its four tables, the photo folder, your shop details, the five policy pages, and —
the important one — it makes a real read-only call to Razorpay with your keys.

- **"Razorpay answered"** — outbound works and your keys are good. This host will do.
- **"This host cannot look up api.razorpay.com"** or **"Connection to Razorpay timed
  out"** — the host blocks outbound connections. Move to a different host; nothing
  you change here will fix it.
- **"Razorpay refused the keys"** — the host is fine, your keys are wrong. Copy them
  from the dashboard again.

Fix the red rows, re-run it, then place one test order end to end.

**Delete `setup-check.php` once you are done.** It is a setup tool, and leaving it
up tells strangers what your server runs.

### What to expect from free hosting

- Free plans suspend accounts during traffic spikes. Fine for one client looking at
  it; not somewhere to take real orders.
- Email sending is usually blocked or heavily limited, so leave
  `mail.transport` as `'log'` and show the receipt screen rather than a delivered
  email.
- Treat it as a demo you throw away. The real install goes on the seller's own
  hosting, following the steps above this section.

---

## The seller's daily routine

Open **`https://yourdomain.com/admin`** on the phone and log in.

- **Today's catch:** set each fish's price, "Landed" and "Left" quantity. Untick "On sale" for anything not caught today. Tap **Save**.
- **Charging for cleaning:** in a fish's *More details*, write the cut list with a `+` and a price — `Whole,Cleaned+50,Curry cut+60`. That adds ₹50 per kg for cleaning and ₹60 per kg for curry cut, on top of the fish price; `Whole` stays free. For fish sold by the piece the charge is per piece. The shopper sees `Cleaned (+₹50/kg)` in the dropdown and the price updates before they pay.
- **Photos:** on the same tab, each fish has an **Add a photo** button that opens the phone camera. Shoot the fish on the ice in daylight — the site shrinks and converts it for you, so a 5 MB phone photo becomes a ~40 KB image that loads fast on mobile data. Tick **Remove this photo** to go back to the drawing.
- **Settings:** update "Catch landed at". On days with no catch, switch **Taking bookings today** off.
- **Orders:** the packing list. The top box shows the total of each fish to keep aside. Change each order to *Kept aside* → *Out for delivery* → *Done*. Use **Resend receipt** if a buyer says they didn't get it.

Stock goes down by itself every time someone pays.

---

## Trying it on your laptop (XAMPP)

1. Install [XAMPP](https://www.apachefriends.org) and start **Apache** and **MySQL**.
2. Copy the project folder to `C:\xampp\htdocs\morning-catch`.
3. Open `http://localhost/phpmyadmin` → create database `morningcatch` → **Import** `database/schema.sql`.
4. Copy `includes/config.sample.php` to `includes/config.php` and fill in:
   `db` → host `localhost`, name `morningcatch`, user `root`, password `''` (empty); Razorpay test keys; set `ignore_cutoff` to `true`.
5. Open `http://localhost/morning-catch`.

`includes/config.php` is git-ignored, so it never reaches GitHub.

---

## Something not working?

| What you see | What to check |
|---|---|
| "Shop is not configured yet" | Config file name/location (step 4) |
| "Could not connect to the database" | `db` values in the config; the user must be added to the database |
| Yellow "Preview mode" strip on the live site | `api/get_products.php` is failing — open it directly to see the message |
| "Payment could not be started" | Razorpay keys in the config; test keys need Test Mode |
| Paid, but no email/WhatsApp | Open `morning-catch.log` (next to the config file). Every failed send is written there with the reason |
| Old design still showing after a push | hPanel → **Cache Manager** → purge, or `Ctrl + Shift + R` |
