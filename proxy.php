<?php
header('Content-Type: text/html; charset=utf-8');

$url = isset($_GET['url']) ? trim($_GET['url']) : '';

if (empty($url)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'URL cannot be empty.', 'finalUrl' => '']);
    exit;
}

// Add http:// if no scheme is present
if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
    $url = "https://".$url;
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid URL format: ' . htmlspecialchars($url), 'finalUrl' => htmlspecialchars($url)]);
    exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 seconds timeout
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
    'Accept-Language: en-US,en;q=0.9',
]);
// For HTTPS, you might need to uncomment these if you face SSL certificate issues
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
// curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$content = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); // Get the final URL after redirects
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'cURL Error: ' . htmlspecialchars($error), 'statusCode' => $statusCode, 'finalUrl' => htmlspecialchars($finalUrl ?: $url)]);
    exit;
}

if ($statusCode >= 200 && $statusCode < 400) {
    // Basic HTML modification
    $baseHref = '<base href="'.htmlspecialchars($finalUrl).'" target="_blank">';
    
    // Inject base tag
    if (strpos($content, '<head>') !== false) {
        if (preg_match('/<base\s[^>]*>/i', $content)) {
            $content = preg_replace('/<base\s[^>]*>/i', $baseHref, $content, 1);
        } else {
            $content = str_replace('<head>', '<head>'.$baseHref, $content);
        }
    } else {
        $content = $baseHref . $content;
    }

    // Attempt to make src/href attributes absolute for root-relative paths
    // This is a simplified regex and might not cover all cases.
    $content = preg_replace_callback(
        '/(src|href)\s*=\s*([\'"])\/(?!\/)([^\'"]*)\2/i',
        function ($matches) use ($finalUrl) {
            $parsedFinalUrl = parse_url($finalUrl);
            $scheme = isset($parsedFinalUrl['scheme']) ? $parsedFinalUrl['scheme'] : 'http';
            $host = isset($parsedFinalUrl['host']) ? $parsedFinalUrl['host'] : '';
            $port = isset($parsedFinalUrl['port']) ? ':' . $parsedFinalUrl['port'] : '';
            return $matches[1] . '=' . $matches[2] . $scheme . '://' . $host . $port . '/' . $matches[3] . $matches[2];
        },
        $content
    );
    
    echo json_encode(['success' => true, 'content' => $content, 'statusCode' => $statusCode, 'finalUrl' => htmlspecialchars($finalUrl)]);
} else {
    http_response_code($statusCode == 0 ? 500 : $statusCode); // If status code is 0, it's likely a curl error not caught above
    echo json_encode([
        'success' => false, 
        'error' => "Failed to fetch content. Server responded with status: $statusCode. This could be due to the site blocking proxy attempts or an invalid URL.", 
        'statusCode' => $statusCode,
        'finalUrl' => htmlspecialchars($finalUrl ?: $url)
    ]);
}
?>
