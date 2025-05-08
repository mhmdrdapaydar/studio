'use client';

import { useState, useTransition } from 'react';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { fetchProxiedContent, type FetchProxiedContentResult } from '@/app/actions';
import { Loader2, Globe, AlertTriangle, CheckCircle } from 'lucide-react';
import { useToast } from '@/hooks/use-toast';

export function UrlInputSection() {
  const [url, setUrl] = useState<string>('');
  const [fetchedContent, setFetchedContent] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isPending, startTransition] = useTransition();
  const { toast } = useToast();

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!url.trim()) {
      setError('Please enter a URL.');
      toast({
        title: "Error",
        description: "URL field cannot be empty.",
        variant: "destructive",
      });
      return;
    }

    setError(null);
    setFetchedContent(null);

    const formData = new FormData();
    formData.append('url', url);

    startTransition(async () => {
      const result: FetchProxiedContentResult = await fetchProxiedContent(formData);
      if (result.success && result.content) {
        setFetchedContent(result.content);
        toast({
          title: "Success!",
          description: `Content from ${result.finalUrl} loaded.`,
          variant: "default",
          action: <CheckCircle className="text-green-500" />,
        });
      } else {
        setError(result.error || 'Failed to fetch content.');
        toast({
          title: "Error Fetching Content",
          description: result.error || `Failed to load ${result.finalUrl}. Status: ${result.statusCode || 'Unknown'}`,
          variant: "destructive",
          action: <AlertTriangle className="text-red-500" />,
        });
      }
    });
  };

  return (
    <div className="w-full">
      <Card className="shadow-lg bg-card">
        <CardHeader>
          <CardTitle className="text-2xl font-semibold text-center text-primary">
            <Globe className="inline-block mr-2 h-7 w-7" /> Access Website
          </CardTitle>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-6">
            <div className="space-y-2">
              <label htmlFor="url-input" className="text-sm font-medium text-foreground">
                Website URL
              </label>
              <Input
                id="url-input"
                type="text"
                value={url}
                onChange={(e) => setUrl(e.target.value)}
                placeholder="e.g., example.com or https://example.com"
                className="bg-background border-input focus:ring-primary"
                aria-label="Website URL input"
                disabled={isPending}
              />
            </div>
            <Button
              type="submit"
              className="w-full bg-primary hover:bg-primary/90 text-primary-foreground"
              disabled={isPending}
              aria-label="Fetch website content"
            >
              {isPending ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Loading...
                </>
              ) : (
                'Go'
              )}
            </Button>
          </form>

          {error && (
            <Alert variant="destructive" className="mt-6">
              <AlertTriangle className="h-4 w-4" />
              <AlertTitle>Error</AlertTitle>
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
        </CardContent>
      </Card>

      {fetchedContent && (
        <Card className="mt-8 shadow-lg bg-card">
          <CardHeader>
            <CardTitle className="text-xl font-semibold text-primary">
              View Content
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div
              className="prose prose-sm sm:prose lg:prose-lg xl:prose-xl max-w-none overflow-auto rounded-md border p-4 bg-background h-[60vh]"
              dangerouslySetInnerHTML={{ __html: fetchedContent }}
              style={{ fontFamily: 'sans-serif' }} // Ensure clean sans-serif for fetched content
              aria-live="polite"
            />
          </CardContent>
        </Card>
      )}
    </div>
  );
}
