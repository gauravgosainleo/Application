<?php
/*
 * Safe file uploads: type sniffing, size limits, random names and
 * GD re-encoding (strips EXIF/GPS data, fixes rotation, resizes).
 */

class Upload
{
    const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    const VIDEO_TYPES = [
        'video/mp4'       => 'mp4',
        'application/mp4' => 'mp4',
        'video/x-m4v'     => 'mp4',
        'video/webm'      => 'webm',
        'video/quicktime' => 'mov',
    ];

    public static function has($field)
    {
        return isset($_FILES[$field]) && is_array($_FILES[$field])
            && !is_array($_FILES[$field]['error'])
            && $_FILES[$field]['error'] !== UPLOAD_ERR_NO_FILE;
    }

    private static function check($field, $maxBytes)
    {
        if (!self::has($field)) {
            fail('Please choose a file to upload.', 422);
        }
        $f = $_FILES[$field];
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            fail('The file is too large for the server upload limit.', 413);
        }
        if ($f['error'] !== UPLOAD_ERR_OK) {
            fail('Upload failed (error code ' . (int)$f['error'] . '). Please try again.', 400);
        }
        if ($f['size'] > $maxBytes) {
            fail('The file is too large (max ' . round($maxBytes / 1048576) . ' MB).', 413);
        }
        if (!is_uploaded_file($f['tmp_name'])) {
            fail('Invalid upload.', 400);
        }
        return $f;
    }

    private static function mime($path)
    {
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($fi, $path);
            finfo_close($fi);
            if ($mime) {
                return strtolower($mime);
            }
        }
        if (function_exists('mime_content_type')) {
            return strtolower((string)mime_content_type($path));
        }
        $info = @getimagesize($path);
        return $info ? strtolower($info['mime']) : 'application/octet-stream';
    }

    private static function dir($folder)
    {
        $folder = preg_replace('/[^a-z0-9_-]/', '', strtolower($folder));
        $dir = WEB_DIR . '/uploads/' . $folder;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            fail('The upload folder uploads/' . $folder . ' is not writable on the server.', 500, 'upload_dir');
        }
        return $dir;
    }

    /**
     * Stores an uploaded image and returns its relative path ("uploads/<folder>/<file>").
     * Options: max (longest side, default 1920), square (crop to a square of this size).
     */
    public static function image($field, $folder, array $opts = [])
    {
        $f = self::check($field, (int)cfg('uploads.max_image_mb', 8) * 1048576);
        $mime = self::mime($f['tmp_name']);
        if (!isset(self::IMAGE_TYPES[$mime])) {
            fail('Only JPG, PNG, GIF or WEBP images are allowed.', 415);
        }
        $info = @getimagesize($f['tmp_name']);
        if (!$info || $info[0] < 1 || $info[1] < 1) {
            fail('The image file is damaged or not supported.', 415);
        }
        if ($info[0] * $info[1] > 50000000) {
            fail('The image dimensions are too large.', 413);
        }
        $dir = self::dir($folder);
        $name = bin2hex(random_bytes(12));
        $maxDim = isset($opts['max']) ? (int)$opts['max'] : 1920;
        $square = isset($opts['square']) ? (int)$opts['square'] : 0;

        if (function_exists('imagecreatetruecolor') && ($mime !== 'image/gif' || $square)) {
            $saved = self::reencode($f['tmp_name'], $mime, $dir . '/' . $name, $maxDim, $square);
            if ($saved) {
                return 'uploads/' . basename($dir) . '/' . basename($saved);
            }
        }
        $dest = $dir . '/' . $name . '.' . self::IMAGE_TYPES[$mime];
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            fail('Could not save the uploaded file.', 500);
        }
        @chmod($dest, 0644);
        return 'uploads/' . basename($dir) . '/' . basename($dest);
    }

    private static function reencode($src, $mime, $destNoExt, $maxDim, $square)
    {
        $img = null;
        if (($mime === 'image/jpeg' || $mime === 'image/pjpeg') && function_exists('imagecreatefromjpeg')) {
            $img = @imagecreatefromjpeg($src);
        } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
            $img = @imagecreatefrompng($src);
        } elseif ($mime === 'image/gif' && function_exists('imagecreatefromgif')) {
            $img = @imagecreatefromgif($src);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $img = @imagecreatefromwebp($src);
        }
        if (!$img) {
            return null;
        }

        if (($mime === 'image/jpeg' || $mime === 'image/pjpeg') && function_exists('exif_read_data')) {
            $exif = @exif_read_data($src);
            $orientation = isset($exif['Orientation']) ? (int)$exif['Orientation'] : 1;
            $angle = [3 => 180, 6 => -90, 8 => 90];
            if (isset($angle[$orientation])) {
                $rotated = imagerotate($img, $angle[$orientation], 0);
                if ($rotated) {
                    imagedestroy($img);
                    $img = $rotated;
                }
            }
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $sx = 0;
        $sy = 0;
        $sw = $w;
        $sh = $h;
        if ($square > 0) {
            $side = min($w, $h);
            $sx = (int)(($w - $side) / 2);
            $sy = (int)(($h - $side) / 2);
            $sw = $sh = $side;
            $tw = $th = min($side, $square);
        } else {
            $scale = min(1, $maxDim / max($w, $h));
            $tw = max(1, (int)round($w * $scale));
            $th = max(1, (int)round($h * $scale));
        }

        $keepAlpha = $mime === 'image/png' || $mime === 'image/gif' || $mime === 'image/webp';
        $dst = imagecreatetruecolor($tw, $th);
        if ($keepAlpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefilledrectangle($dst, 0, 0, $tw, $th, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $img, 0, 0, $sx, $sy, $tw, $th, $sw, $sh);
        imagedestroy($img);

        if ($mime === 'image/webp' && function_exists('imagewebp')) {
            $path = $destNoExt . '.webp';
            $ok = imagewebp($dst, $path, 85);
        } elseif ($keepAlpha) {
            $path = $destNoExt . '.png';
            $ok = imagepng($dst, $path, 7);
        } else {
            $path = $destNoExt . '.jpg';
            imageinterlace($dst, true);
            $ok = imagejpeg($dst, $path, 85);
        }
        imagedestroy($dst);
        if (!$ok) {
            return null;
        }
        @chmod($path, 0644);
        return $path;
    }

    /** Stores an uploaded video file (mp4 / webm / mov). */
    public static function video($field, $folder)
    {
        $f = self::check($field, (int)cfg('uploads.max_video_mb', 40) * 1048576);
        $mime = self::mime($f['tmp_name']);
        if (!isset(self::VIDEO_TYPES[$mime])) {
            fail('Only MP4, WEBM or MOV videos are allowed.', 415);
        }
        $dir = self::dir($folder);
        $dest = $dir . '/' . bin2hex(random_bytes(12)) . '.' . self::VIDEO_TYPES[$mime];
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            fail('Could not save the uploaded video.', 500);
        }
        @chmod($dest, 0644);
        return 'uploads/' . basename($dir) . '/' . basename($dest);
    }

    /** Detects the MIME type of an uploaded field without storing it. */
    public static function typeOf($field)
    {
        return self::has($field) ? self::mime($_FILES[$field]['tmp_name']) : null;
    }

    /** Deletes a previously uploaded file (only inside uploads/). */
    public static function delete($relPath)
    {
        if (!$relPath || strpos($relPath, 'uploads/') !== 0 || strpos($relPath, '..') !== false) {
            return;
        }
        $full = WEB_DIR . '/' . $relPath;
        if (is_file($full)) {
            @unlink($full);
        }
    }
}
