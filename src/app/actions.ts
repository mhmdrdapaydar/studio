'use server';

import { z } from 'zod';
import { fetchContent as fetchContentFromProxy } from '@/services/proxy';

// Schema for URL validation, allowing for URLs without explicit schemes.
const UrlInputSchema = z.string()
  .min(1, { message: "URL cannot be empty." })
  .transform(val => {
    if (!val.startsWith('http://') && !val.startsWith('https://')) {
      return `https://${val}`;
    }
    return val;
  })
  .pipe(z.string().url({ message: "Invalid URL format. Ensure it's a valid web address (e.g., example.com or https://example.com)." }));


export interface FetchProxiedContentResult {
  success: boolean;
  content?: string;
  statusCode?: number;
  error?: string;
  finalUrl?: string;
}

export async function fetchProxiedContent(formData: FormData): Promise<FetchProxiedContentResult> {
  const urlInputValue = formData.get('url');

  if (typeof urlInputValue !== 'string') {
    return { success: false, error: "URL must be a string." };
  }

  const validationResult = UrlInputSchema.safeParse(urlInputValue);
  if (!validationResult.success) {
    return { success: false, error: validationResult.error.errors.map(e => e.message).join(', ') };
  }

  const validatedUrl = validationResult.data;

  try {
    const result = await fetchContentFromProxy(validatedUrl);
    if (result.statusCode >= 200 && result.statusCode < 400) { // Allow redirects
      return { success: true, content: result.content, statusCode: result.statusCode, finalUrl: validatedUrl };
    } else {
      return { 
        success: false, 
        error: `Failed to fetch content. The server responded with status: ${result.statusCode}. This could be due to the site blocking proxy attempts or an invalid URL.`, 
        statusCode: result.statusCode,
        finalUrl: validatedUrl
      };
    }
  } catch (e) {
    console.error("Error fetching proxied content:", e);
    const errorMessage = e instanceof Error ? e.message : "An unknown error occurred while fetching the content.";
    return { success: false, error: `An error occurred: ${errorMessage}`, finalUrl: validatedUrl };
  }
}
