<?php
/* =====================================================================
   db.php — MySQL connection (PDO)

   Usage anywhere:   $pdo = db();
   The connection is created once per request and reused.

   Why PDO with prepared statements? Every value goes in as a "?"
   placeholder, so customer input can never break or inject SQL.

   db()          — the shop cannot work without the database, so this
                   stops the request with a clear error if it is down.
   db_optional() — returns null instead, for pages that should still
                   open when MySQL is unreachable (the legal pages).
   ===================================================================== */

declare(strict_types=1);

function db(): PDO
{
    $pdo = db_optional();
    if ($pdo instanceof PDO) return $pdo;

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Could not connect to the database. Please try again shortly.']);
    exit;
}

/** The same connection, but null instead of a fatal error when it fails. */
function db_optional(): ?PDO
{
    static $pdo    = null;
    static $tried  = false;
    if ($pdo instanceof PDO) return $pdo;
    if ($tried) return null;                 // don't retry a dead connection every call
    $tried = true;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // throw on any SQL error
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // rows as ['column' => value]
        PDO::ATTR_EMULATE_PREPARES   => false,                    // real prepared statements
    ];

    // Optional full DSN override (used for automated tests); normally leave unset.
    $dsn = cfg('db.dsn');

    if (!$dsn) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            cfg('db.host', 'localhost'),
            cfg('db.name')
        );
    }

    try {
        $pdo = new PDO($dsn, cfg('db.user'), cfg('db.password'), $options);
        if (str_starts_with($dsn, 'mysql:')) {
            // Keep MySQL timestamps in India time to match PHP
            $pdo->exec("SET time_zone = '+05:30'");
        }
    } catch (PDOException $e) {
        log_msg('DB connection failed: ' . $e->getMessage());
        return null;
    }

    return $pdo;
}
