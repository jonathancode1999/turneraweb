<?php
// Secure image upload helpers (v1.2 hardening)
// Validates size, real image content, MIME, and optionally re-encodes to JPG.
//
// Usage:
//   $rel = upload_image_from_field('avatar_file', __DIR__.'/../public/uploads/barbers', 'barber_12_avatar', 4*1024*1024);

require_once __DIR__ . '/utils.php';

function upload_image_from_field(string $field, string $absDir, string $baseName, int $maxBytes = 4194304): ?string {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) return null;
    $f = $_FILES[$field];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($f['error'] ?? 0) !== UPLOAD_ERR_OK) throw new RuntimeException('Error subiendo imagen (código ' . (int)($f['error'] ?? 0) . ').');
    if (($f['size'] ?? 0) <= 0) throw new RuntimeException('Archivo vacío.');
    if (($f['size'] ?? 0) > $maxBytes) throw new RuntimeException('La imagen supera ' . (int)round($maxBytes/1024/1024) . 'MB.');

    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('Upload inválido.');

    // Real image check + mime
    $info = @getimagesize($tmp);
    if ($info === false || empty($info['mime'])) throw new RuntimeException('La imagen no es válida.');
    $mime = (string)$info['mime'];
    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
        throw new RuntimeException('Formato no permitido (usar JPG/PNG/WEBP).');
    }

    if (!is_dir($absDir)) { @mkdir($absDir, 0775, true); }
    @file_put_contents(rtrim($absDir,'/').'/index.php', "<?php http_response_code(404);");
    $outAbs = rtrim($absDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName . '.jpg';

    // Re-encode (preferred) to strip metadata and ensure consistent format.
    $saved = false;
    if (function_exists('imagecreatefromjpeg') && function_exists('imagejpeg')) {
        $im = null;
        if ($mime === 'image/jpeg') $im = @imagecreatefromjpeg($tmp);
        elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) $im = @imagecreatefrompng($tmp);
        elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $im = @imagecreatefromwebp($tmp);

        if ($im) {
            // Handle alpha (PNG/WEBP) by flattening on white background.
            $w = imagesx($im);
            $h = imagesy($im);
            $canvas = imagecreatetruecolor($w, $h);
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
            imagecopy($canvas, $im, 0, 0, 0, 0, $w, $h);

            $saved = @imagejpeg($canvas, $outAbs, 85);
            imagedestroy($canvas);
            imagedestroy($im);
        }
    }

    // Fallback: move original (still validated by getimagesize + mime)
    if (!$saved) {
        $ext = ($mime === 'image/png') ? 'png' : (($mime === 'image/webp') ? 'webp' : 'jpg');
        $outAbs = rtrim($absDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName . '.' . $ext;
        if (!move_uploaded_file($tmp, $outAbs)) throw new RuntimeException('No se pudo guardar la imagen.');
        $saved = true;
    }

    // Build public relative path (under /public)
    $publicDir = realpath(__DIR__ . '/../public');
    $realOut = realpath($outAbs);
    if ($publicDir && $realOut && strpos($realOut, $publicDir) === 0) {
        $rel = ltrim(str_replace('\\','/', substr($realOut, strlen($publicDir))), '/');
        return $rel; // e.g., uploads/barbers/x.jpg
    }
    // Best effort
    $rel = 'uploads/' . basename(dirname($outAbs)) . '/' . basename($outAbs);
    return $rel;
}
