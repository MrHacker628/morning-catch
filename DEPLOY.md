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
   - `db` → the database name, user and password from step 3
   - `admin` → `password` (pick a long one)
   - `seller` → the seller's WhatsApp number (`91` + 10 digits) and email
5. Leave `mail.transport` = `'log'` and `whatsapp.provider` = `'log'` for the first test.

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

1. Razorpay: finish **KYC** / account activation.
2. Switch the dashboard to **Live Mode** → generate **Live** keys (`rzp_live_…`) → put them in the config.
3. Add the webhook again in **Live Mode** (test and live webhooks are separate).
4. Make sure `shop.ignore_cutoff` is `false`.
5. Do one real ₹-small order yourself and refund it from the Razorpay dashboard.

---

## The seller's daily routine

Open **`https://yourdomain.com/admin`** on the phone and log in.

- **Today's catch:** set each fish's price, "Landed" and "Left" quantity. Untick "On sale" for anything not caught today. Tap **Save**.
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
