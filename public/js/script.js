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

    if (currentYearSpan) {
        currentYearSpan.textContent = new Date().getFullYear();
    }

    urlForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const url = urlInput.value.trim();

        if (!url) {
            showError('Please enter a URL.');
            return;
        }

        hideError();
        showLoader();
        contentViewer.style.display = 'none';
        fetchButton.disabled = true;
        fetchButton.textContent = 'Loading...';

        try {
            const response = await fetch(`proxy.php?url=${encodeURIComponent(url)}`);

            if (!response.ok) {
                // The proxy.php script itself might have had an error (e.g., PHP error, server misconfiguration)
                let proxyErrorText = `Error from proxy server: ${response.status} ${response.statusText}.`;
                try {
                    const errorBody = await response.text();
                    // Try to parse as JSON first, as our proxy sends JSON errors
                    let detail = "";
                    try {
                        const jsonError = JSON.parse(errorBody);
                        if (jsonError && jsonError.error) {
                            detail = jsonError.error;
                        } else {
                             detail = errorBody.substring(0, 200) + (errorBody.length > 200 ? '...' : '');
                        }
                    } catch (parseError) {
                         // If not JSON, use a snippet of the text body
                         detail = errorBody.substring(0, 200) + (errorBody.length > 200 ? '...' : '');
                    }
                    if(detail) proxyErrorText += ` Details: ${detail}`;

                } catch (textError) {
                    // Failed to get text body, stick with status
                    proxyErrorText += ' Could not retrieve further details from proxy server.';
                }
                showError(proxyErrorText);
                console.error("Proxy response error object:", response);
                return; 
            }

            // If response.ok is true, proxy.php should have sent valid JSON
            const result = await response.json();

            if (result.success && result.content) {
                contentDisplay.innerHTML = result.content; 
                contentViewer.style.display = 'block';
                contentTitle.textContent = `Content Preview`;
                currentUrlDisplay.textContent = `Displaying: ${result.finalUrl || url}`;
                currentUrlDisplay.title = `Displaying: ${result.finalUrl || url}`;
                contentViewer.scrollIntoView({ behavior: 'smooth' });
            } else {
                let errorText = result.error || 'An unexpected error occurred from the proxy.';
                // result.statusCode is the status from the target URL, response.status is from proxy.php itself
                if (result.statusCode && result.statusCode !== response.status) { 
                    errorText += ` (Remote Status: ${result.statusCode})`;
                }
                if (result.finalUrl) {
                    errorText += ` Attempted URL: ${result.finalUrl}`;
                }
                showError(errorText);
            }
        } catch (e) {
            console.error('Fetch or JSON parsing error:', e);
            let friendlyMessage = 'Failed to fetch content. An issue occurred with the request or processing the response.';
            if (e instanceof TypeError && e.message.includes('NetworkError')) { // Firefox
                friendlyMessage = 'Network error. Please check your connection or if the server/proxy is reachable.';
            } else if (e instanceof TypeError && e.message.includes('Failed to fetch')) { // Chrome
                friendlyMessage = 'Network error or proxy unreachable. Please check your connection and the proxy endpoint.';
            } else if (e.message.toLowerCase().includes('json')) {
                friendlyMessage = 'Error processing server response. The proxy might have returned an invalid format (e.g. HTML error page instead of JSON). Check browser console for details.';
            }
            showError(friendlyMessage);
        } finally {
            hideLoader();
            fetchButton.disabled = false;
            fetchButton.textContent = 'Fetch & View';
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

    function showLoader() {
        loader.style.display = 'block';
    }

    function hideLoader() {
        loader.style.display = 'none';
    }

    function showError(message) {
        errorMessageDiv.textContent = message;
        errorMessageDiv.style.display = 'block';
    }

    function hideError() {
        errorMessageDiv.style.display = 'none';
    }
});
