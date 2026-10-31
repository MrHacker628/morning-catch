<?php
/* =====================================================================
   photos.php — product photos uploaded from /admin

   A fish stall sells on how the fish looks, so the seller needs to be
   able to photograph the ice box on their phone and have it on the site
   a minute later. This file takes that photo and makes it safe and small:

     • checks it really is an image (not a PHP file with a .jpg name)
     • turns a phone photo the right way up using its EXIF orientation
     • scales it down to at most 1400px and re-encodes it as WebP,
       which throws away anything hidden inside the original file
     • saves it under a random name in assets/img/products/

   That folder also carries its own .htaccess so nothing in it can ever
   be executed, even if someone finds a way to put a script there.
   ===================================================================== */

declare(strict_types=1);

const PHOTO_MAX_BYTES = 12 * 1024 * 1024;   // 12 MB — a phone photo is 2–6 MB
const PHOTO_MAX_SIDE  = 1400;               // px, longest side
const PHOTO_QUALITY   = 82;

/** Absolute path of the folder photos live in. */
function photo_dir(): string
{
    return dirname(__DIR__) . '/assets/img/products';
}

/** Make sure the folder exists and cannot run scripts. */
function photo_dir_prepare(): bool
{
    $dir = photo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;

    // An older version of this file wrote a guard containing directives that
    // LiteSpeed and most shared hosts refuse ("Options ... -ExecCGI",
    // "php_flag"). A refused directive makes the server return 500 for every
    // photo in the folder, so repair that guard in place rather than leaving
    // the shop with broken images until someone edits it by hand.
    $guard = $dir . '/.htaccess';
    $stale = is_file($guard)
        && preg_match('/ExecCGI|php_flag/i', (string) @file_get_contents($guard)) === 1;

    if (!is_file($guard) || $stale) {
        // Same guard that ships in the repo, in case the folder was recreated.
        // Keep this minimal: shared hosts refuse "Options" beyond -Indexes and
        // refuse php_flag here, and a refused directive makes the server return
        // 500 for every photo in the folder. Blocking the executable extensions
        // is what actually does the work, and it is accepted everywhere.
        @file_put_contents($guard, implode("\n", [
            '# Uploaded fish photos live here. Images only — nothing may be executed.',
            '<FilesMatch "\\.(php|phps|phar|phtml|pht|inc|pl|py|cgi|sh|bash|html?)$">',
            '    Require all denied',
            '</FilesMatch>',
            '',
        ]));
    }
    return is_writable($dir);
}

/** 'Silver Pomfret' → 'silver-pomfret' (used only to make filenames readable) */
function photo_slug(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? substr($slug, 0, 40) : 'fish';
}

/**
 * Was a file actually chosen in this form field?
 * An untouched file input still arrives, with error = UPLOAD_ERR_NO_FILE.
 */
function photo_was_chosen(?array $file): bool
{
    return $file !== null
        && isset($file['error'])
        && (int) $file['error'] !== UPLOAD_ERR_NO_FILE;
}

/**
 * Store an uploaded photo.
 *
 * @return array{0: ?string, 1: string}  [web path or null, error message]
 *         The web path is relative to the site root, e.g.
 *         'assets/img/products/kingfish-9f2a1b7c.webp'.
 */
function photo_store(array $file, string $productName): array
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return [null, 'That photo is too big. Please send one under 12 MB.'];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return [null, 'The photo did not upload properly. Please try again.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return [null, 'The photo did not upload properly. Please try again.'];
    }
    if ((int) ($file['size'] ?? 0) > PHOTO_MAX_BYTES) {
        return [null, 'That photo is too big. Please send one under 12 MB.'];
    }
    if (!function_exists('imagecreatefromstring')) {
        return [null, 'This server cannot resize images (the GD extension is off).'];
    }

    // Must really be an image, and one of the types a phone produces.
    $info = @getimagesize($tmp);
    $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
    if (!$info || !in_array($info[2] ?? 0, $allowed, true)) {
        return [null, 'That file is not a photo. Use a JPG, PNG or WebP.'];
    }

    $raw = @file_get_contents($tmp);
    if ($raw === false) return [null, 'Could not read the photo. Please try again.'];

    $img = @imagecreatefromstring($raw);
    if (!$img) return [null, 'Could not read that photo. Please try another one.'];

    $img = photo_fix_orientation($img, $tmp, (int) $info[2]);
    $img = photo_scale_down($img);

    if (!photo_dir_prepare()) {
        imagedestroy($img);
        return [null, 'The photo folder is not writable. Set assets/img/products to 755.'];
    }

    $webp = function_exists('imagewebp');
    $name = photo_slug($productName) . '-' . bin2hex(random_bytes(4)) . ($webp ? '.webp' : '.jpg');
    $path = photo_dir() . '/' . $name;

    imageinterlace($img, true);          // progressive JPEG — shows up sooner on slow data
    $ok = $webp ? @imagewebp($img, $path, PHOTO_QUALITY) : @imagejpeg($img, $path, PHOTO_QUALITY);
    imagedestroy($img);

    if (!$ok) return [null, 'Could not save the photo. Please try again.'];
    @chmod($path, 0644);

    return ['assets/img/products/' . $name, ''];
}

/** Turn a phone photo the right way up before we throw the EXIF data away. */
function photo_fix_orientation(GdImage $img, string $tmp, int $type): GdImage
{
    if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) return $img;

    $exif = @exif_read_data($tmp);
    $orientation = (int) ($exif['Orientation'] ?? 0);
    $rotate = match ($orientation) {
        3 => 180,
        6 => -90,
        8 => 90,
        default => 0,
    };
    if ($rotate === 0) return $img;

    $rotated = @imagerotate($img, (float) $rotate, 0);
    if (!$rotated) return $img;
    imagedestroy($img);
    return $rotated;
}

/** Scale down so the longest side is at most PHOTO_MAX_SIDE. */
function photo_scale_down(GdImage $img): GdImage
{
    $w = imagesx($img);
    $h = imagesy($img);
    $longest = max($w, $h);
    if ($longest <= PHOTO_MAX_SIDE) return $img;

    $ratio  = PHOTO_MAX_SIDE / $longest;
    $scaled = @imagescale($img, (int) round($w * $ratio), (int) round($h * $ratio));
    if (!$scaled) return $img;
    imagedestroy($img);
    return $scaled;
}

/**
 * Delete a stored photo. Only ever touches files inside the photo
 * folder, so a tampered image_url cannot delete anything else.
 */
function photo_delete(?string $webPath): void
{
    $webPath = trim((string) $webPath);
    if ($webPath === '' || !str_starts_with($webPath, 'assets/img/products/')) return;

    $name = basename($webPath);
    if ($name === '' || str_contains($name, '..')) return;

    $full = photo_dir() . '/' . $name;
    if (is_file($full)) @unlink($full);
}
