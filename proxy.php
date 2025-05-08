<?php
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

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 30); 
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
    'Accept-Language: en-US,en;q=0.9',
]);
// For HTTPS, you might need to uncomment these if you face SSL certificate issues and understand the risks.
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
// curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$content = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); 
$curlError = curl_error($ch); 
curl_close($ch);

if ($curlError) {
    sendJsonResponse([
        'success' => false, 
        'error' => 'cURL Error: ' . htmlspecialchars($curlError), 
        'statusCode' => $statusCode, // $statusCode might be 0 if cURL failed before HTTP transaction
        'finalUrl' => htmlspecialchars($finalUrl ?: $url)
    ], 500); // Using 500 for cURL library errors
}

if ($statusCode >= 200 && $statusCode < 400) {
    $baseHref = '<base href="'.htmlspecialchars($finalUrl).'" target="_blank">';
    
    if (stripos($content, '<head>') !== false) { // Use stripos for case-insensitive search
        if (preg_match('/<base\s[^>]*>/i', $content)) {
            $content = preg_replace('/<base\s[^>]*>/i', $baseHref, $content, 1);
        } else {
            // Inject base tag right after the opening <head> tag
            $content = preg_replace('/(<head\b[^>]*>)/i', '$1'.$baseHref, $content, 1);
        }
    } else {
        // Prepend if no <head> tag found (less ideal but a fallback)
        $content = $baseHref . $content;
    }

    // Attempt to make src/href attributes absolute for root-relative paths
    $content = preg_replace_callback(
        '/(src|href)\s*=\s*([\'"])\/(?!\/)([^\'"]*)\2/i',
        function ($matches) use ($finalUrl) {
            $parsedFinalUrl = parse_url($finalUrl);
            if (!$parsedFinalUrl) { // Safety check if finalUrl is malformed
                return $matches[0]; // Return original if parsing fails
            }
            $scheme = isset($parsedFinalUrl['scheme']) ? $parsedFinalUrl['scheme'] : 'http';
            $host = isset($parsedFinalUrl['host']) ? $parsedFinalUrl['host'] : '';
            $port = isset($parsedFinalUrl['port']) ? ':' . $parsedFinalUrl['port'] : '';
            return $matches[1] . '=' . $matches[2] . $scheme . '://' . $host . $port . '/' . ltrim($matches[3], '/') . $matches[2];
        },
        $content
    );
    
    sendJsonResponse([
        'success' => true, 
        'content' => $content, 
        'statusCode' => $statusCode, 
        'finalUrl' => htmlspecialchars($finalUrl)
    ]);
} else {
    // Determine appropriate HTTP status code for the proxy's response
    // If cURL returned 0 (e.g., connection refused) or >= 500, proxy should probably return 502 Bad Gateway.
    // Otherwise, mirror the status code from the remote server if it's a client error (4xx).
    $httpErrorCode = ($statusCode == 0 || $statusCode >= 500) ? 502 : $statusCode; 
    
    $errorMsg = "Failed to fetch content. The remote server responded with status: $statusCode.";
    if ($statusCode === 0 && empty($curlError)) { // curl_error() might be empty if it's a DNS or connect timeout
        $errorMsg = "Failed to fetch content. Could not connect to the server, the URL may be invalid, or the target server is not responding.";
    } else if ($statusCode === 403) {
        $errorMsg .= " Access Forbidden. The target site may be blocking direct access or proxy attempts.";
    } else if ($statusCode === 404) {
        $errorMsg .= " Not Found. The requested resource was not found on the target server.";
    } else if ($statusCode >= 500) {
        $errorMsg .= " The target server encountered an internal error.";
    } else if ($statusCode >= 400 && $statusCode < 500) { // Other 4xx errors
        $errorMsg .= " There was an issue with the request to the target server (e.g., bad request, unauthorized).";
    }

    sendJsonResponse([
        'success' => false, 
        'error' => $errorMsg, 
        'statusCode' => $statusCode,
        'finalUrl' => htmlspecialchars($finalUrl ?: $url)
    ], $httpErrorCode);
}
?>