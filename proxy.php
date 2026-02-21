<?php
header('Content-Type: text/html; charset=UTF-8');

$target_url = 'https://www.icj-cij.org';

// Initialize cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $target_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

// Execute request
$content = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
<?php
// IMPORTANT: Only use this proxy for content you have permission to proxy.
// This script forwards request headers and bodies (when appropriate), and
// preserves/forwards selected response headers (Content-Type, caching, Set-Cookie).

$target_base = 'https://www.icj-cij.org';

// Accept a path to proxy (e.g. proxy.php?path=some/page)
$path = isset($_GET['path']) ? $_GET['path'] : '';
$target_url = rtrim($target_base, '/') . ($path ? '/' . ltrim($path, '/') : '/');

// Build cURL request
$method = $_SERVER['REQUEST_METHOD'];
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $target_url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

// Forward select request headers from the client to the target
$forward = [];
$map = [
    'HTTP_ACCEPT' => 'Accept',
    'HTTP_ACCEPT_LANGUAGE' => 'Accept-Language',
    'HTTP_USER_AGENT' => 'User-Agent',
    'HTTP_REFERER' => 'Referer',
    'HTTP_COOKIE' => 'Cookie',
];
foreach ($map as $serverKey => $hdrName) {
    if (!empty($_SERVER[$serverKey])) {
        $forward[] = $hdrName . ': ' . $_SERVER[$serverKey];
    }
}
if (!empty($forward)) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, $forward);
}

// Forward request body for POST/PUT/PATCH
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $body = file_get_contents('php://input');
    if ($body !== false && $body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
}

// Capture response headers
$response_headers = [];
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$response_headers) {
    $len = strlen($header);
    $header = trim($header);
    if ($header === '') return $len;

    if (strpos($header, ':') === false) {
        // status line
        $response_headers[] = $header;
        return $len;
    }
    list($name, $value) = explode(':', $header, 2);
    $name = trim($name);
    $value = trim($value);
    if (!isset($response_headers[$name])) {
        $response_headers[$name] = [$value];
    } else {
        $response_headers[$name][] = $value;
    }
    return $len;
});

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Forward selected response headers to the client
$allowed = ['Content-Type', 'Cache-Control', 'Expires', 'Last-Modified', 'Vary', 'Content-Language'];
foreach ($allowed as $h) {
    if (isset($response_headers[$h])) {
        header($h . ': ' . implode(', ', $response_headers[$h]));
    }
}

// Forward Set-Cookie headers individually
if (isset($response_headers['Set-Cookie'])) {
    foreach ($response_headers['Set-Cookie'] as $cookieVal) {
        header('Set-Cookie: ' . $cookieVal, false);
    }
}

// If it's not HTML (images, CSS, JS, etc.) just echo raw response
$contentType = isset($response_headers['Content-Type'][0]) ? $response_headers['Content-Type'][0] : '';
if (stripos($contentType, 'text/html') === false && stripos($contentType, 'application/xhtml+xml') === false) {
    // If no content type was provided, default to binary passthrough
    if ($contentType === '') {
        header('Content-Type: application/octet-stream');
    }
    http_response_code($http_code ? $http_code : 200);
    echo $response;
    exit;
}

// For HTML, perform DOM rewriting for internal links/resources
libxml_use_internal_errors(true);
$doc = new DOMDocument();
$html = mb_convert_encoding($response, 'HTML-ENTITIES', 'UTF-8');
@$doc->loadHTML($html);
$xpath = new DOMXPath($doc);

$rewriteAttrs = ['href', 'src', 'action'];
$targetHost = parse_url($target_base, PHP_URL_HOST);

foreach ($rewriteAttrs as $attr) {
    $nodes = $xpath->query('//@' . $attr);
    foreach ($nodes as $node) {
        $val = $node->nodeValue;
        if (!$val) continue;

        // protocol-relative (//example.com/path)
        if (strpos($val, '//') === 0) {
            $node->nodeValue = 'https:' . $val;
            continue;
        }

        // absolute URL
        if (preg_match('#^https?://#i', $val)) {
            $u = parse_url($val);
            if (isset($u['host']) && stripos($u['host'], $targetHost) !== false) {
                $newPath = isset($u['path']) ? $u['path'] : '/';
                $newQuery = isset($u['query']) ? '?' . $u['query'] : '';
                $node->nodeValue = $_SERVER['PHP_SELF'] . '?path=' . ltrim($newPath, '/') . $newQuery;
            }
            // external hosts are left alone
            continue;
        }

        // root-relative (/path/file)
        if (strpos($val, '/') === 0) {
            $node->nodeValue = $_SERVER['PHP_SELF'] . '?path=' . ltrim($val, '/');
            continue;
        }

        // relative paths are left as-is (browser will resolve)
    }
}

// Banner visibility: show by default but allow hiding via ?transparent=1
$show_banner = true;
if (isset($_GET['transparent']) && ($_GET['transparent'] === '1' || strtolower($_GET['transparent']) === 'true')) {
    $show_banner = false;
}

$out = $doc->saveHTML();
if ($show_banner) {
    // Insert a visible banner under the opening <body> so users know this is proxied
    $banner = '<div style="position:fixed;top:0;left:0;right:0;background:#fff8dc;color:#333;padding:6px 10px;border-bottom:1px solid #e0d5a0;z-index:99999;text-align:center;font-size:13px;">Proxied content from <a href="' . htmlspecialchars($target_base, ENT_QUOTES, 'UTF-8') . '" target="_blank">' . htmlspecialchars($target_base, ENT_QUOTES, 'UTF-8') . '</a></div><div style="height:40px"></div>';
    $out = preg_replace('/(<body[^>]*>)/i', '$1' . $banner, $out, 1);
}

http_response_code($http_code ? $http_code : 200);
echo $out;
?>