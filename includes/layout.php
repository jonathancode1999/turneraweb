<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

function page_head(string $title, string $bodyClass = '', ?string $headerHtml = null): void {
    $cfg = app_config();
    // Load theme variables (editable in Admin → Configuración).
    $themePrimary = '#2D7BD1';
    $themeAccent = '#0EA5E9';
    try {
        $pdo = db();
        $bid = (int)($cfg['business_id'] ?? 1);
        $biz = $pdo->query('SELECT theme_primary, theme_accent FROM businesses WHERE id=' . $bid)->fetch();
        if ($biz) {
            $tp = trim((string)($biz['theme_primary'] ?? ''));
            $ta = trim((string)($biz['theme_accent'] ?? ''));
            if ($tp !== '') $themePrimary = $tp;
            if ($ta !== '') $themeAccent = $ta;
        }
    } catch (Throwable $e) {
        // ignore theme errors
    }
    echo "<!doctype html><html lang=\"es\"><head>\n";
    echo "<meta charset=\"utf-8\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    echo "<title>" . h($title) . " | " . h($cfg['app_name']) . "</title>\n";
    // Cache-bust CSS so mobile/layout fixes apply immediately on localhost/hostings.
    $cssPathFs = __DIR__ . '/../assets/app.css';
    $cssVer = is_file($cssPathFs) ? (string)filemtime($cssPathFs) : (string)time();
    echo "<link rel=\"stylesheet\" href=\"../assets/app.css?v=" . h($cssVer) . "\">\n";
    echo "<style>:root{--primary:" . h($themePrimary) . ";--accent:" . h($themeAccent) . ";}</style>\n";
    $cls = $bodyClass ? ' class="' . h($bodyClass) . '"' : '';
    echo "</head><body$cls><div class=\"container\">
";
    if ($headerHtml !== null) {
        echo "<header class=\"header\">" . $headerHtml . "</header>
";
    } else {
        echo "<header class=\"header\"><div class=\"brand\">" . h($cfg['app_name']) . "</div></header>
";
    }
    // flash message
    flash_render();
}

function page_foot(): void {
    $year = (new DateTimeImmutable())->format('Y');
    echo "<footer class=\"footer\">&copy; $year Turnera</footer>";
    echo "</div></body></html>";
}
