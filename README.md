# UnblockMe - PHP/HTML Version

This is a simple PHP and HTML application to fetch and display content from a given URL.

## How to Run

1.  Ensure you have a web server (like Apache or Nginx) with PHP installed and configured.
2.  Place the files (`index.html`, `proxy.php`, and the `public` directory) in your web server's document root or a subdirectory.
3.  Open `index.html` in your browser through your web server (e.g., `http://localhost/index.html` or `http://localhost/your-subdirectory/index.html`).

## Files

-   `index.html`: The main HTML page with the URL input form and content display area.
-   `proxy.php`: The PHP script that fetches content from the specified URL.
-   `public/css/style.css`: Basic CSS for styling the application.
-   `public/js/script.js`: JavaScript for handling form submission, fetching content via `proxy.php`, and managing fullscreen display.

## Functionality

-   Enter a URL in the input field.
-   Click "Fetch & View" to load the content from the URL.
-   The fetched content will be displayed below the form.
-   A "Fullscreen" button allows viewing the fetched content in fullscreen mode.
