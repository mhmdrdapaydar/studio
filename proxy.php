<?php
// Set a longer execution time limit if needed, default is often 30 or 60 seconds.
// ini_set('max_execution_time', 120); // 120 seconds

// Helper function to send JSON responses
function sendJsonResponse($data, $httpStatusCode = 200) {
    if (!headers_sent()) { // Ensure headers aren't already sent
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($httpStatusCode);
    }
    echo json_encode($data);
    exit;
}

// Check if cURL is available
if (!function_exists('curl_init')) {
    sendJsonResponse([
        'success' => false, 
        'error' => 'cURL extension is not installed or enabled on the server. This script requires cURL to function.',
        'finalUrl' => '',
        'statusCode' => 500 // Custom status code for this specific server configuration issue
    ], 500); // Internal Server Error
}

$url = isset($_GET['url']) ? trim($_GET['url']) : '';

if (empty($url)) {
    sendJsonResponse(['success' => false, 'error' => 'URL cannot be empty.', 'finalUrl' => ''], 400);
}

// Add https:// if no scheme is present
if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
    $url = "https://".$url;
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    sendJsonResponse(['success' => false, 'error' => 'Invalid URL format: ' . htmlspecialchars($url), 'finalUrl' => htmlspecialchars($url)], 400);
}

// --- Cookie Handling ---
$cookieFile = tempnam(sys_get_temp_dir(), 'unblockme_cookie_');
if ($cookieFile === false) {
    sendJsonResponse([
        'success' => false, 
        'error' => 'Failed to create temporary cookie file on the server.', 
        'finalUrl' => htmlspecialchars($url),
        'statusCode' => 500
    ], 500);
}
// Register a shutdown function to clean up the cookie file
register_shutdown_function(function() use ($cookieFile) {
    if ($cookieFile && file_exists($cookieFile)) {
        unlink($cookieFile);
    }
});
// --- End Cookie Handling ---

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 45); // Increased timeout slightly
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/98.0.4758.102 Safari/537.36'); // Updated User-Agent
curl_setopt($ch, CURLOPT_ENCODING, ""); // Handle gzip, deflate, etc. automatically

// --- Enhanced HTTP Headers ---
$requestHeaders = [
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
    'Accept-Language: en-US,en;q=0.9',
    // 'Accept-Encoding: gzip, deflate, br', // Covered by CURLOPT_ENCODING = ""
    'DNT: 1', // Do Not Track
    'Upgrade-Insecure-Requests: 1' // Ask for HTTPS version if available
];
$urlParts = parse_url($url);
if (isset($urlParts['scheme']) && isset($urlParts['host'])) {
    $requestHeaders[] = 'Referer: ' . $urlParts['scheme'] . '://' . $urlParts['host'] . '/';
} else {
    $requestHeaders[] = 'Referer: ' . $url; // Fallback referer
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
// --- End Enhanced HTTP Headers ---

// --- Cookie Jar Setup ---
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile); 
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
// --- End Cookie Jar Setup ---

// --- SSL Options (use with caution) ---
// If you encounter SSL certificate errors for legitimate sites, it might be due to an outdated CA bundle on your server.
// The options below bypass SSL verification, which is a security risk and should only be used if you understand the implications.
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // WARNING: Disables SSL certificate verification.
// curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // WARNING: Disables SSL host verification.
// --- End SSL Options ---

$content = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); 
$curlError = curl_error($ch); 
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($curlError) {
    sendJsonResponse([
        'success' => false, 
        'error' => 'cURL Error: ' . htmlspecialchars($curlError), 
        'statusCode' => $statusCode,
        'finalUrl' => htmlspecialchars($finalUrl ?: $url)
    ], 500);
}

if ($statusCode >= 200 && $statusCode < 400) {
    // Only process if content type is HTML, otherwise, it might be an image, CSS, etc.
    // For this proxy's purpose, we primarily expect HTML.
    if ($contentType && stripos($contentType, 'text/html') !== false) {
        // Inject <base> tag
        $baseHref = '<base href="'.htmlspecialchars($finalUrl).'" target="_blank">';
        if (stripos($content, '<head>') !== false) {
            if (preg_match('/<base\s[^>]*>/i', $content)) {
                $content = preg_replace('/<base\s[^>]*>/i', $baseHref, $content, 1);
            } else {
                $content = preg_replace('/(<head\b[^>]*>)/i', '$1'.$baseHref, $content, 1);
            }
        } else {
            $content = $baseHref . $content;
        }

        // Remove Content-Security-Policy meta tags which can block proxied content
        $content = preg_replace('/<meta http-equiv=["\']Content-Security-Policy["\'][^>]*>/i', '', $content);

        // Attempt to make src/href attributes absolute for root-relative paths (e.g., /path/image.jpg)
        $content = preg_replace_callback(
            '/(src|href)\s*=\s*([\'"])\/(?!\/)([^\'"]*)\2/i',
            function ($matches) use ($finalUrl) {
                $parsedFinalUrl = parse_url($finalUrl);
                if (!$parsedFinalUrl) {
                    return $matches[0];
                }
                $scheme = isset($parsedFinalUrl['scheme']) ? $parsedFinalUrl['scheme'] : 'http';
                $host = isset($parsedFinalUrl['host']) ? $parsedFinalUrl['host'] : '';
                $port = isset($parsedFinalUrl['port']) ? ':' . $parsedFinalUrl['port'] : '';
                return $matches[1] . '=' . $matches[2] . $scheme . '://' . $host . $port . '/' . ltrim($matches[3], '/') . $matches[2];
            },
            $content
        );

        // Attempt to rewrite root-relative URLs in inline style="background-image: url(/image.jpg)"
        $content = preg_replace_callback(
            '/(url\s*\(\s*([\'"]?))\/(?!\/)([^\'")]+)(\2\s*\))/i',
            function ($matches) use ($finalUrl) {
                $parsedFinalUrl = parse_url($finalUrl);
                if (!$parsedFinalUrl) return $matches[0];
                $scheme = isset($parsedFinalUrl['scheme']) ? $parsedFinalUrl['scheme'] : 'http';
                $host = isset($parsedFinalUrl['host']) ? $parsedFinalUrl['host'] : '';
                $port = isset($parsedFinalUrl['port']) ? ':' . $parsedFinalUrl['port'] : '';
                return $matches[1] . $scheme . '://' . $host . $port . '/' . ltrim($matches[3], '/') . $matches[4];
            },
            $content
        );
    }
    // For non-HTML content, we might decide to pass it through or handle differently in a more advanced proxy.
    // For now, we assume the main use is for HTML pages.

    sendJsonResponse([
        'success' => true, 
        'content' => $content, 
        'statusCode' => $statusCode, 
        'finalUrl' => htmlspecialchars($finalUrl),
        'contentType' => $contentType
    ]);

} else {
    $httpErrorCode = ($statusCode == 0 || $statusCode >= 500) ? 502 : $statusCode; 
    
    $errorMsg = "Failed to fetch content. The remote server responded with status: $statusCode.";
    if ($statusCode === 0 && empty($curlError)) {
        $errorMsg = "Failed to fetch content. Could not connect to the server, the URL may be invalid, or the target server is not responding.";
    } else if ($statusCode === 403) {
        $errorMsg .= " Access Forbidden. The target site may be blocking direct access or proxy attempts.";
    } else if ($statusCode === 404) {
        $errorMsg .= " Not Found. The requested resource was not found on the target server.";
    } else if ($statusCode >= 500) {
        $errorMsg .= " The target server encountered an internal error.";
    } else if ($statusCode >= 400 && $statusCode < 500) {
        $errorMsg .= " There was an issue with the request to the target server (e.g., bad request, unauthorized).";
    }

    sendJsonResponse([
        'success' => false, 
        'error' => $errorMsg, 
        'statusCode' => $statusCode,
        'finalUrl' => htmlspecialchars($finalUrl ?: $url),
        'content' => $content // Send back any content received, even on error, for debugging
    ], $httpErrorCode);
}
?>
