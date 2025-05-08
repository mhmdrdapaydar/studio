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
            const result = await response.json();

            if (result.success && result.content) {
                contentDisplay.innerHTML = result.content; // Security note: Displaying raw HTML.
                contentViewer.style.display = 'block';
                contentTitle.textContent = `Content Preview`;
                currentUrlDisplay.textContent = `Displaying: ${result.finalUrl || url}`;
                currentUrlDisplay.title = `Displaying: ${result.finalUrl || url}`;

                 // Scroll to content viewer
                contentViewer.scrollIntoView({ behavior: 'smooth' });

            } else {
                let errorText = result.error || 'An unexpected error occurred.';
                if (result.statusCode) {
                    errorText += ` (Status: ${result.statusCode})`;
                }
                if (result.finalUrl) {
                    errorText += ` Attempted URL: ${result.finalUrl}`;
                }
                showError(errorText);
            }
        } catch (e) {
            console.error('Fetch error:', e);
            showError('Failed to fetch content. Check console for details or ensure the proxy.php script is working.');
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
