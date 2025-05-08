document.addEventListener('DOMContentLoaded', () => {
    const urlForm = document.getElementById('url-form');
    const urlInput = document.getElementById('url-input');
    const fetchButton = document.getElementById('fetch-button');
    const loader = document.getElementById('loader');
    const errorMessageDiv = document.getElementById('error-message');
    const contentViewer = document.getElementById('content-viewer');
    const contentDisplay = document.getElementById('content-display');
    const contentTitle = document.getElementById('content-title');
    const currentUrlDisplay = document.getElementById('current-url-display');
    const fullscreenButton = document.getElementById('fullscreen-button');
    const currentYearSpan = document.getElementById('current-year');

    let currentFetchedBaseUrl = ''; 

    if (currentYearSpan) {
        currentYearSpan.textContent = new Date().getFullYear();
    }

    async function fetchAndDisplayUrl(urlToFetch) {
        if (!urlToFetch) {
            showError('URL to fetch cannot be empty.');
            return;
        }

        hideError();
        showLoader();
        fetchButton.disabled = true;
        fetchButton.textContent = 'Loading...';
        urlInput.value = urlToFetch;

        try {
            const response = await fetch(`proxy.php?url=${encodeURIComponent(urlToFetch)}`);

            if (!response.ok) {
                let proxyErrorText = `Error from proxy server: ${response.status} ${response.statusText}.`;
                try {
                    const errorBody = await response.text();
                    let detail = "";
                    try {
                        const jsonError = JSON.parse(errorBody);
                        detail = jsonError && jsonError.error ? jsonError.error : errorBody.substring(0, 200) + (errorBody.length > 200 ? '...' : '');
                    } catch (parseError) {
                         detail = errorBody.substring(0, 200) + (errorBody.length > 200 ? '...' : '');
                    }
                    if(detail) proxyErrorText += ` Details: ${detail}`;
                } catch (textError) {
                    proxyErrorText += ' Could not retrieve further details from proxy server.';
                }
                showError(proxyErrorText);
                console.error("Proxy response error object:", response);
                currentFetchedBaseUrl = ''; 
                return; 
            }
            
            const result = await response.json();

            if (result.success && result.content !== undefined) {
                contentDisplay.innerHTML = result.content;
                // Sanitize script tags to prevent execution of potentially harmful scripts from the proxy
                // This is a basic measure; a more robust solution would involve a proper HTML sanitizer library
                // or more sophisticated server-side processing.
                Array.from(contentDisplay.getElementsByTagName("script")).forEach(oldScript => {
                    const newScript = document.createElement("script");
                    Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                    // Only re-insert script text if it's deemed "safe" or necessary.
                    // For now, we are allowing scripts but they are re-evaluated by the browser.
                    // To prevent execution of *all* scripts from proxied content:
                    // oldScript.parentNode.removeChild(oldScript); // And don't append newScript
                    // However, this breaks most interactive sites.
                    if (oldScript.src) {
                        // If script has src, it's already handled by proxy.php's URL rewriting (if relative)
                        // or it's absolute. We just recreate the element.
                         newScript.appendChild(document.createTextNode(oldScript.innerHTML)); // keep inline content if any, though unusual for src scripts
                         oldScript.parentNode.replaceChild(newScript, oldScript);

                    } else if (oldScript.innerHTML) {
                        // For inline scripts, the server-side rewriting in proxy.php is primary.
                        // Re-creating the script tag helps ensure it's "fresh" in the DOM if needed.
                        newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                        oldScript.parentNode.replaceChild(newScript, oldScript);
                    }
                });


                contentViewer.style.display = 'block';
                contentTitle.textContent = `Content Preview`;
                
                currentFetchedBaseUrl = result.rawFinalUrl || urlToFetch; 
                const displayUrl = result.finalUrl || urlToFetch; 
                
                currentUrlDisplay.textContent = `Displaying: ${displayUrl}`;
                currentUrlDisplay.title = `Displaying: ${displayUrl}`;
                
                contentViewer.scrollIntoView({ behavior: 'smooth', block: 'start' });

            } else {
                let errorText = result.error || 'An unexpected error occurred from the proxy.';
                if (result.statusCode && result.statusCode !== response.status) { 
                    errorText += ` (Remote Status: ${result.statusCode})`;
                }
                if (result.finalUrl) {
                    errorText += ` Attempted URL: ${result.finalUrl}`;
                }
                showError(errorText);
                currentFetchedBaseUrl = result.rawFinalUrl || ''; 
            }
        } catch (e) {
            console.error('Fetch or JSON parsing error:', e);
            let friendlyMessage = 'Failed to fetch content. An issue occurred with the request or processing the response.';
            if (e instanceof TypeError && (e.message.includes('NetworkError') || e.message.includes('Failed to fetch'))) {
                friendlyMessage = 'Network error or proxy unreachable. Please check your connection and the proxy endpoint.';
            } else if (e.message.toLowerCase().includes('json')) {
                friendlyMessage = 'Error processing server response. The proxy might have returned an invalid format. Check browser console.';
            }
            showError(friendlyMessage);
            currentFetchedBaseUrl = ''; 
        } finally {
            hideLoader();
            fetchButton.disabled = false;
            fetchButton.textContent = 'Fetch & View';
        }
    }

    urlForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const url = urlInput.value.trim();
        fetchAndDisplayUrl(url);
    });
    
    function resolveUrl(relativeUrl, base) {
        if (!base) { 
            base = urlInput.value.trim() || document.location.href;
        }
        try {
            return new URL(relativeUrl, base).href;
        } catch (e) {
            console.warn("Could not resolve URL:", relativeUrl, "with base:", base, "Error:", e);
            if (relativeUrl.startsWith('//')) {
                return new URL(document.location.protocol + relativeUrl).href;
            }
            if (relativeUrl.startsWith('/')) {
                const baseObj = new URL(base);
                return baseObj.origin + relativeUrl;
            }
            return relativeUrl;
        }
    }

    contentDisplay.addEventListener('click', function(event) {
        const link = event.target.closest('a');
        if (link) {
            let href = link.getAttribute('href');

            if (!href || href.trim() === '') {
                event.preventDefault(); // Prevent action for empty hrefs
                return;
            }
            if (href.startsWith('javascript:')) {
                console.warn('Javascript link clicked, execution prevented by proxy viewer.', link);
                event.preventDefault(); // Prevent execution of javascript: links
                return; 
            }

            // Allow default behavior for links with target="_blank" or if it's an external link not to be proxied
            // For this proxy, we want to proxy most things. If it has target="_blank", the proxy can't easily control new tabs.
            // So we navigate all links within the viewer for simplicity.
            event.preventDefault(); 

            if (href.startsWith('#')) {
                const targetId = href.substring(1);
                let targetElement = null;
                try {
                    // CSS.escape is important for IDs that might contain special characters
                    targetElement = contentDisplay.querySelector(`#${CSS.escape(targetId)}`);
                } catch(e) {
                    console.warn("Error finding fragment:", e);
                    // Try a simpler query if CSS.escape failed or wasn't needed
                    try { targetElement = contentDisplay.querySelector(href); } catch (e2) {}
                }
                if (targetElement) {
                    targetElement.scrollIntoView({ behavior: 'smooth' });
                } else {
                    contentDisplay.scrollTop = 0; 
                }
                return;
            }

            const absoluteUrl = resolveUrl(href, currentFetchedBaseUrl);
            fetchAndDisplayUrl(absoluteUrl);
        }
    });

    contentDisplay.addEventListener('submit', function(event) {
        const form = event.target.closest('form');
        if (form) {
            event.preventDefault();
            const action = form.getAttribute('action') || ''; // Default to empty string if no action
            const method = form.method ? form.method.toLowerCase() : 'get';

            // Resolve action URL against the base URL of the fetched content
            let targetUrl = resolveUrl(action, currentFetchedBaseUrl);

            const formData = new FormData(form);
            const params = new URLSearchParams();
            for (const pair of formData) {
                params.append(pair[0], pair[1]);
            }
            
            if (method === 'post') {
                // The current proxy.php converts all requests to GET.
                // If proxy.php were to support POST, we'd need to send data differently.
                showError("Warning: POST forms are submitted as GET through this proxy. Functionality may differ.", "warning");
            }

            if (params.toString()) {
                targetUrl += (targetUrl.includes('?') ? '&' : '?') + params.toString();
            }
            
            fetchAndDisplayUrl(targetUrl);
        }
    });


    fullscreenButton.addEventListener('click', () => {
        if (!document.fullscreenElement) {
            if (contentDisplay.requestFullscreen) {
                contentDisplay.requestFullscreen().catch(err => {
                    alert(`Error attempting to enable full-screen mode: ${err.message} (${err.name})`);
                });
            } else if (contentDisplay.webkitRequestFullscreen) { /* Safari */
                contentDisplay.webkitRequestFullscreen();
            } else if (contentDisplay.msRequestFullscreen) { /* IE11 */
                contentDisplay.msRequestFullscreen();
            }
        } else {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            }
        }
    });
    
    document.addEventListener('fullscreenchange', () => {
        if (document.fullscreenElement === contentDisplay) {
            fullscreenButton.textContent = 'Exit Fullscreen';
        } else {
            fullscreenButton.textContent = 'Fullscreen';
        }
    });
    // Safari specific fullscreen change event
    document.addEventListener('webkitfullscreenchange', () => {
         if (document.webkitFullscreenElement === contentDisplay) {
            fullscreenButton.textContent = 'Exit Fullscreen';
        } else {
            fullscreenButton.textContent = 'Fullscreen';
        }
    });


    function showLoader() {
        loader.style.display = 'block';
    }

    function hideLoader() {
        loader.style.display = 'none';
    }

    function showError(message, type = "error") { 
        errorMessageDiv.textContent = message;
        errorMessageDiv.style.display = 'block';
        errorMessageDiv.className = `error-message ${type === 'warning' ? 'warning-message' : ''}`;
        if (type === "error") {
             console.error("Proxy Client Error:", message);
        } else if (type === "warning") {
             console.warn("Proxy Client Warning:", message);
        }
    }

    function hideError() {
        errorMessageDiv.style.display = 'none';
        errorMessageDiv.className = 'error-message'; 
    }
});
