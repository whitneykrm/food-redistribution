<?php
function saveListingImage(array $file): array
{
    $maxBytes = 3 * 1024 * 1024; // 3 MB (the dashboard shrinks photos before upload)

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return [false, 'That photo is too large. Please choose one under 3 MB.'];
        }
        return [false, 'The photo could not be uploaded. Please try again.'];
    }
    if ($file['size'] > $maxBytes) {
        return [false, 'That photo is too large. Please choose one under 3 MB.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return [false, 'Invalid upload.'];
    }

    // Check the real file type, not the name the browser sent
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
        return [false, 'Please upload a JPG, PNG or WebP image.'];
    }

    $dir = __DIR__ . '/../uploads/listings';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return [false, 'Could not create the uploads folder.'];
    }

    // Uploaded files must never be run as code
    $htaccess = __DIR__ . '/../uploads/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "<FilesMatch \"\\.(php|phtml|phar|php[0-9])$\">\n    Require all denied\n</FilesMatch>\nOptions -Indexes\n");
    }

    // Random name, so two vendors can never overwrite each other's photos
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return [false, 'Could not save the photo.'];
    }
    return [true, 'uploads/listings/' . $name];
}

/** Deletes a previously saved listing photo (only inside uploads/listings). */
function deleteListingImage(?string $path): void
{
    if (!$path || strpos($path, 'uploads/listings/') !== 0 || strpos($path, '..') !== false) {
        return;
    }
    $full = __DIR__ . '/../' . $path;
    if (is_file($full)) {
        @unlink($full);
    }
}