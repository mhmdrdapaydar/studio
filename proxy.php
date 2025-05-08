<?php
// Set a longer execution time limit if needed, default is often 30 or 60 seconds.
// ini_set('max_execution_time', 120); // 120 seconds

// Helper function to send JSON responses
function sendJsonResponse($data, $httpStatusCode = 200) {
    $jsonOutput = json_encode($data);

    if ($jsonOutput === false) {
        $jsonLastErrorMsg = json_last_error_msg();
        // error_log('json_encode error in proxy.php: ' . $jsonLastErrorMsg . ' | Data keys: ' . implode(', ', array_keys($data)));
        $errorData = [
            'success' => false,
            'error' => 'Server-side JSON encoding error. ' . ($jsonLastErrorMsg ?: 'Unknown encoding error.'),
            'statusCode' => 500,
            'finalUrl' => isset($data['rawFinalUrl']) ? htmlspecialchars($data['rawFinalUrl']) : (isset($data['finalUrl']) ? htmlspecialchars($data['finalUrl']) : ''),
            'rawFinalUrl' => isset($data['rawFinalUrl']) ? $data['rawFinalUrl'] : (isset($data['finalUrl']) ? $data['finalUrl'] : ''),
            'content' => null
        ];
        $jsonOutput = json_encode($errorData);
        $httpStatusCode = 500;
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
    if (empty(trim($url))) return $base_url;
    
    if (preg_match('~^(?:[a-z][a-z0-9+-.]*:|data:|mailto:|tel:)~i', $url)) {
        return $url;
    }

    $base = parse_url($base_url);
    if (empty($base['scheme']) || empty($base['host'])) {
        if (strpos($base_url, '//') === 0) {
             $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . ':' . $base_url;
             $base = parse_url($base_url);
        } elseif (strpos($base_url, '/') === false && strpos($base_url, '.') !== false) {
             $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $base_url;
             $base = parse_url($base_url);
        }
        if (empty($base['scheme']) || empty($base['host'])) {
           return $url; 
        }
    }

    if (substr($url, 0, 2) === '//') {
        return $base['scheme'] . ':' . $url;
    }

    $path_prefix = $base['scheme'] . '://' . $base['host'];
    if (isset($base['port'])) {
        $path_prefix .= ':' . $base['port'];
    }

    if ($url[0] === '#') {
        return rtrim($base_url, '#?') . $url;
    }
    
    if ($url[0] === '?') {
        $base_url_no_query = strtok($base_url, '?');
        return rtrim($base_url_no_query, '/') . $url;
    }
    
    $base_path_dir_segment = '';
    if (isset($base['path'])) {
        if (substr($base['path'], -1) === '/' || $base['path'] === '/') {
            $base_path_dir_segment = $base['path'];
        } else {
            $base_path_dir_segment = dirname($base['path']);
        }
    }
    
    if ($base_path_dir_segment === '.' || $base_path_dir_segment === '' || $base_path_dir_segment === '\\') {
        $base_path_dir_segment = '/';
    } else {
        if ($base_path_dir_segment[0] !== '/') $base_path_dir_segment = '/' . $base_path_dir_segment;
        if (substr($base_path_dir_segment, -1) !== '/') $base_path_dir_segment .= '/';
    }
    
    $absolute_path = '';
    if ($url[0] === '/') {
        $absolute_path = $url;
    } else {
        $absolute_path = $base_path_dir_segment . $url;
    }

    $parts = [];
    $path_to_explode = $absolute_path === '/' ? '' : ltrim($absolute_path, '/');

    foreach (explode('/', $path_to_explode) as $part) {
        if ($part === '.' || ($part === '' && !empty($parts))) {
            continue;
        }
        if ($part === '..') {
            if (!empty($parts)) {
                 array_pop($parts);
            }
        } else {
            $parts[] = $part;
        }
    }
    $normalized_path = '/' . implode('/', $parts);
    if ($absolute_path === '/' && empty($parts)) {
        $normalized_path = '/';
    }

    $final_absolute_url = $path_prefix . $normalized_path;
    
    $original_url_parts = parse_url($url);
    if (isset($original_url_parts['query'])) $final_absolute_url .= '?' . $original_url_parts['query'];
    if (isset($original_url_parts['fragment'])) $final_absolute_url .= '#' . $original_url_parts['fragment'];
    
    return $final_absolute_url;
}

function get_proxy_url_prefix() {
    $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $script_name = $_SERVER['SCRIPT_NAME'];
    // Construct the base path of the proxy script itself
    return htmlspecialchars($scheme . "://" . $host . $script_name . "?url=", ENT_QUOTES, 'UTF-8');
}


if (!function_exists('curl_init')) {
    sendJsonResponse([
        'success' => false, 
        'error' => 'cURL extension is not installed or enabled on the server. This script requires cURL to function.',
        'finalUrl' => '', 
        'rawFinalUrl' => '', 
        'statusCode' => 500, 
        'content' => null
    ], 500);
}

$url = isset($_GET['url']) ? trim($_GET['url']) : '';

if (empty($url)) {
    sendJsonResponse(['success' => false, 'error' => 'URL cannot be empty.', 'finalUrl' => '', 'rawFinalUrl' => '', 'content' => null, 'statusCode' => 400], 400);
}

if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
    $url = "https://".$url;
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    sendJsonResponse(['success' => false, 'error' => 'Invalid URL format: ' . htmlspecialchars($url), 'finalUrl' => htmlspecialchars($url), 'rawFinalUrl' => $url, 'content' => null, 'statusCode' => 400], 400);
}

$cookieFile = null; 
if (is_writable(sys_get_temp_dir())) {
    $cookieFile = tempnam(sys_get_temp_dir(), 'unblockme_cookie_');
}

if ($cookieFile === false || $cookieFile === null) { 
    // error_log("Warning: Failed to create temporary cookie file in proxy.php. Proceeding without cookie persistence.");
    $cookieFile = null; 
} else {
    register_shutdown_function(function() use ($cookieFile) {
        if ($cookieFile && file_exists($cookieFile)) {
            unlink($cookieFile);
        }
    });
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Increased timeout
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4896.127 Safari/537.36 UnblockMeProxy/1.1'); 
curl_setopt($ch, CURLOPT_ENCODING, ""); 
// Attempt to set preferred TLS version. TLS 1.2 is widely supported.
// Some servers might require TLS 1.3, some older ones might need TLS 1.1 (less secure).
// CURL_SSLVERSION_TLSv1_2 is 6.
if (defined('CURL_SSLVERSION_TLSv1_2')) {
    curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
}


$requestHeaders = [
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
    'Accept-Language: en-US,en;q=0.9',
    'DNT: 1', 
    'Upgrade-Insecure-Requests: 1' 
];
$urlParts = parse_url($url);
if (isset($urlParts['scheme']) && isset($urlParts['host'])) {
    $referer = $urlParts['scheme'] . '://' . $urlParts['host'] . '/';
    $requestHeaders[] = 'Origin: ' . $urlParts['scheme'] . '://' . $urlParts['host'];
} else {
    $referer = $url; 
}
$requestHeaders[] = 'Referer: ' . $referer;

curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);

if ($cookieFile) { 
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile); 
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
}

curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); 
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); 
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
        'statusCode' => $statusCode ?: 503, 
        'finalUrl' => htmlspecialchars($finalUrl),
        'rawFinalUrl' => $finalUrl,
        'content' => null
    ], $statusCode ?: 503); 
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
            // If no <head>, prepend base tag. This might not be ideal but is a fallback.
            $content = $baseHref . $content;
        }

        // Remove CSP, integrity, and nonce attributes which can interfere with proxying
        $content = preg_replace('/<meta http-equiv=["\']Content-Security-Policy["\'][^>]*>/i', '', $content);
        $content = preg_replace('/\s+integrity\s*=\s*([\'"])[^\'"]*\1/i', '', $content);
        $content = preg_replace_callback('/<link([^>]*)integrity=([\'"])[^\'"]*\2([^>]*)>/is', function($matches) {
            return '<link' . $matches[1] . $matches[3] . '>';
        }, $content);
        $content = preg_replace('/\s+nonce\s*=\s*([\'"])[^\'"]*\1/i', '', $content);
        $content = preg_replace('/navigator\.serviceWorker\s*\.\s*register\s*\(([^)]+)\)/i', 'console.warn("ServiceWorker registration blocked by proxy: $1")', $content);

        $proxyUrlPrefix = get_proxy_url_prefix();

        // Rewrite attributes in HTML tags
        $attributesToRewrite = ['src', 'href', 'action', 'data-src', 'poster', 'background', 'data-url', 'data-href', 'srcset']; 
        foreach ($attributesToRewrite as $attr) {
            if ($attr === 'srcset') {
                // Special handling for srcset: rewrite each URL in the set
                 $content = preg_replace_callback(
                    '/(<[^>]+srcset\s*=\s*)([\'"]?)([^"\'<>]+)([\'"]?)/i',
                    function ($matches) use ($finalUrl, $proxyUrlPrefix) {
                        $srcset_values = explode(',', $matches[3]);
                        $new_srcset_values = [];
                        foreach ($srcset_values as $value_pair) {
                            $parts = preg_split('/\s+/', trim($value_pair));
                            $url_part = trim($parts[0]);
                            $descriptor_part = isset($parts[1]) ? ' ' . trim($parts[1]) : '';
                            
                            if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/|#)/i', $url_part) || empty(trim($url_part))) {
                                $new_srcset_values[] = $url_part . $descriptor_part;
                            } else {
                                $absoluteUrl = make_absolute($url_part, $finalUrl);
                                $new_srcset_values[] = htmlspecialchars($proxyUrlPrefix . urlencode($absoluteUrl), ENT_QUOTES, 'UTF-8') . $descriptor_part;
                            }
                        }
                        return $matches[1] . $matches[2] . implode(', ', $new_srcset_values) . $matches[4];
                    },
                    $content
                );
            } else {
                // Standard attribute URL rewriting (links through proxy)
                $pattern = '/(<[^>]+' . preg_quote($attr, '/') . '\s*=\s*)([\'"]?)([^"\'\s#<>][^"\'\s<>]*|[^"\'\s<>]*\?[^"\'\s<>]*|[^"\'\s<>]*#[^"\'\s<>]*)([\'"]?)/i';
                $content = preg_replace_callback(
                    $pattern,
                    function ($matches) use ($finalUrl, $proxyUrlPrefix) {
                        $originalUrl = html_entity_decode($matches[3]); 
                        if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/|#)/i', $originalUrl) || empty(trim($originalUrl))) {
                            return $matches[0]; 
                        }
                        $absoluteUrl = make_absolute($originalUrl, $finalUrl);
                        // All proxied resources should go through the proxy script again
                        return $matches[1] . $matches[2] . htmlspecialchars($proxyUrlPrefix . urlencode($absoluteUrl), ENT_QUOTES, 'UTF-8') . $matches[4];
                    },
                    $content
                );
            }
        }
        
        // Rewrite URLs in inline style="... url(...)" (links through proxy)
        $content = preg_replace_callback(
            '/(url\s*\(\s*)([\'"]?)([^"\'\)\s#<>][^"\'\)\s<>]*|[^"\'\)\s<>]*\?[^"\'\)\s<>]*|[^"\'\)\s<>]*#[^"\'\)\s<>]*)([\'"]?)(\s*\))/i',
            function ($matches) use ($finalUrl, $proxyUrlPrefix) {
                $originalUrl = html_entity_decode($matches[3]);
                if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/|#)/i', $originalUrl) || empty(trim($originalUrl))) {
                    return $matches[0]; 
                }
                $absoluteUrl = make_absolute($originalUrl, $finalUrl);
                return $matches[1] . $matches[2] . htmlspecialchars($proxyUrlPrefix . urlencode($absoluteUrl), ENT_QUOTES, 'UTF-8') . $matches[4] . $matches[5];
            },
            $content
        );


        // Rewrite URLs within <script> tags to go through the proxy if they are relative
        // This is complex and can break scripts, but necessary for some dynamic sites.
        // It tries to catch common patterns like fetch('relative/path'), xhr.open('GET', 'relative/path'), location.href = 'relative/path'
        $content = preg_replace_callback(
            '/(<script\b[^>]*>)(.*?)(<\/script>)/is', // Process content of each script tag
            function ($script_matches) use ($finalUrl, $proxyUrlPrefix) {
                $script_content = $script_matches[2];

                // Pattern for fetch, XHR open, and location assignments with relative URLs
                // Looks for (fetch|open|location\s*(=|\.href\s*=))\s*\(\s*['"]([^'"#:\s][^'"]*)['"]
                // Or location.href = 'relative/path'
                $js_patterns = [
                    // fetch('relative/path') or fetch("relative/path")
                    '/(fetch\s*\(\s*)([\'"])([^"\'#:\s][^\'"]*?)([\'"]\s*[,\)])/i',
                    // new XMLHttpRequest().open('GET', 'relative/path')
                    '/((?:xhr|xmlHttpRequest|new XMLHttpRequest\(\))\s*\.\s*open\s*\(\s*[\'"][A-Z]+[\'"]\s*,\s*)([\'"])([^"\'#:\s][^\'"]*?)([\'"]\s*)/i',
                    // location.href = 'relative/path' or location = 'relative/path'
                    '/(location\s*(?:\.href\s*)?\s*=\s*)([\'"])([^"\'#:\s][^\'"]*?)([\'"])/i'
                ];

                foreach ($js_patterns as $pattern) {
                    $script_content = preg_replace_callback(
                        $pattern,
                        function ($matches) use ($finalUrl, $proxyUrlPrefix) {
                            $originalUrl = $matches[3]; // The relative URL path
                            // If it already looks absolute, data URI, or fragment, skip.
                            if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/|#)/i', $originalUrl) || strpos($originalUrl, $proxyUrlPrefix) === 0) {
                                return $matches[0]; 
                            }
                            $absoluteUrl = make_absolute($originalUrl, $finalUrl);
                            $proxiedJsUrl = $proxyUrlPrefix . urlencode($absoluteUrl);
                            // Reconstruct the JS call with the proxied URL
                            // $matches[1] is prefix, $matches[2] is quote, $matches[4] is suffix
                            return $matches[1] . $matches[2] . $proxiedJsUrl . $matches[4];
                        },
                        $script_content
                    );
                }
                return $script_matches[1] . $script_content . $script_matches[3];
            },
            $content
        );

    }

    sendJsonResponse([
        'success' => true, 
        'content' => $content, 
        'statusCode' => $statusCode, 
        'finalUrl' => htmlspecialchars($finalUrl, ENT_QUOTES, 'UTF-8'), 
        'rawFinalUrl' => $finalUrl, 
        'contentType' => $contentType
    ]);

} else {
    $httpErrorCode = ($statusCode == 0 || $statusCode >= 500) ? 502 : $statusCode; 
    
    $errorMsg = "Failed to fetch content. The remote server responded with status: $statusCode.";
    if ($statusCode === 0 && empty($curlError)) { 
        $errorMsg = "Failed to fetch content. Could not connect to the server, the URL may be invalid, or the target server is not responding.";
    } else if (!empty($curlError)) { 
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
        'content' => $content 
    ], $httpErrorCode);
}
?>
