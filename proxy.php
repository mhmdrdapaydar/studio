<?php
// Set a longer execution time limit if needed, default is often 30 or 60 seconds.
// ini_set('max_execution_time', 120); // 120 seconds

// Helper function to send JSON responses
function sendJsonResponse($data, $httpStatusCode = 200) {
    $jsonOutput = json_encode($data);

    if ($jsonOutput === false) {
        $jsonLastErrorMsg = json_last_error_msg();
        // error_log('Primary json_encode error in proxy.php: ' . $jsonLastErrorMsg . ' | Data keys: ' . (is_array($data) ? implode(', ', array_keys($data)) : 'Non-array data'));
        
        // Prepare error data, ensuring problematic fields from original $data are handled carefully
        $finalUrlString = '';
        if (isset($data['rawFinalUrl']) && is_string($data['rawFinalUrl'])) {
            $finalUrlString = $data['rawFinalUrl'];
        } elseif (isset($data['finalUrl']) && is_string($data['finalUrl'])) {
            $finalUrlString = $data['finalUrl'];
        }
        // Basic sanitization for the URL in the error message, though htmlspecialchars is usually for HTML context
        $safeFinalUrl = htmlspecialchars($finalUrlString, ENT_QUOTES, 'UTF-8');

        $errorData = [
            'success' => false,
            'error' => 'Server-side JSON encoding error. ' . ($jsonLastErrorMsg ?: 'Unknown encoding error.'),
            'statusCode' => 500, // Internal Server Error
            'finalUrl' => $safeFinalUrl, // Use sanitized version
            'rawFinalUrl' => $finalUrlString, // Keep raw for potential debugging if needed, but be wary
            'content' => null // Avoid re-encoding potentially problematic content
        ];
        
        $jsonOutput = json_encode($errorData);
        $httpStatusCode = 500;

        if ($jsonOutput === false) {
            // Fallback json_encode also failed. This is critical. Send a hardcoded JSON error.
            // error_log('Fallback json_encode also failed in proxy.php. Last error: ' . json_last_error_msg());
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
            }
            // Manually craft a JSON string that is guaranteed to be valid
            // Ensure any dynamic parts inserted here are themselves 100% safe for JSON.
            $hardcodedError = '{"success":false,"error":"Fatal server-side JSON encoding error. Unable to format error details.","statusCode":500,"finalUrl":';
            // Simple JSON encoding for the URL part of the hardcoded error
            $jsonEncodedFinalUrl = json_encode($finalUrlString); 
            if ($jsonEncodedFinalUrl === false) $jsonEncodedFinalUrl = '""'; // If even this fails, use empty string
            $hardcodedError .= $jsonEncodedFinalUrl;
            $hardcodedError .= ',"rawFinalUrl":'.$jsonEncodedFinalUrl.',"content":null}';
            echo $hardcodedError;
            exit;
        }
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
    if (empty(trim($url))) return $base_url; // Return base if URL is empty
    
    // If URL is already absolute (has a scheme), data URI, mailto, tel, or a fragment-only URL for the base page
    if (preg_match('~^(?:[a-z][a-z0-9+-.]*:|data:|mailto:|tel:)~i', $url)) {
        return $url;
    }

    // Parse the base URL
    $base = parse_url($base_url);

    // If base URL is invalid or doesn't have scheme/host, try to reconstruct or return original URL
    if (empty($base['scheme']) || empty($base['host'])) {
        $current_script_scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        if (strpos($base_url, '//') === 0) { // Protocol-relative URL for base
             $base_url = $current_script_scheme . ':' . $base_url;
        } elseif (strpos($base_url, '/') === false && strpos($base_url, '.') !== false) { // Domain only for base
             $base_url = $current_script_scheme . '://' . $base_url;
        } elseif ($base_url[0] === '/') { // Absolute path for base (relative to current host)
            $current_script_host = $_SERVER['HTTP_HOST'];
            $base_url = $current_script_scheme . '://' . $current_script_host . $base_url;
        } else { // Unclear base, fallback
            // error_log("make_absolute: Could not establish a valid base scheme/host from base_url: " . $base_url . " | original relative url: " . $url);
            return $url; 
        }
        $base = parse_url($base_url); // Re-parse after attempting reconstruction
        if (empty($base['scheme']) || empty($base['host'])) {
            // error_log("make_absolute: Still invalid base after reconstruction: " . $base_url);
            return $url; // Final fallback
        }
    }

    // If URL is protocol-relative (e.g., //example.com/path)
    if (substr($url, 0, 2) === '//') {
        return $base['scheme'] . ':' . $url;
    }

    // Construct the path prefix (scheme://host:port)
    $path_prefix = $base['scheme'] . '://' . $base['host'];
    if (isset($base['port'])) {
        $path_prefix .= ':' . $base['port'];
    }

    // If URL is a fragment
    if ($url[0] === '#') {
        return rtrim($base_url, '#?') . $url; // Append fragment to full base URL
    }
    
    // If URL is a query string
    if ($url[0] === '?') {
        $base_url_no_query_fragment = strtok($base_url, '?#');
        return rtrim($base_url_no_query_fragment, '/') . $url;
    }
    
    // Determine the directory part of the base URL's path
    $base_path_dir_segment = '';
    if (isset($base['path'])) {
        // If base path ends with / or is just /, it's a directory
        if (substr($base['path'], -1) === '/' || $base['path'] === '/') {
            $base_path_dir_segment = $base['path'];
        } else {
            $base_path_dir_segment = dirname($base['path']);
        }
    }
    // Normalize dirname result: . or \ might become /
    if ($base_path_dir_segment === '.' || $base_path_dir_segment === '' || $base_path_dir_segment === '\\') {
        $base_path_dir_segment = '/';
    }
    // Ensure it starts and ends with a slash if it's not just "/"
    if ($base_path_dir_segment !== '/') {
        if ($base_path_dir_segment[0] !== '/') $base_path_dir_segment = '/' . $base_path_dir_segment;
        if (substr($base_path_dir_segment, -1) !== '/') $base_path_dir_segment .= '/';
    }
    
    $absolute_path = '';
    if ($url[0] === '/') { // URL is an absolute path
        $absolute_path = $url;
    } else { // URL is a relative path
        $absolute_path = $base_path_dir_segment . $url;
    }

    // Normalize path (resolve ../ and ./)
    $parts = [];
    // Ensure leading slash for explode, handle case where $absolute_path might be just '/'
    $path_to_explode = ($absolute_path === '/') ? '' : ltrim($absolute_path, '/');

    foreach (explode('/', $path_to_explode) as $part) {
        if ($part === '.' || ($part === '' && !empty($parts))) { // Skip . and empty parts (multiple slashes) unless it's the first part after root
            continue;
        }
        if ($part === '..') { // Go up one level
            if (!empty($parts)) { // Only pop if not at root
                 array_pop($parts);
            }
        } else { // Add path segment
            $parts[] = $part;
        }
    }
    $normalized_path = '/' . implode('/', $parts);
    // If original path was '/' and parts is empty (e.g. from '/./' or '/../'), ensure it's still '/'
    if ($absolute_path === '/' && empty($parts)) {
        $normalized_path = '/';
    }


    $final_absolute_url = $path_prefix . $normalized_path;
    
    // Re-append query string and fragment from the original relative URL if they existed
    $original_url_parts = parse_url($url);
    if (isset($original_url_parts['query'])) $final_absolute_url .= '?' . $original_url_parts['query'];
    if (isset($original_url_parts['fragment'])) $final_absolute_url .= '#' . $original_url_parts['fragment'];
    
    return $final_absolute_url;
}

// Function to construct the base URL for the proxy script itself.
// This is used to rewrite relative URLs in the fetched content to point back to the proxy.
function get_proxy_url_prefix() {
    // Determine scheme (http or https)
    $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    // Get host name
    $host = $_SERVER['HTTP_HOST'];
    // Get the path to the current script
    $script_name = $_SERVER['SCRIPT_NAME'];
    // Construct the base path of the proxy script itself, with a ?url= query param start
    // htmlspecialchars is used to prevent XSS if this URL is embedded in HTML attributes incorrectly,
    // though for URL construction it might be better to use rawurlencode on components if needed.
    // Since this is for the *proxy's* URL, and we control it, it's generally safe.
    return htmlspecialchars($scheme . "://" . $host . $script_name . "?url=", ENT_QUOTES, 'UTF-8');
}


// Check if cURL extension is loaded. This is critical for the script to function.
if (!function_exists('curl_init')) {
    sendJsonResponse([
        'success' => false, 
        'error' => 'cURL extension is not installed or enabled on the server. This script requires cURL to function.',
        'finalUrl' => '', 
        'rawFinalUrl' => '', 
        'statusCode' => 500, // Internal Server Error, as it's a server configuration issue
        'content' => null
    ], 500);
}

// Get 'url' parameter from query string, trim whitespace.
$url = isset($_GET['url']) ? trim($_GET['url']) : '';

// If URL is empty, return an error.
if (empty($url)) {
    sendJsonResponse(['success' => false, 'error' => 'URL cannot be empty.', 'finalUrl' => '', 'rawFinalUrl' => '', 'content' => null, 'statusCode' => 400], 400); // Bad Request
}

// Automatically prepend 'https://' if no scheme is present.
// This assumes HTTPS by default for scheme-less URLs like 'example.com'.
if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
    $url = "https://".$url;
}

// Validate the URL format.
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    sendJsonResponse(['success' => false, 'error' => 'Invalid URL format: ' . htmlspecialchars($url), 'finalUrl' => htmlspecialchars($url), 'rawFinalUrl' => $url, 'content' => null, 'statusCode' => 400], 400); // Bad Request
}

// Attempt to create a temporary file for cookies.
// This helps maintain sessions across requests to the target site.
$cookieFile = null; 
// Check if the system's temporary directory is writable.
if (is_writable(sys_get_temp_dir())) {
    $cookieFile = tempnam(sys_get_temp_dir(), 'unblockme_cookie_');
}

// If cookie file creation failed, log a warning and proceed without cookie persistence.
if ($cookieFile === false || $cookieFile === null) { 
    // error_log("Warning: Failed to create temporary cookie file in proxy.php. Proceeding without cookie persistence.");
    $cookieFile = null; // Ensure it's explicitly null if creation failed.
} else {
    // Register a shutdown function to delete the cookie file when the script finishes.
    register_shutdown_function(function() use ($cookieFile) {
        if ($cookieFile && file_exists($cookieFile)) {
            unlink($cookieFile);
        }
    });
}

// Initialize cURL session.
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url); // Set the URL to fetch.
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the transfer as a string.
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow HTTP redirects.
curl_setopt($ch, CURLOPT_MAXREDIRS, 10); // Limit the number of redirects.
curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Set a timeout for the request (seconds).
// Set a common User-Agent string to mimic a browser.
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4896.127 Safari/537.36 UnblockMeProxy/1.1'); 
curl_setopt($ch, CURLOPT_ENCODING, ""); // Handle all encodings (gzip, deflate, etc.).

// Attempt to set preferred TLS version. TLS 1.2 is widely supported.
// Some servers might require TLS 1.3, some older ones might need TLS 1.1 (less secure).
// CURL_SSLVERSION_TLSv1_2 is 6.
if (defined('CURL_SSLVERSION_TLSv1_2')) {
    curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
}

// Define custom HTTP headers for the request.
$requestHeaders = [
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
    'Accept-Language: en-US,en;q=0.9',
    'DNT: 1', // Do Not Track header.
    'Upgrade-Insecure-Requests: 1' // Signal to servers preference for HTTPS.
];
// Try to set Origin and Referer headers based on the target URL.
$urlParts = parse_url($url);
if (isset($urlParts['scheme']) && isset($urlParts['host'])) {
    $referer = $urlParts['scheme'] . '://' . $urlParts['host'] . '/';
    $requestHeaders[] = 'Origin: ' . $urlParts['scheme'] . '://' . $urlParts['host'];
} else {
    $referer = $url; // Fallback if URL parsing for scheme/host fails.
}
$requestHeaders[] = 'Referer: ' . $referer;

curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders); // Set the custom headers.

// If a cookie file was created, use it for storing and sending cookies.
if ($cookieFile) { 
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile); 
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
}

// SSL verification settings. Important for security.
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Verify the peer's SSL certificate.
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); // Check that the Common Name or Subject Alternative Name in the certificate matches the hostname.
// For environments with SSL certificate bundle issues, you might need to specify a CA bundle.
// curl_setopt($ch, CURLOPT_CAINFO, '/path/to/your/cacert.pem'); // Example path

// Execute the cURL request.
$content = curl_exec($ch);
// Get information about the request.
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // HTTP status code.
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url; // Final URL after redirects.
$curlError = curl_error($ch); // cURL error message, if any.
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE); // Content-Type of the response.
curl_close($ch); // Close cURL session.

// Handle cURL errors (e.g., network issues, DNS failures).
if ($curlError) {
    sendJsonResponse([
        'success' => false, 
        'error' => 'cURL Error: ' . htmlspecialchars($curlError), 
        'statusCode' => $statusCode ?: 503, // Use 503 Service Unavailable if status code is 0
        'finalUrl' => htmlspecialchars($finalUrl),
        'rawFinalUrl' => $finalUrl,
        'content' => null // No content on cURL error
    ], $statusCode ?: 503); 
}

// Process successful responses (HTTP status 200-399).
if ($statusCode >= 200 && $statusCode < 400) {
    // Only process HTML content for rewriting. Other content types (images, CSS, JS files directly)
    // are passed through if they are directly requested by URL. Here, we assume primary fetch is HTML.
    if ($contentType && stripos($contentType, 'text/html') !== false) {
        
        // Add or replace <base> tag to correctly resolve relative URLs in the fetched content.
        // The target="_self" ensures links open in the same iframe/context.
        $baseHref = '&lt;base href="'.htmlspecialchars($finalUrl, ENT_QUOTES, 'UTF-8').'" target="_self" /&gt;';
        if (stripos($content, '&lt;head&gt;') !== false) {
            // If a base tag already exists, replace it.
            if (preg_match('/&lt;base\s[^&gt;]*&gt;/i', $content)) {
                $content = preg_replace('/&lt;base\s[^&gt;]*&gt;/i', $baseHref, $content, 1);
            } else {
                // If no base tag, add it inside the &lt;head&gt;.
                $content = preg_replace('/(&lt;head\b[^&gt;]*&gt;)/i', '$1'.$baseHref, $content, 1);
            }
        } else {
            // If no &lt;head&gt; tag, prepend base tag. This is a fallback and might not be ideal for all HTML structures.
            $content = $baseHref . $content;
        }

        // Remove Content Security Policy (CSP) meta tags, Subresource Integrity (SRI) attributes,
        // and nonce attributes, as they can interfere with proxying and script execution.
        $content = preg_replace('/&lt;meta http-equiv=["\']Content-Security-Policy["\'][^&gt;]*&gt;/i', '', $content);
        // Remove integrity attributes from link and script tags
        $content = preg_replace('/\s+integrity\s*=\s*([\'"])[^\'"]*\1/i', '', $content);
        // Specifically target link tags for integrity removal as well
        $content = preg_replace_callback('/&lt;link([^&gt;]*)integrity=([\'"])[^\'"]*\2([^&gt;]*)&gt;/is', function($matches) {
            return '&lt;link' . $matches[1] . $matches[3] . '&gt;';
        }, $content);
        $content = preg_replace('/\s+nonce\s*=\s*([\'"])[^\'"]*\1/i', '', $content);
        // Attempt to neutralize Service Worker registrations
        $content = preg_replace('/navigator\.serviceWorker\s*\.\s*register\s*\(([^)]+)\)/i', 'console.warn("ServiceWorker registration blocked by proxy: $1")', $content);

        // Get the prefix for proxying URLs (e.g., "http://yourproxy.com/proxy.php?url=").
        $proxyUrlPrefix = get_proxy_url_prefix();

        // Attributes in HTML tags that commonly contain URLs to be rewritten.
        $attributesToRewrite = ['src', 'href', 'action', 'data-src', 'poster', 'background', 'data-url', 'data-href', 'srcset', 'formaction']; 
        foreach ($attributesToRewrite as $attr) {
            if ($attr === 'srcset') {
                // Special handling for srcset: rewrite each URL in the set.
                 $content = preg_replace_callback(
                    // Regex for srcset: matches srcset attribute and its value.
                    // Ensures it captures content within quotes or unquoted.
                    '/(&lt;[^&gt;]+srcset\s*=\s*)([\'"]?)([^"\'&lt;&gt;]+)([\'"]?)/i',
                    function ($matches) use ($finalUrl, $proxyUrlPrefix) {
                        $srcset_values = explode(',', $matches[3]); // Split srcset by comma.
                        $new_srcset_values = [];
                        foreach ($srcset_values as $value_pair) {
                            $parts = preg_split('/\s+/', trim($value_pair)); // Split URL and descriptor.
                            $url_part = trim($parts[0]);
                            $descriptor_part = isset($parts[1]) ? ' ' . trim($parts[1]) : '';
                            
                            // Skip if URL is absolute, data URI, or empty.
                            if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/|#)/i', $url_part) || empty(trim($url_part))) {
                                $new_srcset_values[] = $url_part . $descriptor_part;
                            } else {
                                $absoluteUrl = make_absolute($url_part, $finalUrl);
                                $new_srcset_values[] = htmlspecialchars($proxyUrlPrefix . urlencode($absoluteUrl), ENT_QUOTES, 'UTF-8') . $descriptor_part;
                            }
                        }
                        // Reconstruct the srcset attribute.
                        return $matches[1] . $matches[2] . implode(', ', $new_srcset_values) . $matches[4];
                    },
                    $content
                );
            } else {
                // Standard attribute URL rewriting (links through proxy).
                // Regex for attributes: matches attribute=value, captures value.
                // Avoids matching URLs starting with # (fragments) or known schemes directly.
                // It tries to capture URLs that are likely relative or need proxying.
                $pattern = '/(&lt;[^&gt;]+' . preg_quote($attr, '/') . '\s*=\s*)([\'"]?)([^"\'\s&lt;&gt;][^"\'\s&lt;&gt;]*|[^"\'\s&lt;&gt;]*\?[^"\'\s&lt;&gt;]*|[^"\'\s&lt;&gt;]*#[^"\'\s&lt;&gt;]*)([\'"]?)/i';
                $content = preg_replace_callback(
                    $pattern,
                    function ($matches) use ($finalUrl, $proxyUrlPrefix) {
                        $originalUrl = html_entity_decode($matches[3]); // Decode HTML entities in URL.
                        // Skip if URL is absolute (has scheme), data URI, protocol-relative, or fragment-only.
                        if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/|#)/i', $originalUrl) || empty(trim($originalUrl))) {
                            return $matches[0]; // Return original match if no rewrite needed.
                        }
                        $absoluteUrl = make_absolute($originalUrl, $finalUrl);
                        // All proxied resources should go through the proxy script again.
                        // Encode the absolute URL for safe inclusion in the proxy query parameter.
                        return $matches[1] . $matches[2] . htmlspecialchars($proxyUrlPrefix . urlencode($absoluteUrl), ENT_QUOTES, 'UTF-8') . $matches[4];
                    },
                    $content
                );
            }
        }
        
        // Rewrite URLs in inline style="... url(...)" (links through proxy).
        $content = preg_replace_callback(
            // Regex for url() in styles: matches url(value), captures value.
            '/(url\s*\(\s*)([\'"]?)([^"\'\)\s&lt;&gt;][^"\'\)\s&lt;&gt;]*|[^"\'\)\s&lt;&gt;]*\?[^"\'\)\s&lt;&gt;]*|[^"\'\)\s&lt;&gt;]*#[^"\'\)\s&lt;&gt;]*)([\'"]?)(\s*\))/i',
            function ($matches) use ($finalUrl, $proxyUrlPrefix) {
                $originalUrl = html_entity_decode($matches[3]); // Decode URL.
                // Skip if absolute, data URI, etc.
                if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/|#)/i', $originalUrl) || empty(trim($originalUrl))) {
                    return $matches[0]; 
                }
                $absoluteUrl = make_absolute($originalUrl, $finalUrl);
                // Return rewritten url().
                return $matches[1] . $matches[2] . htmlspecialchars($proxyUrlPrefix . urlencode($absoluteUrl), ENT_QUOTES, 'UTF-8') . $matches[4] . $matches[5];
            },
            $content
        );


        // Rewrite URLs within &lt;script&gt; tags to go through the proxy if they are relative.
        // This is complex and error-prone. It targets common patterns like fetch, XHR, and location assignments.
        // It's disabled by default in many simple proxies due to high risk of breaking scripts.
        // For UnblockMe, a more aggressive approach is attempted.
        $content = preg_replace_callback(
            '/(&lt;script\b[^&gt;]*&gt;)(.*?)(&lt;\/script&gt;)/is', // Process content of each script tag.
            function ($script_matches) use ($finalUrl, $proxyUrlPrefix) {
                $script_content = $script_matches[2];

                // Patterns for common JS URL usages.
                // These patterns try to find string literals that look like relative URLs.
                $js_patterns = [
                    // fetch('relative/path') or fetch("relative/path")
                    // Group 1: Prefix (e.g., "fetch(" )
                    // Group 2: Quote (' or ")
                    // Group 3: URL path
                    // Group 4: Suffix (e.g., "')")
                    '/(fetch\s*\(\s*)([\'"])([^"\'#:\s][^\'"{}]*?)(?&lt;![a-zA-Z0-9\.])([\R\n\s]*[\'"]\s*[,)])/i', // More careful about not matching JS object keys
                    // new XMLHttpRequest().open('GET', 'relative/path')
                    '/((?:xhr|xmlHttpRequest|new XMLHttpRequest\(\))\s*\.\s*open\s*\(\s*[\'"][A-Z]+[\'"]\s*,\s*)([\'"])([^"\'#:\s][^\'"{}]*?)(?&lt;![a-zA-Z0-9\.])([\R\n\s]*[\'"]\s*[,)])/i',
                    // location.href = 'relative/path' or location = 'relative/path'
                    '/(location\s*(?:\.href\s*)?\s*=\s*)([\'"])([^"\'#:\s][^\'"{};]*?)(?&lt;![a-zA-Z0-9\.])([\R\n\s]*[\'"];?)/i',
                     // window.open('relative/path')
                    '/(window\.open\s*\(\s*)([\'"])([^"\'#:\s][^\'"{}]*?)(?&lt;![a-zA-Z0-9\.])([\R\n\s]*[\'"]\s*[,)])/i',
                    // Element.setAttribute('src', 'relative/path') or .href
                    '/(\.setAttribute\s*\(\s*[\'"](?:src|href)[\'"]\s*,\s*)([\'"])([^"\'#:\s][^\'"{}]*?)(?&lt;![a-zA-Z0-9\.])([\R\n\s]*[\'"]\s*\))/i'
                ];

                foreach ($js_patterns as $pattern_index => $pattern) {
                    $script_content = preg_replace_callback(
                        $pattern,
                        function ($matches) use ($finalUrl, $proxyUrlPrefix, $pattern_index) {
                            $originalUrl = $matches[3]; // The relative URL path.
                            
                            // Skip if it looks absolute, data URI, fragment, or already proxied.
                            if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/|#)/i', $originalUrl) || strpos($originalUrl, $proxyUrlPrefix) === 0 || strpos($originalUrl, '{{') !== false /* handlebars-like templates */ ) {
                                return $matches[0]; 
                            }
                            // Avoid rewriting if it looks like a JS variable or template literal placeholder.
                            if (strpos($originalUrl, '${') !== false || strpos($originalUrl, '+') !== false) {
                                return $matches[0];
                            }

                            $absoluteUrl = make_absolute($originalUrl, $finalUrl);
                            // Ensure the URL to be proxied is properly encoded for JS string context
                            // This requires escaping backslashes and quotes within the URL string.
                            $proxiedJsUrl = addslashes($proxyUrlPrefix . urlencode($absoluteUrl));
                            
                            // Reconstruct the JS call with the proxied URL.
                            // $matches[1] is prefix, $matches[2] is quote, $matches[4] is suffix.
                            return $matches[1] . $matches[2] . $proxiedJsUrl . $matches[4];
                        },
                        $script_content
                    );
                }
                // Return the modified script content within its original tags.
                return $script_matches[1] . $script_content . $script_matches[3];
            },
            $content
        );
         // Attempt to rewrite import statements for JS modules if they use relative paths
        $content = preg_replace_callback(
            '/(import\s+(?:{[^&gt;]+}\s+from\s+|[\w\s,*{}]*\s+from\s+|\s*)[\'"])([^"\':#][^\'"]*)([\'"])/i',
            function ($matches) use ($finalUrl, $proxyUrlPrefix) {
                $originalUrl = $matches[2];
                if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/|#)/i', $originalUrl) || strpos($originalUrl, $proxyUrlPrefix) === 0) {
                    return $matches[0];
                }
                $absoluteUrl = make_absolute($originalUrl, $finalUrl);
                $proxiedJsUrl = $proxyUrlPrefix . urlencode($absoluteUrl);
                return $matches[1] . $proxiedJsUrl . $matches[3];
            },
            $content
        );


    } // End of HTML processing

    // Send successful response with content.
    sendJsonResponse([
        'success' => true, 
        'content' => $content, 
        'statusCode' => $statusCode, 
        'finalUrl' => htmlspecialchars($finalUrl, ENT_QUOTES, 'UTF-8'), 
        'rawFinalUrl' => $finalUrl, 
        'contentType' => $contentType
    ]);

} else { // Handle HTTP error codes from the target server (4xx, 5xx).
    // Determine a suitable HTTP status code for the proxy's response.
    // If status code is 0 (e.g. connection timeout before HTTP response) or &gt;=500 (server error on target), proxy returns 502 Bad Gateway.
    // Otherwise, proxy returns the same status code it received.
    $httpErrorCode = ($statusCode == 0 || $statusCode &gt;= 500) ? 502 : $statusCode; 
    
    // Construct a user-friendly error message.
    $errorMsg = "Failed to fetch content. The remote server responded with status: $statusCode.";
    if ($statusCode === 0 && empty($curlError)) { 
        $errorMsg = "Failed to fetch content. Could not connect to the server, the URL may be invalid, or the target server is not responding.";
    } else if (!empty($curlError)) { // If cURL provided a specific error message, use it.
        $errorMsg = "cURL Error: " . htmlspecialchars($curlError);
    } else if ($statusCode === 403) {
        $errorMsg .= " Access Forbidden. The target site may be blocking direct access or proxy attempts.";
    } else if ($statusCode === 404) {
        $errorMsg .= " Not Found. The requested resource was not found on the target server.";
    } else if ($statusCode &gt;= 500) {
        $errorMsg .= " The target server encountered an internal error.";
    } else if ($statusCode &gt;= 400 && $statusCode &lt; 500) { // Other 4xx client errors.
        $errorMsg .= " There was an issue with the request to the target server (e.g., bad request, unauthorized).";
    }

    // Send error response.
    sendJsonResponse([
        'success' => false, 
        'error' => $errorMsg, 
        'statusCode' => $statusCode, // Report the original status code from target.
        'finalUrl' => htmlspecialchars($finalUrl, ENT_QUOTES, 'UTF-8'),
        'rawFinalUrl' => $finalUrl,
        'content' => $content // Include content if any was returned with the error (e.g. custom error page from target)
    ], $httpErrorCode); // Use $httpErrorCode for the proxy's own response status.
}
?&gt;

