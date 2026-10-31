<?php
/* =====================================================================
   send_notifications.php — Email + WhatsApp receipts

   Public functions:
     send_email_receipt($order)          → bool
     send_whatsapp_receipt($order)       → bool
     send_seller_alert($order, $oversold)

   $order is the array from find_order(): the orders row + ['items'].

   Every function returns false (and logs why) instead of throwing, so a
   broken WhatsApp token can never break a customer's payment.

   Switch providers in the config file:
     mail.transport     = smtp | mail | log
     whatsapp.provider  = ultramsg | meta | twilio | log | none
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/lib/PHPMailer/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

/* =====================================================================
   PART A — shared receipt text
   ===================================================================== */

/** "Kingfish · Curry cut — 1 kg — ₹900" for each item */
function receipt_item_lines(array $order): array
{
    return array_map(function ($i) {
        $cut = $i['cut_type'] ? ' · ' . $i['cut_type'] : '';
        if ((float) ($i['cut_fee'] ?? 0) > 0) $cut .= ' (' . cut_fee_label((float) $i['cut_fee'], $i['unit']) . ')';
        return $i['product_name'] . $cut . ' — ' . qty_label($i['quantity'], $i['unit']) . ' — ' . inr($i['line_total']);
    }, $order['items']);
}

/** "Delivery today, 8:00 – 9:00 AM" / "Pickup today, 7:00 – 9:00 AM at Stall 12…" */
function receipt_when_where(array $order): string
{
    if ($order['fulfilment'] === 'pickup') {
        $where = cfg('shop.pickup_address');
        return 'Pickup today, ' . $order['delivery_slot'] . ($where ? ' at ' . $where : '');
    }
    return 'Delivery today, ' . $order['delivery_slot'];
}

/* =====================================================================
   PART B — EMAIL
   ===================================================================== */

function send_email_receipt(array $order): bool
{
    $shop    = cfg('shop.name', 'Morning Catch');
    $subject = "Your {$shop} receipt — Order {$order['order_ref']}";
    return send_mail(
        $order['customer_email'],
        $order['customer_name'],
        $subject,
        email_receipt_html($order),
        email_receipt_text($order)
    );
}

/**
 * Low-level sender. Uses Brevo's HTTPS API, PHPMailer over SMTP,
 * PHP mail(), or just writes to the log in 'log' mode.
 */
function send_mail(string $toEmail, string $toName, string $subject, string $html, string $text): bool
{
    $transport = cfg('mail.transport', 'smtp');

    if ($transport === 'log') {
        // Write it out, but report NOT sent: nothing reached the customer, and
        // the success screen must not tell them a receipt is on its way.
        log_msg("EMAIL (log mode, NOT sent) to {$toEmail}: {$subject}\n{$text}");
        return false;
    }

    if ($transport === 'brevo') {
        return send_mail_brevo($toEmail, $toName, $subject, $html, $text);
    }

    $mail = new PHPMailer(true);   // true = throw exceptions on errors
    try {
        if ($transport === 'smtp') {
            $mail->isSMTP();
            $mail->Host       = cfg('mail.host', 'smtp.hostinger.com');
            $mail->Port       = (int) cfg('mail.port', 465);
            $mail->SMTPAuth   = true;
            $mail->Username   = cfg('mail.username');
            $mail->Password   = cfg('mail.password');
            $mail->SMTPSecure = cfg('mail.secure', 'ssl') === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $mail->Timeout    = 15;
        } else {
            $mail->isMail();       // PHP's built-in mail()
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom(cfg('mail.from_email'), cfg('mail.from_name', cfg('shop.name')));
        if (cfg('mail.reply_to')) $mail->addReplyTo(cfg('mail.reply_to'));
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;    // plain-text version for simple mail apps

        $mail->send();
        return true;
    } catch (Throwable $e) {
        log_msg('EMAIL FAILED to ' . $toEmail . ': ' . $mail->ErrorInfo . ' ' . $e->getMessage());
        return false;
    }
}

/** Plain-text email body */
function email_receipt_text(array $order): string
{
    $shop = cfg('shop.name', 'Morning Catch');
    $t  = "Hi {$order['customer_name']},\n\n";
    $t .= "Thank you! Your payment was received and your fish is being kept aside.\n\n";
    $t .= "Order ID: {$order['order_ref']}\n\n";
    foreach (receipt_item_lines($order) as $line) $t .= "- {$line}\n";
    $t .= "\nFish total: " . inr($order['subtotal']) . "\n";
    $t .= 'Delivery: ' . ((float) $order['delivery_fee'] > 0 ? inr($order['delivery_fee']) : 'Free') . "\n";
    $t .= 'TOTAL PAID: ' . inr($order['total_amount']) . "\n\n";
    $t .= receipt_when_where($order) . "\n";
    if ($order['fulfilment'] === 'delivery' && $order['delivery_address']) $t .= "Address: {$order['delivery_address']}\n";
    if (society_refund_pending($order)) $t .= "\n" . society_refund_promise() . "\n";
    $t .= "\nPayment ID: {$order['razorpay_payment_id']}\n\n— {$shop}\n";
    return $t;
}

/** HTML email body — table layout + inline styles so it looks right in Gmail/Outlook */
function email_receipt_html(array $order): string
{
    $shop  = h(cfg('shop.name', 'Morning Catch'));
    $name  = h($order['customer_name']);
    $ref   = h($order['order_ref']);
    $when  = h(receipt_when_where($order));
    $date  = h(date('l, j F Y', strtotime((string) ($order['paid_at'] ?: 'now'))));
    $addr  = $order['fulfilment'] === 'delivery' && $order['delivery_address']
        ? '<p style="margin:6px 0 0;color:#33505C;font-size:14px;">' . nl2br(h($order['delivery_address'])) . '</p>' : '';
    // Society orders that paid delivery: put the promise in writing, so the
    // buyer has a record of it and not just a line on a checkout screen.
    $addr .= society_refund_pending($order)
        ? '<p style="margin:10px 0 0;padding:10px 12px;background:#FDF1D6;border-radius:10px;color:#8A5D06;font-size:13px;">'
          . h(society_refund_promise()) . '</p>' : '';
    $notes = $order['notes'] ? '<p style="margin:6px 0 0;color:#33505C;font-size:14px;">Note: ' . h($order['notes']) . '</p>' : '';

    $rows = '';
    foreach ($order['items'] as $i) {
        $rows .= '<tr>
          <td style="padding:12px 0;border-bottom:1px solid #F1E8DA;font-size:15px;color:#0B2733;">
            <strong>' . h($i['product_name']) . '</strong><br>
            <span style="color:#33505C;font-size:13px;">' . h($i['cut_type'] ?? '') . ' · ' . h(qty_label($i['quantity'], $i['unit'])) . ' × ' . h(inr($i['unit_price'])) . ((float) ($i['cut_fee'] ?? 0) > 0 ? ' ' . h(cut_fee_label((float) $i['cut_fee'], $i['unit'])) : '') . '</span>
          </td>
          <td align="right" style="padding:12px 0;border-bottom:1px solid #F1E8DA;font-size:15px;color:#0B2733;white-space:nowrap;">' . h(inr($i['line_total'])) . '</td>
        </tr>';
    }

    $delivery = (float) $order['delivery_fee'] > 0 ? h(inr($order['delivery_fee'])) : 'Free';
    $sub      = h(inr($order['subtotal']));
    $total    = h(inr($order['total_amount']));
    $payId    = h($order['razorpay_payment_id']);

    return <<<HTML
<!DOCTYPE html>
<html><body style="margin:0;padding:0;background:#FBF6EE;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FBF6EE;padding:24px 12px;">
<tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:20px;overflow:hidden;">
    <tr><td style="background:#0B2733;padding:28px 28px 24px;">
      <p style="margin:0;color:#F5B53F;font-size:12px;letter-spacing:2px;text-transform:uppercase;font-weight:bold;">{$shop}</p>
      <h1 style="margin:8px 0 0;color:#FBF6EE;font-family:Georgia,serif;font-size:28px;">Payment received</h1>
      <p style="margin:6px 0 0;color:#C9D6DA;font-size:14px;">{$date}</p>
    </td></tr>

    <tr><td style="padding:24px 28px 8px;">
      <p style="margin:0;font-size:16px;color:#0B2733;">Hi {$name},</p>
      <p style="margin:8px 0 0;font-size:15px;color:#33505C;line-height:1.5;">Thank you! Your fish is being kept aside for you.</p>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0 4px;background:#FDF1D6;border-radius:12px;">
        <tr><td style="padding:14px 16px;font-size:12px;color:#33505C;text-transform:uppercase;letter-spacing:1px;">Order ID</td>
            <td align="right" style="padding:14px 16px;font-size:18px;font-weight:bold;color:#0B2733;font-family:'Courier New',monospace;">{$ref}</td></tr>
      </table>
    </td></tr>

    <tr><td style="padding:0 28px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0">{$rows}
        <tr><td style="padding:12px 0 2px;font-size:14px;color:#33505C;">Fish total</td><td align="right" style="padding:12px 0 2px;font-size:14px;color:#33505C;">{$sub}</td></tr>
        <tr><td style="padding:2px 0;font-size:14px;color:#33505C;">Delivery</td><td align="right" style="padding:2px 0;font-size:14px;color:#33505C;">{$delivery}</td></tr>
        <tr><td style="padding:10px 0 0;font-size:18px;font-weight:bold;color:#0B2733;">Total paid</td><td align="right" style="padding:10px 0 0;font-size:18px;font-weight:bold;color:#F2643D;">{$total}</td></tr>
      </table>
    </td></tr>

    <tr><td style="padding:20px 28px 8px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#DDF0EE;border-radius:12px;">
        <tr><td style="padding:14px 16px;">
          <p style="margin:0;font-size:15px;font-weight:bold;color:#0E6B74;">{$when}</p>
          {$addr}{$notes}
        </td></tr>
      </table>
    </td></tr>

    <tr><td style="padding:16px 28px 28px;">
      <p style="margin:0;font-size:13px;color:#33505C;line-height:1.5;">Show your Order ID when you collect. Payment ID: {$payId}</p>
    </td></tr>
  </table>
  <p style="margin:16px 0 0;font-size:12px;color:#8A9AA0;">{$shop} · Fresh fish, booked online</p>
</td></tr>
</table>
</body></html>
HTML;
}

/* =====================================================================
   PART C — WHATSAPP
   ===================================================================== */

function send_whatsapp_receipt(array $order): bool
{
    $shop = cfg('shop.name', 'Morning Catch');

    // Free-text version (UltraMsg, Twilio, log)
    $msg  = "🐟 *{$shop}* — Payment received\n\n";
    $msg .= "Hi {$order['customer_name']}, thank you for your order!\n\n";
    $msg .= "*Order ID:* {$order['order_ref']}\n\n";
    foreach (receipt_item_lines($order) as $line) $msg .= "• {$line}\n";
    if ((float) $order['delivery_fee'] > 0) $msg .= 'Delivery: ' . inr($order['delivery_fee']) . "\n";
    $msg .= "\n*Total paid: " . inr($order['total_amount']) . "*\n\n";
    $msg .= ($order['fulfilment'] === 'pickup' ? '🏪 ' : '🛵 ') . receipt_when_where($order) . "\n";
    $msg .= "\nWe're keeping your fish aside. Show this Order ID when you collect.";

    // Template version (Meta). Template variables can't contain line breaks,
    // so items are joined with commas. Order must match the approved template:
    // {{1}} name  {{2}} order ID  {{3}} items  {{4}} total  {{5}} when/where
    $templateParams = [
        $order['customer_name'],
        $order['order_ref'],
        implode(', ', receipt_item_lines($order)),
        inr($order['total_amount']),
        receipt_when_where($order),
    ];

    return send_whatsapp($order['customer_phone'], $msg, $templateParams);
}

/**
 * Send one WhatsApp message through the configured provider.
 * @param string     $to              number with country code, digits only: 919876543210
 * @param string     $text            free-text message
 * @param array|null $templateParams  used by Meta (template messages)
 */
function send_whatsapp(string $to, string $text, ?array $templateParams = null): bool
{
    $to       = preg_replace('/\D/', '', $to);
    $provider = cfg('whatsapp.provider', 'none');

    switch ($provider) {
        case 'none':
            return false;

        case 'log':
            // As above: logged for inspection, but reported as not delivered.
            log_msg("WHATSAPP (log mode, NOT sent) to +{$to}:\n{$text}");
            return false;

        /* ---------- UltraMsg: https://docs.ultramsg.com ---------- */
        case 'ultramsg':
            $instance = cfg('whatsapp.ultramsg.instance_id');
            [$status, $body] = http_post(
                "https://api.ultramsg.com/{$instance}/messages/chat",
                http_build_query([
                    'token' => cfg('whatsapp.ultramsg.token'),
                    'to'    => '+' . $to,
                    'body'  => $text,
                ]),
                ['Content-Type: application/x-www-form-urlencoded']
            );
            // UltraMsg replies {"sent":"true","message":"ok","id":...}
            $ok = $status === 200 && (str_contains((string) $body, '"sent":"true"') || str_contains((string) $body, '"sent":true'));
            break;

        /* ---------- Meta WhatsApp Cloud API (template message) ---------- */
        case 'meta':
            $version = cfg('whatsapp.meta.graph_version', 'v21.0');
            $phoneId = cfg('whatsapp.meta.phone_number_id');
            $payload = $templateParams === null
                ? ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $text]]
                : [
                    'messaging_product' => 'whatsapp',
                    'to'       => $to,
                    'type'     => 'template',
                    'template' => [
                        'name'       => cfg('whatsapp.meta.template_name', 'order_receipt'),
                        'language'   => ['code' => cfg('whatsapp.meta.template_lang', 'en')],
                        'components' => [[
                            'type'       => 'body',
                            'parameters' => array_map(
                                // Meta rejects newlines/tabs and long runs of spaces inside variables
                                fn ($p) => ['type' => 'text', 'text' => preg_replace('/\s+/', ' ', (string) $p)],
                                $templateParams
                            ),
                        ]],
                    ],
                ];
            [$status, $body] = http_post(
                "https://graph.facebook.com/{$version}/{$phoneId}/messages",
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                ['Content-Type: application/json', 'Authorization: Bearer ' . cfg('whatsapp.meta.access_token')]
            );
            $ok = $status === 200 && str_contains((string) $body, '"messages"');
            break;

        /* ---------- Twilio: https://www.twilio.com/docs/whatsapp ---------- */
        case 'twilio':
            $sid = cfg('whatsapp.twilio.account_sid');
            [$status, $body] = http_post(
                "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json",
                http_build_query([
                    'From' => 'whatsapp:' . cfg('whatsapp.twilio.from'),
                    'To'   => 'whatsapp:+' . $to,
                    'Body' => $text,
                ]),
                ['Content-Type: application/x-www-form-urlencoded'],
                $sid . ':' . cfg('whatsapp.twilio.auth_token')
            );
            $ok = $status === 201 || $status === 200;
            break;

        default:
            log_msg("WHATSAPP: unknown provider '{$provider}'");
            return false;
    }

    if (!$ok) log_msg("WHATSAPP FAILED via {$provider} to +{$to}", ['status' => $status, 'response' => mb_substr((string) $body, 0, 500)]);
    return $ok;
}

/** Small cURL POST helper → [httpStatus, responseBody] */
/**
 * Send through Brevo's transactional email API over plain HTTPS.
 *
 * Why this exists: shared and free hosting usually blocks the SMTP ports
 * (25, 465, 587) and often disables PHP's mail() as well, so neither of the
 * other transports can get a receipt out. Port 443 is always open — the shop
 * already talks to Razorpay over it — so an HTTPS email API works where SMTP
 * cannot.
 *
 * Setup: brevo.com → Senders & Domains → verify the from address, then
 * SMTP & API → API Keys → create one, and put it in mail.brevo_api_key.
 * Brevo will not send from an address it has not verified.
 */
function send_mail_brevo(string $toEmail, string $toName, string $subject, string $html, string $text): bool
{
    $key = trim((string) cfg('mail.brevo_api_key', ''));
    if ($key === '' || str_starts_with($key, 'YOUR_')) {
        log_msg('EMAIL not sent: mail.transport is brevo but mail.brevo_api_key is empty.');
        return false;
    }

    $from = (string) cfg('mail.from_email', '');
    if ($from === '') {
        log_msg('EMAIL not sent: mail.from_email is empty.');
        return false;
    }

    $payload = [
        'sender'      => ['name' => (string) cfg('mail.from_name', cfg('shop.name', 'Morning Catch')), 'email' => $from],
        'to'          => [['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail]],
        'subject'     => $subject,
        'htmlContent' => $html,
        'textContent' => $text,
    ];
    if ($replyTo = trim((string) cfg('mail.reply_to', ''))) {
        $payload['replyTo'] = ['email' => $replyTo];
    }

    // api_base override is only for tests; normally leave it unset.
    $base = rtrim((string) cfg('mail.brevo_api_base', 'https://api.brevo.com'), '/');

    [$status, $body] = http_post(
        $base . '/v3/smtp/email',
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        ['Content-Type: application/json', 'Accept: application/json', 'api-key: ' . $key]
    );

    if ($status >= 200 && $status < 300) return true;

    // Say something useful: an unverified sender and a bad key look identical
    // from the outside otherwise, and both are common on a first setup.
    $hint = match (true) {
        $status === 401 => ' — the API key was rejected. Copy it again from Brevo → SMTP & API → API Keys.',
        $status === 400 && str_contains($body, 'sender') => " — Brevo will not send from {$from}. Verify that address under Senders & Domains first.",
        $status === 0   => ' — could not reach Brevo at all. The host may be blocking outbound HTTPS.',
        default         => '',
    };
    log_msg("EMAIL failed via Brevo (HTTP {$status}){$hint}", ['response' => substr($body, 0, 300)]);
    return false;
}

function http_post(string $url, string $body, array $headers = [], ?string $basicAuth = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 15,
    ];
    if ($basicAuth) $opts[CURLOPT_USERPWD] = $basicAuth;
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) $response = 'cURL error: ' . curl_error($ch);
    curl_close($ch);
    return [$status, $response];
}

/* =====================================================================
   PART D — SELLER ALERT (new paid order)
   ===================================================================== */

function send_seller_alert(array $order, array $oversold = []): void
{
    $lines = implode("\n", array_map(fn ($l) => "• {$l}", receipt_item_lines($order)));
    $text  = "🔔 *New order {$order['order_ref']}* — " . inr($order['total_amount']) . " paid\n\n";
    $text .= "{$order['customer_name']} · +{$order['customer_phone']}\n";
    $text .= receipt_when_where($order) . "\n";
    if ($order['fulfilment'] === 'delivery') $text .= "📍 {$order['delivery_address']}\n";
    if (society_refund_pending($order)) $text .= "\n🏢 " . society_refund_promise() . "\n";
    if ($order['notes']) $text .= "📝 {$order['notes']}\n";
    $text .= "\n{$lines}";
    if ($oversold) {
        $text .= "\n\n⚠️ OVERSOLD: " . implode(', ', $oversold) . " — not enough stock left. Call the customer or refund from Razorpay.";
    }

    // WhatsApp to seller. Note for Meta: a free-text message only arrives if the
    // seller has messaged your business number in the last 24 hours.
    $sellerPhone = (string) cfg('seller.whatsapp', '');
    if ($sellerPhone !== '' && cfg('whatsapp.provider') !== 'none') {
        send_whatsapp($sellerPhone, $text, null);
    }

    $sellerEmail = (string) cfg('seller.email', '');
    if ($sellerEmail !== '') {
        $subject = ($oversold ? '⚠️ OVERSOLD — ' : '') . "New order {$order['order_ref']} — " . inr($order['total_amount']);
        $html = '<pre style="font-family:Arial,sans-serif;font-size:15px;white-space:pre-wrap;">' . h(str_replace('*', '', $text)) . '</pre>';
        send_mail($sellerEmail, 'Seller', $subject, $html, str_replace('*', '', $text));
    }
}
