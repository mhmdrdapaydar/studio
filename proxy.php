<?php
// Set a longer execution time limit if needed, default is often 30 or 60 seconds.
// ini_set('max_execution_time', 120); // 120 seconds

// Helper function to send JSON responses
function sendJsonResponse($data, $httpStatusCode = 200) {
    // Attempt to ensure content is UTF-8, especially if it's text.
    if (isset($data['content']) && is_string($data['content'])) {
        if (!mb_check_encoding($data['content'], 'UTF-8')) {
            // If not UTF-8, try to convert from ISO-8859-1 (common fallback) or detect.
            // This is a basic attempt; more sophisticated charset detection might be needed.
            $detectedEncoding = mb_detect_encoding($data['content'], 'UTF-8, ISO-8859-1, Windows-1252', true);
            if ($detectedEncoding && $detectedEncoding !== 'UTF-8') {
                $data['content'] = mb_convert_encoding($data['content'], 'UTF-8', $detectedEncoding);
            } elseif (!$detectedEncoding) {
                // If detection fails, try a common conversion or use substitute/ignore options.
                // Forcing to UTF-8 with substitution for invalid characters.
                 $data['content'] = mb_convert_encoding($data['content'], 'UTF-8', 'UTF-8');
            }
        }
    }

    $jsonOutput = json_encode($data);

    if ($jsonOutput === false) {
        $jsonLastErrorMsg = json_last_error_msg();
        // error_log('Primary json_encode error in proxy.php: ' . $jsonLastErrorMsg . ' | Data keys: ' . (is_array($data) ? implode(', ', array_keys($data)) : 'Non-array data'));
        
        $finalUrlString = '';
        if (isset($data['rawFinalUrl']) && is_string($data['rawFinalUrl'])) {
            $finalUrlString = $data['rawFinalUrl'];
        } elseif (isset($data['finalUrl']) && is_string($data['finalUrl'])) {
            $finalUrlString = $data['finalUrl'];
        }
        
        $safeFinalUrl = htmlspecialchars($finalUrlString, ENT_QUOTES, 'UTF-8');

        $errorData = [
            'success' => false,
            'error' => 'Server-side JSON encoding error. ' . ($jsonLastErrorMsg ?: 'Unknown encoding error.'),
            'statusCode' => 500, 
            'finalUrl' => $safeFinalUrl, 
            'rawFinalUrl' => $finalUrlString, 
            'content' => 'Error: Content could not be encoded to JSON. Possible non-UTF8 characters.'
        ];
        
        // Attempt to encode the error data itself.
        $jsonOutput = json_encode($errorData);
        $httpStatusCode = 500;

        if ($jsonOutput === false) {
            // error_log('Fallback json_encode also failed in proxy.php. Last error: ' . json_last_error_msg());
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
            }
            $jsonEncodedFinalUrl = json_encode($finalUrlString); 
            if ($jsonEncodedFinalUrl === false) $jsonEncodedFinalUrl = '""';
            $hardcodedError = '{"success":false,"error":"Fatal server-side JSON encoding error.","statusCode":500,"finalUrl":' . $jsonEncodedFinalUrl . ',"rawFinalUrl":' . $jsonEncodedFinalUrl . ',"content":null}';
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
    if (empty(trim($url))) return $base_url;
    
    if (preg_match('~^(?:[a-z][a-z0-9+-.]*:|data:|mailto:|tel:|#) obliterum_obliviscor~i', $url)) { // Added # to prevent processing fragment-only URLs incorrectly as paths.
        return $url;
    }
    // Handle fragment-only URLs relative to the base_url
    if ($url[0] === '#') {
        return rtrim($base_url, '#?') . $url;
    }


    $base = parse_url($base_url);

    if (empty($base['scheme']) || empty($base['host'])) {
        $current_script_scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        if (strpos($base_url, '//') === 0) { 
             $base_url = $current_script_scheme . ':' . $base_url;
        } elseif (strpos($base_url, '/') === false && strpos($base_url, '.') !== false) { 
             $base_url = $current_script_scheme . '://' . $base_url;
        } elseif (isset($base_url[0]) && $base_url[0] === '/') { 
            $current_script_host = $_SERVER['HTTP_HOST'];
            $base_url = $current_script_scheme . '://' . $current_script_host . $base_url;
        } else {
            // error_log("make_absolute: Could not establish a valid base scheme/host from base_url: " . $base_url . " | original relative url: " . $url);
            return $url; 
        }
        $base = parse_url($base_url); 
        if (empty($base['scheme']) || empty($base['host'])) {
            // error_log("make_absolute: Still invalid base after reconstruction: " . $base_url);
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
    
    if ($url[0] === '?') {
        $base_url_no_query_fragment = strtok($base_url, '?#');
        return rtrim($base_url_no_query_fragment, '/') . $url;
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
    }
    if ($base_path_dir_segment !== '/') {
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
    $path_to_explode = ($absolute_path === '/') ? '' : ltrim($absolute_path, '/');

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
    // Return raw URL, htmlspecialchars will be applied where this prefix is used in HTML attributes.
    return $scheme . "://" . $host . $script_name . "?url=";
}


if (!function_exists('curl_init')) {
    sendJsonResponse([
        'success' => false, 
        'error' => 'cURL extension is not installed or enabled on the server.',
        'finalUrl' => '', 
        'rawFinalUrl' => '', 
        'statusCode' => 500,
        'content' => null
    ], 500);
}

// Initialize mbstring functions for proper character encoding handling
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}


$url = isset($_GET['url']) ? trim($_GET['url']) : '';

if (empty($url)) {
    sendJsonResponse(['success' => false, 'error' => 'URL cannot be empty.', 'finalUrl' => '', 'rawFinalUrl' => '', 'content' => null, 'statusCode' => 400], 400);
}

if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
    $url = "https://".$url;
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    sendJsonResponse(['success' => false, 'error' => 'Invalid URL format: ' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8'), 'finalUrl' => htmlspecialchars($url, ENT_QUOTES, 'UTF-8'), 'rawFinalUrl' => $url, 'content' => null, 'statusCode' => 400], 400);
}

$cookieFile = null; 
if (is_writable(sys_get_temp_dir())) {
    $cookieFile = tempnam(sys_get_temp_dir(), 'unblockme_cookie_');
}

if ($cookieFile === false || $cookieFile === null) { 
    // error_log("Warning: Failed to create temporary cookie file in proxy.php.");
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
curl_setopt($ch, CURLOPT_TIMEOUT, 60); 
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4896.127 Safari/537.36 UnblockMeProxy/1.2'); 
curl_setopt($ch, CURLOPT_ENCODING, ""); 

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
$referer = $url; // Default referer to the original URL
if (isset($urlParts['scheme']) && isset($urlParts['host'])) {
    $origin = $urlParts['scheme'] . '://' . $urlParts['host'];
    $requestHeaders[] = 'Origin: ' . $origin;
    $referer = $origin . (isset($urlParts['path']) ? $urlParts['path'] : '/'); // More specific referer
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
        'error' => 'cURL Error: ' . htmlspecialchars($curlError, ENT_QUOTES, 'UTF-8'), 
        'statusCode' => $statusCode ?: 503, 
        'finalUrl' => htmlspecialchars($finalUrl, ENT_QUOTES, 'UTF-8'),
        'rawFinalUrl' => $finalUrl,
        'content' => null
    ], $statusCode ?: 503); 
}

if ($statusCode >= 200 && $statusCode < 400) {
    if ($contentType && stripos($contentType, 'text/html') !== false) {
        
        $baseHrefTag = '<base href="'.htmlspecialchars($finalUrl, ENT_QUOTES, 'UTF-8').'" target="_self" />';
        // Use raw HTML tags in regex
        if (stripos($content, '<head>') !== false) {
            if (preg_match('/<base\s[^>]*>/i', $content)) {
                $content = preg_replace('/<base\s[^>]*>/i', $baseHrefTag, $content, 1);
            } else {
                $content = preg_replace('/(<head\b[^>]*>)/i', '$1'.$baseHrefTag, $content, 1);
            }
        } else {
            $content = $baseHrefTag . $content;
        }

        $content = preg_replace('/<meta http-equiv=(["\'])Content-Security-Policy\1[^>]*>/i', '', $content);
        $content = preg_replace('/\s+integrity\s*=\s*([\'"])[^\'"]*\1/i', '', $content);
        $content = preg_replace_callback('/<link([^>]*)integrity=([\'"])[^\'"]*\2([^>]*)>/is', function($matches) {
            return '<link' . $matches[1] . $matches[3] . '>';
        }, $content);
        $content = preg_replace('/\s+nonce\s*=\s*([\'"])[^\'"]*\1/i', '', $content);
        $content = preg_replace('/navigator\.serviceWorker\s*\.\s*register\s*\(([^)]+)\)/i', 'console.warn("ServiceWorker registration blocked by proxy: $1")', $content);

        $proxyUrlPrefix = get_proxy_url_prefix(); // This is now a raw URL

        $attributesToRewrite = ['src', 'href', 'action', 'data-src', 'poster', 'background', 'data-url', 'data-href', 'srcset', 'formaction']; 
        foreach ($attributesToRewrite as $attr) {
            if ($attr === 'srcset') {
                 $content = preg_replace_callback(
                    '/(<[^>]+srcset\s*=\s*)([\'"]?)([^"\'<>]+)([\'"]?)/i', // Use raw tag chars
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
                                // Apply htmlspecialchars here when constructing the attribute value
                                $new_srcset_values[] = htmlspecialchars($proxyUrlPrefix . urlencode($absoluteUrl), ENT_QUOTES, 'UTF-8') . $descriptor_part;
                            }
                        }
                        return $matches[1] . $matches[2] . implode(', ', $new_srcset_values) . $matches[4];
                    },
                    $content
                );
            } else {
                // Use raw tag chars in regex pattern
                $pattern = '/(<[^>]+' . preg_quote($attr, '/') . '\s*=\s*)([\'"]?)([^"\'\s<>][^"\'\s<>]*|[^"\'\s<>]*\?[^"\'\s<>]*|[^"\'\s<>]*#[^"\'\s<>]*)([\'"]?)/i';
                $content = preg_replace_callback(
                    $pattern,
                    function ($matches) use ($finalUrl, $proxyUrlPrefix) {
                        $originalUrl = html_entity_decode($matches[3]); 
                        if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/|#)/i', $originalUrl) || empty(trim($originalUrl))) {
                            return $matches[0]; 
                        }
                        $absoluteUrl = make_absolute($originalUrl, $finalUrl);
                        // Apply htmlspecialchars here when constructing the attribute value
                        $proxied_attr_val = htmlspecialchars($proxyUrlPrefix . urlencode($absoluteUrl), ENT_QUOTES, 'UTF-8');
                        return $matches[1] . $matches[2] . $proxied_attr_val . $matches[4];
                    },
                    $content
                );
            }
        }
        
        $content = preg_replace_callback(
            // Use raw tag chars in regex for url()
            '/(url\s*\(\s*)([\'"]?)([^"\'\)\s<>][^"\'\)\s<>]*|[^"\'\)\s<>]*\?[^"\'\)\s<>]*|[^"\'\)\s<>]*#[^"\'\)\s<>]*)([\'"]?)(\s*\))/i',
            function ($matches) use ($finalUrl, $proxyUrlPrefix) {
                $originalUrl = html_entity_decode($matches[3]);
                if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/|#)/i', $originalUrl) || empty(trim($originalUrl))) {
                    return $matches[0]; 
                }
                $absoluteUrl = make_absolute($originalUrl, $finalUrl);
                // Apply htmlspecialchars here for the URL inside url()
                return $matches[1] . $matches[2] . htmlspecialchars($proxyUrlPrefix . urlencode($absoluteUrl), ENT_QUOTES, 'UTF-8') . $matches[4] . $matches[5];
            },
            $content
        );

        $content = preg_replace_callback(
            '/(<script\b[^>]*>)(.*?)(<\/script>)/is', // Use raw script tags
            function ($script_matches) use ($finalUrl, $proxyUrlPrefix) {
                $script_content = $script_matches[2];
                $js_patterns = [
                    '/(fetch\s*\(\s*)([\'"])([^"\'#:\s][^\'"{}]*?)(?<![a-zA-Z0-9\.])([\R\n\s]*[\'"]\s*[,)])/i',
                    '/((?:xhr|xmlHttpRequest|new XMLHttpRequest\(\))\s*\.\s*open\s*\(\s*[\'"][A-Z]+[\'"]\s*,\s*)([\'"])([^"\'#:\s][^\'"{}]*?)(?<![a-zA-Z0-9\.])([\R\n\s]*[\'"]\s*[,)])/i',
                    '/(location\s*(?:\.href\s*)?\s*=\s*)([\'"])([^"\'#:\s][^\'"{};]*?)(?<![a-zA-Z0-9\.])([\R\n\s]*[\'"];?)/i',
                    '/(window\.open\s*\(\s*)([\'"])([^"\'#:\s][^\'"{}]*?)(?<![a-zA-Z0-9\.])([\R\n\s]*[\'"]\s*[,)])/i',
                    '/(\.setAttribute\s*\(\s*[\'"](?:src|href)[\'"]\s*,\s*)([\'"])([^"\'#:\s][^\'"{}]*?)(?<![a-zA-Z0-9\.])([\R\n\s]*[\'"]\s*\))/i'
                ];

                foreach ($js_patterns as $pattern) {
                    $script_content = preg_replace_callback(
                        $pattern,
                        function ($matches) use ($finalUrl, $proxyUrlPrefix) {
                            $originalUrl = $matches[3]; 
                            if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/|#)/i', $originalUrl) || strpos($originalUrl, $proxyUrlPrefix) === 0 || strpos($originalUrl, '{{') !== false ) {
                                return $matches[0]; 
                            }
                            if (strpos($originalUrl, '${') !== false || strpos($originalUrl, '+') !== false) {
                                return $matches[0];
                            }
                            $absoluteUrl = make_absolute($originalUrl, $finalUrl);
                            $proxiedJsUrl = addslashes($proxyUrlPrefix . urlencode($absoluteUrl)); // proxyUrlPrefix is raw here
                            return $matches[1] . $matches[2] . $proxiedJsUrl . $matches[4];
                        },
                        $script_content
                    );
                }
                return $script_matches[1] . $script_content . $script_matches[3];
            },
            $content
        );
        $content = preg_replace_callback(
            // Use raw quotes for JS import statements
            '/(import\s+(?:{[^>]+}\s+from\s+|[\w\s,*{}]*\s+from\s+|\s*)[\'"])([^"\':#][^\'"]*)([\'"])/i',
            function ($matches) use ($finalUrl, $proxyUrlPrefix) {
                $originalUrl = $matches[2];
                if (preg_match('/^(data:|([a-z][a-z0-9+-.]*):|\/\/|#)/i', $originalUrl) || strpos($originalUrl, $proxyUrlPrefix) === 0) {
                    return $matches[0];
                }
                $absoluteUrl = make_absolute($originalUrl, $finalUrl);
                $proxiedJsUrl = $proxyUrlPrefix . urlencode($absoluteUrl); // proxyUrlPrefix is raw
                return $matches[1] . $proxiedJsUrl . $matches[3];
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
        $errorMsg = "Failed to fetch content. Could not connect, URL may be invalid, or target server is not responding.";
    } else if (!empty($curlError)) { 
        $errorMsg = "cURL Error: " . htmlspecialchars($curlError, ENT_QUOTES, 'UTF-8');
    } else if ($statusCode === 403) {
        $errorMsg .= " Access Forbidden. Target site may block direct access or proxy attempts.";
    } else if ($statusCode === 404) {
        $errorMsg .= " Not Found. Resource not found on target server.";
    } else if ($statusCode >= 500) {
        $errorMsg .= " Target server encountered an internal error.";
    } else if ($statusCode >= 400 && $statusCode < 500) { 
        $errorMsg .= " Issue with the request to the target server.";
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