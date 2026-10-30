<?php
/* =====================================================================
   business.php — the seller's identity: legal name, address, contact
   details, FSSAI number, delivery area.

   Everything here comes from the `business` block of the config file,
   so a new seller is set up by editing the config — never the HTML.
   Empty values are simply left out of the page.
   ===================================================================== */

declare(strict_types=1);

/** The business block, with sensible fallbacks. */
function business(): array
{
    static $b = null;
    if ($b !== null) return $b;

    $shopName = (string) cfg('shop.name', 'Morning Catch');

    $b = [
        'shop_name'       => $shopName,
        'legal_name'      => trim((string) cfg('business.legal_name', '')) ?: $shopName,
        'owner_name'      => trim((string) cfg('business.owner_name', '')),
        'tagline'         => trim((string) cfg('business.tagline', '')),
        'address'         => trim((string) cfg('business.address', (string) cfg('shop.pickup_address', ''))),
        'phone'           => trim((string) cfg('business.phone', '')),
        'whatsapp'        => preg_replace('/\D+/', '', (string) cfg('business.whatsapp', '')) ?: '',
        'email'           => trim((string) cfg('business.email', (string) cfg('mail.reply_to', ''))),
        'hours'           => trim((string) cfg('business.hours', '')),
        'fssai'           => trim((string) cfg('business.fssai', '')),
        'gstin'           => trim((string) cfg('business.gstin', '')),
        'delivery_areas'  => trim((string) cfg('business.delivery_areas', '')),
        'delivery_note'   => trim((string) cfg('business.delivery_note', '')),
        'site_url'        => rtrim((string) cfg('shop.site_url', ''), '/'),
        'pickup_address'  => trim((string) cfg('shop.pickup_address', '')),
    ];
    return $b;
}

/** The postal address split into lines, for a multi-line <address>. */
function business_address_lines(): array
{
    $address = business()['address'];
    if ($address === '') return [];
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,(?=\s*[A-Z])/', $address) ?: [])));
}

/** wa.me link for the seller, or '' when no WhatsApp number is configured. */
function whatsapp_link(string $message = ''): string
{
    $number = business()['whatsapp'];
    if ($number === '') return '';
    return 'https://wa.me/' . $number . ($message !== '' ? '?text=' . rawurlencode($message) : '');
}

/**
 * The legal pages, in footer order: file => label.
 * Razorpay asks for all five before it will activate a live account.
 */
function legal_pages(): array
{
    return [
        'terms.php'    => 'Terms & conditions',
        'privacy.php'  => 'Privacy policy',
        'refund.php'   => 'Refunds & cancellations',
        'shipping.php' => 'Delivery policy',
        'contact.php'  => 'Contact us',
    ];
}

/**
 * The parts of the business details that are safe to send to the
 * browser — used by api/get_products.php to fill the footer, so the
 * HTML carries no seller-specific text.
 */
function business_public(): array
{
    $b = business();
    return [
        'SHOP_NAME'      => $b['shop_name'],
        'LEGAL_NAME'     => $b['legal_name'],
        'TAGLINE'        => $b['tagline'],
        'ADDRESS_LINES'  => business_address_lines(),
        'PHONE'          => $b['phone'],
        'WHATSAPP_LINK'  => whatsapp_link("Hi! I have a question about today's catch."),
        'EMAIL'          => $b['email'],
        'HOURS'          => $b['hours'],
        'FSSAI'          => $b['fssai'],
        'GSTIN'          => $b['gstin'],
        'LEGAL_PAGES'    => legal_pages(),
    ];
}
