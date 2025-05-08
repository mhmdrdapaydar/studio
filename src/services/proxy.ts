/**
 * Represents the result of fetching content from a URL.
 */
export interface FetchResult {
  /**
   * The content fetched from the URL, as a string.
   */
  content: string;
  /**
   * The HTTP status code of the response.
   */
  statusCode: number;
  /**
   * The final URL after any redirects.
   */
  finalUrl: string;
}

/**
 * Asynchronously fetches content from a given URL.
 *
 * @param url The URL to fetch.
 * @returns A promise that resolves to a FetchResult object containing the content and status code.
 */
export async function fetchContent(url: string): Promise<FetchResult> {
  try {
    // IMPORTANT: This fetch is made from the Next.js server, not the client.
    // However, many sites have protections against being proxied/scraped.
    // A dedicated proxy server (e.g., PHP, Node.js) with more advanced capabilities
    // (like rotating IPs, handling headers carefully) would be more effective for real-world use.
    const response = await fetch(url, {
      headers: {
        // Mimic a browser to improve chances of success
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language': 'en-US,en;q=0.9',
      },
      redirect: 'follow', // Follow redirects
    });

    const content = await response.text();
    
    // Some basic replacements to attempt to make relative links work.
    // This is very naive and will not work for all cases.
    // A more robust solution would involve parsing the HTML and rewriting URLs.
    const baseHref = `<base href="${response.url}" target="_blank">`;
    let modifiedContent = content;

    // Inject base tag if not present or update if present
    if (modifiedContent.includes("<head>")) {
      if (modifiedContent.match(/<base\s[^>]*>/i)) {
        modifiedContent = modifiedContent.replace(/<base\s[^>]*>/i, baseHref);
      } else {
        modifiedContent = modifiedContent.replace("<head>", `<head>${baseHref}`);
      }
    } else {
      // If no head tag, prepend to content (less ideal)
      modifiedContent = baseHref + modifiedContent;
    }
    
    // A very simple attempt to make src/href attributes absolute.
    // This is highly error-prone and a proper HTML parser should be used.
    // For example, this doesn't handle single quotes or attributes without quotes.
    modifiedContent = modifiedContent.replace(/(src|href)="\/(?!\/)/gi, `$1="${response.url}/`);


    return {
      content: modifiedContent,
      statusCode: response.status,
      finalUrl: response.url, // URL after redirects
    };
  } catch (error) {
    console.error(`Proxy service error fetching ${url}:`, error);
    if (error instanceof Error && error.message.includes('Invalid URL')) {
       return { content: `<p>Error: The provided URL "${url}" is invalid.</p>`, statusCode: 400, finalUrl: url };
    }
    return {
      content: `<p>Error fetching content from ${url}. The site might be down or blocking requests.</p><p>Details: ${error instanceof Error ? error.message : String(error)}</p>`,
      statusCode: 500, // Internal server error or network issue
      finalUrl: url,
    };
  }
}
