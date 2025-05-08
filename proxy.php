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

// Helper function to make a URL absolute
function make_absolute($url, $base_url) {
    if (empty(trim($url))) return $base_url; // If URL is empty, return base
    
    // Check if URL is already absolute (has a scheme) or is a data/mailto/tel URI
    if (preg_match('~^(?:[a-z][a-z0-9+-.]*:|data:|mailto:|tel:)~i', $url)) {
        return $url;
    }

    $base = parse_url($base_url);
    if (empty($base['scheme']) || empty($base['host'])) {
        return $url; // Invalid base URL, cannot resolve reliably
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
        return $base_url . $url;
    }
    
    if ($url[0] === '?') { // Query string on the same page
         // Remove existing query string from base_url if any
        $base_url_no_query = strtok($base_url, '?');
        return $base_url_no_query . $url;
    }

    $base_path_dir = '';
    if (isset($base['path'])) {
        // If base['path'] is a directory (ends with /) or is just /, use it as is
        if (substr($base['path'], -1) === '/' || $base['path'] === '/') {
            $base_path_dir = $base['path'];
        } else {
            // If base['path'] is a file, get its directory
            $base_path_dir = dirname($base['path']);
        }
    }
    // Ensure base_path_dir starts and ends with a slash if not empty, or is just "/"
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
    // Ensure there's no leading slash for explode if $absolute_path is like "/foo/bar"
    // but keep it if it's just "/"
    $path_to_explode = $absolute_path === '/' ? '' : ltrim($absolute_path, '/');

    foreach (explode('/', $path_to_explode) as $part) {
        if ($part === '.' || $part === '') {
            if (empty($parts) && $part === '') { // Handle case like /../ leading to empty $parts
                 // If absolute_path was '/', then $path_to_explode is empty, loop doesn't run.
                 // If absolute_path was '/foo', $path_to_explode is 'foo'.
                 // This seems fine for now.
            }
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
        } else {
            $parts[] = $part;
        }
    }
    $normalized_path = '/' . implode('/', $parts);

    $final_absolute_url = $path_prefix . $normalized_path;

    // Preserve query string and fragment from original relative URL if any
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
        'finalUrl' => '',
        'rawFinalUrl' => '',
        'statusCode' => 500 // Custom status code for this specific server configuration issue
    ], 500); // Internal Server Error
}

$url = isset($_GET['url']) ? trim($_GET['url']) : '';

if (empty($url)) {
    sendJsonResponse(['success' => false, 'error' => 'URL cannot be empty.', 'finalUrl' => '', 'rawFinalUrl' => ''], 400);
}

// Add https:// if no scheme is present
if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
    $url = "https://".$url;
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    sendJsonResponse(['success' => false, 'error' => 'Invalid URL format: ' . htmlspecialchars($url), 'finalUrl' => htmlspecialchars($url), 'rawFinalUrl' => $url], 400);
}

// --- Cookie Handling ---
$cookieFile = tempnam(sys_get_temp_dir(), 'unblockme_cookie_');
if ($cookieFile === false) {
    sendJsonResponse([
        'success' => false, 
        'error' => 'Failed to create temporary cookie file on the server.', 
        'finalUrl' => htmlspecialchars($url),
        'rawFinalUrl' => $url,
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

curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); 
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); 
// Consider setting CURLOPT_CAINFO to a specific CA bundle path if SSL issues persist on some servers.
// Example: curl_setopt($ch, CURLOPT_CAINFO, '/path/to/cacert.pem');


$content = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url; // Ensure finalUrl is always set
$curlError = curl_error($ch); 
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($curlError) {
    sendJsonResponse([
        'success' => false, 
        'error' => 'cURL Error: ' . htmlspecialchars($curlError), 
        'statusCode' => $statusCode,
        'finalUrl' => htmlspecialchars($finalUrl),
        'rawFinalUrl' => $finalUrl
    ], 500);
}

if ($statusCode >= 200 && $statusCode < 400) {
    if ($contentType && stripos($contentType, 'text/html') !== false) {
        // Inject <base> tag. target="_self" ensures links open in the same frame/window by default.
        $baseHref = '<base href="'.htmlspecialchars($finalUrl, ENT_QUOTES, 'UTF-8').'" target="_self" />';
        if (stripos($content, '<head>') !== false) {
            if (preg_match('/<base\s[^>]*>/i', $content)) {
                $content = preg_replace('/<base\s[^>]*>/i', $baseHref, $content, 1);
            } else {
                $content = preg_replace('/(<head\b[^>]*>)/i', '$1'.$baseHref, $content, 1);
            }
        } else {
            // If no <head>, prepend base tag. This might not be ideal for structure but helps resolving URLs.
            $content = $baseHref . $content;
        }

        // Remove Content-Security-Policy meta tags which can block proxied content
        $content = preg_replace('/<meta http-equiv=["\']Content-Security-Policy["\'][^>]*>/i', '', $content);
        // Remove integrity attributes from scripts and links as proxied content will differ
        $content = preg_replace('/\s+integrity\s*=\s*([\'"])[^\'"]*\1/i', '', $content);
        // Remove SRI from link tags as well
        $content = preg_replace('/<link[^>]*\s+integrity\s*=\s*([\'"]).*?\1[^>]*>/is', '', $content);


        // URL Rewriting for src, href, action, data-src, etc.
        // Note: srcset is more complex and not handled here to keep it simpler.
        $attributesToRewrite = ['src', 'href', 'action', 'data-src', 'poster', 'background']; 

        foreach ($attributesToRewrite as $attr) {
            // Regex to find attributes: (<tag_part attribute_name=["'])(value)(["'])
            $pattern = '/(<[^>]+' . preg_quote($attr, '/') . '\s*=\s*)([\'"]?)([^"\'\s#?][^"\'\s>]*|[^"\'\s>]*\?[^"\'\s>]*|[^"\'\s>]*#[^"\'\s>]*)([\'"]?)/i';

            $content = preg_replace_callback(
                $pattern,
                function ($matches) use ($finalUrl) {
                    $originalUrl = $matches[3];
                    // Don't rewrite if it looks like a data URI or an already absolute URL (simplified check)
                    if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/)/i', $originalUrl)) {
                        return $matches[0]; // Return original match
                    }
                    $absoluteUrl = make_absolute($originalUrl, $finalUrl);
                    return $matches[1] . $matches[2] . htmlspecialchars($absoluteUrl, ENT_QUOTES, 'UTF-8') . $matches[4];
                },
                $content
            );
        }
        
        // Attempt to rewrite URLs in inline style="... url(...)"
        $content = preg_replace_callback(
            '/(url\s*\(\s*)([\'"]?)([^"\'\)\s#?][^"\'\)\s]*|[^"\'\)\s]*\?[^"\'\)\s]*|[^"\'\)\s]*#[^"\'\)\s]*)([\'"]?)(\s*\))/i',
            function ($matches) use ($finalUrl) {
                $originalUrl = $matches[3];
                if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/)/i', $originalUrl)) {
                    return $matches[0]; // Don't rewrite data URIs or absolute URLs
                }
                $absoluteUrl = make_absolute($originalUrl, $finalUrl);
                return $matches[1] . $matches[2] . htmlspecialchars($absoluteUrl, ENT_QUOTES, 'UTF-8') . $matches[4] . $matches[5];
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
        'finalUrl' => htmlspecialchars($finalUrl, ENT_QUOTES, 'UTF-8'),
        'rawFinalUrl' => $finalUrl,
        'content' => $content // Send back any content received, even on error, for debugging
    ], $httpErrorCode);
}
?>
