<?php
/* =====================================================================
   bootstrap.php — loaded first by every PHP page.
   Finds the config file, sets the timezone, and provides small helpers
   used everywhere (JSON replies, logging, money formatting).
   ===================================================================== */

declare(strict_types=1);

// Never show PHP errors to customers — log them instead.
ini_set('display_errors', '0');
error_reporting(E_ALL);

/* ---------------------------------------------------------------------
   1. Find and load the config file
   --------------------------------------------------------------------- */
(function () {
    $candidates = [
        getenv('MC_CONFIG') ?: null,                              // optional override
        dirname(__DIR__, 2) . '/morning-catch-config.php',        // outside public_html (live)
        __DIR__ . '/config.php',                                  // local testing
    ];
    foreach ($candidates as $file) {
        if ($file && is_file($file)) {
            $GLOBALS['MC_CONFIG']      = require $file;
            $GLOBALS['MC_CONFIG_FILE'] = $file;
            return;
        }
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Shop is not configured yet. See DEPLOY.md (config file missing).']);
    exit;
})();

date_default_timezone_set(cfg('shop.timezone', 'Asia/Kolkata'));

/** Read a config value with dot notation: cfg('razorpay.key_id') */
function cfg(string $path, $default = null)
{
    $value = $GLOBALS['MC_CONFIG'];
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) return $default;
        $value = $value[$key];
    }
    return $value;
}

/* ---------------------------------------------------------------------
   2. Logging — to a file outside public_html by default
   --------------------------------------------------------------------- */
function log_msg(string $message, array $context = []): void
{
    $file = cfg('log_file') ?: dirname($GLOBALS['MC_CONFIG_FILE']) . '/morning-catch.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context) $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

set_exception_handler(function (Throwable $e) {
    log_msg('UNCAUGHT ' . get_class($e) . ': ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'message' => 'Something went wrong on our side. Please try again in a minute.']);
});

/* ---------------------------------------------------------------------
   3. JSON helpers for the api/ endpoints
   --------------------------------------------------------------------- */
function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_out(['success' => false, 'message' => $message], $status);
}

/** Read the JSON body sent by script.js */
function json_in(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_error('Use POST.', 405);
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 20000) json_error('Request too large.', 413);
    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Invalid request.');
    return $data;
}

/* ---------------------------------------------------------------------
   4. Formatting helpers (used in receipts)
   --------------------------------------------------------------------- */

/** ₹1,23,456 or ₹1,234.50 — Indian digit grouping */
function inr($amount): string
{
    $amount = round((float) $amount, 2);
    $decimals = fmod($amount, 1.0) == 0.0 ? '' : '.' . substr(number_format($amount, 2, '.', ''), -2);
    $int = (string) (int) floor($amount);
    if (strlen($int) > 3) {
        $last3 = substr($int, -3);
        $rest  = substr($int, 0, -3);
        $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int   = $rest . ',' . $last3;
    }
    return '₹' . $int . $decimals;
}

/** 0.5 kg → "500 g", 1.5 kg → "1.5 kg", 2 piece → "2 pcs" */
function qty_label($qty, string $unit): string
{
    $qty = (float) $qty;
    if ($unit === 'piece') return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') . ($qty == 1.0 ? ' pc' : ' pcs');
    if ($qty < 1) return (int) round($qty * 1000) . ' g';
    return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') . ' kg';
}

/** '10:30' → '10:30 AM'. Falls back to the input if it isn't HH:MM. */
function pretty_time(string $hhmm): string
{
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) return $hhmm;
    $ts = mktime((int) $m[1], (int) $m[2], 0, 1, 1, 2000);
    return $ts === false ? $hhmm : date('g:i A', $ts);
}

/** Current time as a MySQL DATETIME string, in the shop's timezone */
function now_sql(): string
{
    return date('Y-m-d H:i:s');
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/business.php';
