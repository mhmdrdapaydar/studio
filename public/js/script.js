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

    let currentFetchedBaseUrl = ''; // Store the base URL of the currently displayed content

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
        // Do not hide contentViewer immediately, allow for smooth transition if content is already shown
        // contentViewer.style.display = 'none'; 
        fetchButton.disabled = true;
        fetchButton.textContent = 'Loading...';
        urlInput.value = urlToFetch; // Update input field with the URL being fetched

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
                currentFetchedBaseUrl = ''; // Reset base URL on error
                return; 
            }
            
            const result = await response.json();

            if (result.success && result.content !== undefined) { // Check for content specifically
                contentDisplay.innerHTML = result.content;
                contentViewer.style.display = 'block';
                contentTitle.textContent = `Content Preview`;
                
                currentFetchedBaseUrl = result.rawFinalUrl || urlToFetch; // Store the raw final URL
                const displayUrl = result.finalUrl || urlToFetch; // Use HTML-escaped version for display
                
                currentUrlDisplay.textContent = `Displaying: ${displayUrl}`;
                currentUrlDisplay.title = `Displaying: ${displayUrl}`;

                // Re-attach listeners for dynamic content
                attachDynamicContentListeners();
                
                // Scroll to top of content viewer or content display
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
                currentFetchedBaseUrl = result.rawFinalUrl || ''; // Store or reset base URL on error
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
            currentFetchedBaseUrl = ''; // Reset base URL on error
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

    function attachDynamicContentListeners() {
        // Clear previous listeners if any more robustly (though simple re-assignment is often fine)
        // For this app, directly re-assigning might be okay, but for more complex scenarios, consider event delegation on contentDisplay

        contentDisplay.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', handleLinkClick);
        });

        contentDisplay.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', handleFormSubmit);
        });
    }
    
    function resolveUrl(relativeUrl, base) {
        if (!base) { // if currentFetchedBaseUrl is empty, try to use the input field or current document
            base = urlInput.value.trim() || document.location.href;
        }
        try {
            return new URL(relativeUrl, base).href;
        } catch (e) {
            console.warn("Could not resolve URL:", relativeUrl, "with base:", base, "Error:", e);
            // Fallback for malformed relative URLs or bases
            if (relativeUrl.startsWith('//')) {
                return new URL(document.location.protocol + relativeUrl).href;
            }
            if (relativeUrl.startsWith('/')) {
                const baseObj = new URL(base);
                return baseObj.origin + relativeUrl;
            }
            // If it's a full URL already or other cases, return as is or with minimal change
            return relativeUrl;
        }
    }

    function handleLinkClick(event) {
        const link = event.currentTarget;
        let href = link.getAttribute('href');

        if (!href || href.trim() === '' || href.startsWith('javascript:')) {
            return; // Do nothing for empty or javascript links
        }

        event.preventDefault(); // Prevent default navigation

        if (href.startsWith('#')) {
            // Handle same-page anchor links
            const targetId = href.substring(1);
            const targetElement = contentDisplay.querySelector(`#${CSS.escape(targetId)}`);
            if (targetElement) {
                targetElement.scrollIntoView({ behavior: 'smooth' });
            } else {
                // Fallback: scroll to top of contentDisplay if specific fragment not found
                contentDisplay.scrollTop = 0; 
            }
            return;
        }

        const absoluteUrl = resolveUrl(href, currentFetchedBaseUrl);
        fetchAndDisplayUrl(absoluteUrl);
    }

    function handleFormSubmit(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const action = form.getAttribute('action') || '';
        const method = form.method ? form.method.toLowerCase() : 'get';

        let targetUrl = resolveUrl(action, currentFetchedBaseUrl);

        const formData = new FormData(form);
        const params = new URLSearchParams();
        for (const pair of formData) {
            params.append(pair[0], pair[1]);
        }
        
        if (method === 'post') {
            // Our current PHP proxy converts everything to GET. 
            // For POST, we'd need to send data in the body, which proxy.php isn't set up for.
            // For now, we'll append as query params (like a GET) and warn.
            showError("Warning: POST forms are submitted as GET through this proxy. Functionality may differ.", "warning");
        }

        if (params.toString()) {
            targetUrl += (targetUrl.includes('?') ? '&' : '?') + params.toString();
        }
        
        fetchAndDisplayUrl(targetUrl);
    }


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

    function showLoader() {
        loader.style.display = 'block';
    }

    function hideLoader() {
        loader.style.display = 'none';
    }

    function showError(message, type = "error") { // type can be "error" or "warning"
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
        errorMessageDiv.className = 'error-message'; // Reset class
    }
});
