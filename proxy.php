<?php
// Set a longer execution time limit if needed, default is often 30 or 60 seconds.
// ini_set('max_execution_time', 120); // 120 seconds

// Helper function to send JSON responses
function sendJsonResponse($data, $httpStatusCode = 200) {
    $jsonOutput = json_encode($data);

    if ($jsonOutput === false) {
        // json_encode failed. This can happen with non-UTF8 strings or recursion.
        $jsonLastErrorMsg = json_last_error_msg();
        // Attempt to log the error server-side if possible (requires appropriate permissions and setup)
        // error_log('json_encode error in proxy.php: ' . $jsonLastErrorMsg . ' | Data keys: ' . implode(', ', array_keys($data)));

        // Fallback to a generic JSON error response
        $errorData = [
            'success' => false,
            'error' => 'Server-side JSON encoding error. ' . ($jsonLastErrorMsg ?: 'Unknown encoding error.'),
            'statusCode' => 500, // Internal server error
            'finalUrl' => isset($data['rawFinalUrl']) ? htmlspecialchars($data['rawFinalUrl']) : (isset($data['finalUrl']) ? htmlspecialchars($data['finalUrl']) : ''),
            'rawFinalUrl' => isset($data['rawFinalUrl']) ? $data['rawFinalUrl'] : (isset($data['finalUrl']) ? $data['finalUrl'] : ''),
            'content' => null // Ensure content is null or empty
        ];
        $jsonOutput = json_encode($errorData);
        $httpStatusCode = 500; // Ensure status code reflects the new error
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($httpStatusCode);
    }
    echo $jsonOutput;
    exit;
}

// Helper function to make a URL absolute
function make_absolute($url, $base_url) {
    if (empty(trim($url))) return $base_url; // If URL is empty, return base
    
    // Check if URL is already absolute (has a scheme) or is a data/mailto/tel URI
    if (preg_match('~^(?:[a-z][a-z0-9+-.]*:|data:|mailto:|tel:)~i', $url)) {
        return $url;
    }

    $base = parse_url($base_url);
    if (empty($base['scheme']) || empty($base['host'])) {
        // Try to fix base_url if it's schemeless like "example.com/path"
        if (strpos($base_url, '//') === 0) {
             $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . ':' . $base_url;
             $base = parse_url($base_url);
        } elseif (strpos($base_url, '/') === false && strpos($base_url, '.') !== false) {
             $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $base_url;
             $base = parse_url($base_url);
        }
        if (empty($base['scheme']) || empty($base['host'])) {
           return $url; // Invalid base URL, cannot resolve reliably
        }
    }


    // If URL is scheme-relative (e.g., //example.com/path)
    if (substr($url, 0, 2) === '//') {
        return $base['scheme'] . ':' . $url;
    }

    $path_prefix = $base['scheme'] . '://' . $base['host'];
    if (isset($base['port'])) {
        $path_prefix .= ':' . $base['port'];
    }

    if ($url[0] === '#') { // Anchor link on the same page
        return rtrim($base_url, '#?') . $url; // Append fragment to base_url (potentially without its own fragment/query)
    }
    
    if ($url[0] === '?') { // Query string on the same page
        $base_url_no_query = strtok($base_url, '?'); // Remove existing query string from base_url
        return rtrim($base_url_no_query, '/') . $url; // Append new query string
    }

    $base_path_dir = '';
    if (isset($base['path'])) {
        if (substr($base['path'], -1) === '/' || $base['path'] === '/') {
            $base_path_dir = $base['path'];
        } else {
            $base_path_dir = dirname($base['path']);
        }
    }
    
    if ($base_path_dir === '.' || $base_path_dir === '') {
        $base_path_dir = '/';
    } else {
        if ($base_path_dir[0] !== '/') $base_path_dir = '/' . $base_path_dir;
        if (substr($base_path_dir, -1) !== '/') $base_path_dir .= '/';
    }
    

    $absolute_path = '';
    if ($url[0] === '/') { // URL is root-relative
        $absolute_path = $url;
    } else { // URL is relative to the base path directory
        $absolute_path = $base_path_dir . $url;
    }

    // Normalize the path (resolve ../ and ./)
    $parts = [];
    $path_to_explode = $absolute_path === '/' ? '' : ltrim($absolute_path, '/');

    foreach (explode('/', $path_to_explode) as $part) {
        if ($part === '.' || ($part === '' && !empty($parts))) { // Skip empty parts unless it's the first part of an absolute path that became empty
            continue;
        }
        if ($part === '..') {
            if (!empty($parts)) { // Only pop if there's something to pop
                 array_pop($parts);
            }
        } else {
            $parts[] = $part;
        }
    }
    $normalized_path = '/' . implode('/', $parts);
     // If original $absolute_path was just "/" and $parts became empty, ensure $normalized_path is "/"
    if ($absolute_path === '/' && empty($parts)) {
        $normalized_path = '/';
    }


    $final_absolute_url = $path_prefix . $normalized_path;
    
    $original_url_parts = parse_url($url);
    if (isset($original_url_parts['query'])) $final_absolute_url .= '?' . $original_url_parts['query'];
    if (isset($original_url_parts['fragment'])) $final_absolute_url .= '#' . $original_url_parts['fragment'];
    
    return $final_absolute_url;
}


// Check if cURL is available
if (!function_exists('curl_init')) {
    sendJsonResponse([
        'success' => false, 
        'error' => 'cURL extension is not installed or enabled on the server. This script requires cURL to function.',
        'finalUrl' => '', // URL not known yet
        'rawFinalUrl' => '', // URL not known yet
        'statusCode' => 500, // Custom status code for this specific server configuration issue
        'content' => null
    ], 500); // Internal Server Error
}

$url = isset($_GET['url']) ? trim($_GET['url']) : '';

if (empty($url)) {
    sendJsonResponse(['success' => false, 'error' => 'URL cannot be empty.', 'finalUrl' => '', 'rawFinalUrl' => '', 'content' => null, 'statusCode' => 400], 400);
}

// Add https:// if no scheme is present
if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
    $url = "https://".$url;
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    sendJsonResponse(['success' => false, 'error' => 'Invalid URL format: ' . htmlspecialchars($url), 'finalUrl' => htmlspecialchars($url), 'rawFinalUrl' => $url, 'content' => null, 'statusCode' => 400], 400);
}

// --- Cookie Handling ---
$cookieFile = null; // Initialize to null
if (is_writable(sys_get_temp_dir())) {
    $cookieFile = tempnam(sys_get_temp_dir(), 'unblockme_cookie_');
}

if ($cookieFile === false || $cookieFile === null) { // tempnam returns false on error
    // Don't send error, proceed without cookie jar if tempnam fails, but log it server-side if possible
    // error_log("Warning: Failed to create temporary cookie file in proxy.php. Proceeding without cookie persistence.");
    $cookieFile = null; // Ensure it's null if failed
} else {
    // Register a shutdown function to clean up the cookie file ONLY if it was created
    register_shutdown_function(function() use ($cookieFile) {
        if ($cookieFile && file_exists($cookieFile)) {
            unlink($cookieFile);
        }
    });
}
// --- End Cookie Handling ---

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 45); 
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/98.0.4758.102 Safari/537.36 UnblockMeProxy/1.0'); 
curl_setopt($ch, CURLOPT_ENCODING, ""); 

// --- Enhanced HTTP Headers ---
$requestHeaders = [
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
    'Accept-Language: en-US,en;q=0.9',
    'DNT: 1', 
    'Upgrade-Insecure-Requests: 1' 
];
$urlParts = parse_url($url);
if (isset($urlParts['scheme']) && isset($urlParts['host'])) {
    $referer = $urlParts['scheme'] . '://' . $urlParts['host'] . '/';
    // Add Origin header, often needed
    $requestHeaders[] = 'Origin: ' . $urlParts['scheme'] . '://' . $urlParts['host'];
} else {
    $referer = $url; // Fallback referer
}
$requestHeaders[] = 'Referer: ' . $referer;

curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
// --- End Enhanced HTTP Headers ---

// --- Cookie Jar Setup ---
if ($cookieFile) { // Only set cookie jar if file was successfully created
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile); 
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
}
// --- End Cookie Jar Setup ---

curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); 
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); 
// To use a specific CA bundle:
// curl_setopt($ch, CURLOPT_CAINFO, '/path/to/your/cacert.pem');


$content = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url; 
$curlError = curl_error($ch); 
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($curlError) {
    sendJsonResponse([
        'success' => false, 
        'error' => 'cURL Error: ' . htmlspecialchars($curlError), 
        'statusCode' => $statusCode ?: 503, // Use 503 if status code is 0 (network error)
        'finalUrl' => htmlspecialchars($finalUrl),
        'rawFinalUrl' => $finalUrl,
        'content' => null
    ], $statusCode ?: 503); // Use a server error code if status is 0
}

if ($statusCode >= 200 && $statusCode < 400) {
    if ($contentType && stripos($contentType, 'text/html') !== false) {
        
        $baseHref = '<base href="'.htmlspecialchars($finalUrl, ENT_QUOTES, 'UTF-8').'" target="_self" />';
        if (stripos($content, '<head>') !== false) {
            if (preg_match('/<base\s[^>]*>/i', $content)) {
                $content = preg_replace('/<base\s[^>]*>/i', $baseHref, $content, 1);
            } else {
                $content = preg_replace('/(<head\b[^>]*>)/i', '$1'.$baseHref, $content, 1);
            }
        } else {
            $content = $baseHref . $content;
        }

        $content = preg_replace('/<meta http-equiv=["\']Content-Security-Policy["\'][^>]*>/i', '', $content);
        $content = preg_replace('/\s+integrity\s*=\s*([\'"])[^\'"]*\1/i', '', $content);
        // This regex for link integrity was problematic and could remove whole link tags. Improved:
        $content = preg_replace_callback('/<link([^>]*)integrity=([\'"])[^\'"]*\2([^>]*)>/is', function($matches) {
            return '<link' . $matches[1] . $matches[3] . '>';
        }, $content);
        // Remove 'nonce' attributes from script tags
        $content = preg_replace('/\s+nonce\s*=\s*([\'"])[^\'"]*\1/i', '', $content);
        // Attempt to disable service workers by removing their registration script patterns
        $content = preg_replace('/navigator\.serviceWorker\s*\.\s*register\s*\(([^)]+)\)/i', 'console.warn("ServiceWorker registration blocked by proxy: $1")', $content);


        $attributesToRewrite = ['src', 'href', 'action', 'data-src', 'poster', 'background', 'data-url', 'data-href']; 

        foreach ($attributesToRewrite as $attr) {
            $pattern = '/(<[^>]+' . preg_quote($attr, '/') . '\s*=\s*)([\'"]?)([^"\'\s#<>][^"\'\s<>]*|[^"\'\s<>]*\?[^"\'\s<>]*|[^"\'\s<>]*#[^"\'\s<>]*)([\'"]?)/i';

            $content = preg_replace_callback(
                $pattern,
                function ($matches) use ($finalUrl) {
                    $originalUrl = html_entity_decode($matches[3]); // Decode entities before resolving
                    if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/|#)/i', $originalUrl) || empty(trim($originalUrl))) {
                        return $matches[0]; 
                    }
                    $absoluteUrl = make_absolute($originalUrl, $finalUrl);
                    return $matches[1] . $matches[2] . htmlspecialchars($absoluteUrl, ENT_QUOTES, 'UTF-8') . $matches[4];
                },
                $content
            );
        }
        
        // Rewrite URLs in inline style="... url(...)"
        $content = preg_replace_callback(
            '/(url\s*\(\s*)([\'"]?)([^"\'\)\s#<>][^"\'\)\s<>]*|[^"\'\)\s<>]*\?[^"\'\)\s<>]*|[^"\'\)\s<>]*#[^"\'\)\s<>]*)([\'"]?)(\s*\))/i',
            function ($matches) use ($finalUrl) {
                $originalUrl = html_entity_decode($matches[3]); // Decode entities
                if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/|#)/i', $originalUrl) || empty(trim($originalUrl))) {
                    return $matches[0]; 
                }
                $absoluteUrl = make_absolute($originalUrl, $finalUrl);
                return $matches[1] . $matches[2] . htmlspecialchars($absoluteUrl, ENT_QUOTES, 'UTF-8') . $matches[4] . $matches[5];
            },
            $content
        );

        // Rewrite URLs in <script> tags (simple string replacement, might be fragile)
        // This is highly experimental and can break scripts. Use with caution.
        // Example: proxy.php?url=...
        $proxyUrlPrefix = htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]?url=", ENT_QUOTES, 'UTF-8');
        
        // Looking for fetch('relative/path') or new XMLHttpRequest().open('GET', 'relative/path')
        // This part is complex and risky. A simple example for fetch:
        $content = preg_replace_callback(
            '/(fetch\s*\(\s*)([\'"])([^"\'#:\s][^\'"]*)([\'"]\s*)/i', // Matches fetch('path') or fetch("path") where path is relative
            function ($matches) use ($finalUrl, $proxyUrlPrefix) {
                $originalUrl = html_entity_decode($matches[3]);
                 if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/|#)/i', $originalUrl)) { // if already absolute, data URI or scheme relative
                    return $matches[0]; 
                }
                $absoluteUrl = make_absolute($originalUrl, $finalUrl);
                // Instead of proxying AJAX calls through this PHP script again (which can cause loops or complexity),
                // let's try to make them absolute if they were relative.
                // return $matches[1] . $matches[2] . htmlspecialchars($proxyUrlPrefix . urlencode($absoluteUrl), ENT_QUOTES, 'UTF-8') . $matches[4];
                return $matches[1] . $matches[2] . htmlspecialchars($absoluteUrl, ENT_QUOTES, 'UTF-8') . $matches[4];
            },
            $content
        );


    }
    // For non-HTML content, pass it through as is. Client-side script might not render it.

    sendJsonResponse([
        'success' => true, 
        'content' => $content, 
        'statusCode' => $statusCode, 
        'finalUrl' => htmlspecialchars($finalUrl, ENT_QUOTES, 'UTF-8'), // For display
        'rawFinalUrl' => $finalUrl, // For JS logic
        'contentType' => $contentType
    ]);

} else {
    $httpErrorCode = ($statusCode == 0 || $statusCode >= 500) ? 502 : $statusCode; // 502 for network/upstream errors
    
    $errorMsg = "Failed to fetch content. The remote server responded with status: $statusCode.";
    if ($statusCode === 0 && empty($curlError)) { // $curlError check is important
        $errorMsg = "Failed to fetch content. Could not connect to the server, the URL may be invalid, or the target server is not responding.";
    } else if (!empty($curlError)) { // If curlError is set, it's more specific
        $errorMsg = "cURL Error: " . htmlspecialchars($curlError);
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
        'finalUrl' => htmlspecialchars($finalUrl, ENT_QUOTES, 'UTF-8'),
        'rawFinalUrl' => $finalUrl,
        'content' => $content // Send back any content received, even on error, for debugging (might be null or HTML error page from target)
    ], $httpErrorCode);
}
?>