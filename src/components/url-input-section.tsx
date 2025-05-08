
'use client';

import { useState, useTransition, useRef, useEffect } from 'react';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { fetchProxiedContent, type FetchProxiedContentResult } from '@/app/actions';
import { Loader2, Globe, AlertTriangle, CheckCircle, Maximize, Minimize, ExternalLink } from 'lucide-react';
import { useToast } from '@/hooks/use-toast';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

export function UrlInputSection() {
  const [url, setUrl] = useState<string>('');
  const [fetchedContent, setFetchedContent] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isPending, startTransition] = useTransition();
  const { toast } = useToast();
  const contentRef = useRef<HTMLDivElement>(null);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [currentFinalUrl, setCurrentFinalUrl] = useState<string | null>(null);


  const handleFullscreenToggle = () => {
    if (!contentRef.current) return;

    if (!document.fullscreenElement) {
      contentRef.current.requestFullscreen().catch(err => {
        toast({
          title: "Fullscreen Error",
          description: `Could not enter fullscreen mode: ${err.message}`,
          variant: "destructive",
        });
      });
    } else {
      if (document.exitFullscreen) {
        document.exitFullscreen();
      }
    }
  };

  useEffect(() => {
    const fullscreenChangeHandler = () => {
      setIsFullscreen(!!document.fullscreenElement);
    };
    document.addEventListener('fullscreenchange', fullscreenChangeHandler);
    return () => document.removeEventListener('fullscreenchange', fullscreenChangeHandler);
  }, []);

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!url.trim()) {
      setError('Please enter a URL.');
      toast({
        title: "Input Error",
        description: "URL field cannot be empty. Please enter a valid web address.",
        variant: "destructive",
        action: <AlertTriangle className="text-destructive-foreground" />,
      });
      return;
    }

    setError(null);
    setFetchedContent(null);
    setCurrentFinalUrl(null);

    const formData = new FormData();
    formData.append('url', url);

    startTransition(async () => {
      const result: FetchProxiedContentResult = await fetchProxiedContent(formData);
      if (result.success && result.content) {
        setFetchedContent(result.content);
        setCurrentFinalUrl(result.finalUrl || url);
        toast({
          title: "Content Loaded!",
          description: `Successfully fetched content from ${result.finalUrl || url}.`,
          variant: "default",
          action: <CheckCircle className="text-green-500" />,
        });
      } else {
        setError(result.error || 'An unexpected error occurred while fetching the content.');
        setCurrentFinalUrl(result.finalUrl || url);
        toast({
          title: "Fetch Error",
          description: result.error || `Failed to load content from ${result.finalUrl || url}. Status: ${result.statusCode || 'Unknown'}.`,
          variant: "destructive",
          action: <AlertTriangle className="text-destructive-foreground" />,
        });
      }
    });
  };

  return (
    <TooltipProvider>
      <div className="w-full space-y-8">
        <Card className="shadow-xl border-border/80 bg-card/80 backdrop-blur-sm rounded-xl">
          <CardHeader className="pb-4">
            <div className="flex items-center space-x-3">
              <Globe className="h-8 w-8 text-primary" />
              <div>
                <CardTitle className="text-2xl font-bold text-foreground">
                  Access Any Website
                </CardTitle>
                <CardDescription className="text-muted-foreground">
                  Enter a URL to view its content directly here.
                </CardDescription>
              </div>
            </div>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleSubmit} className="space-y-6">
              <div className="relative">
                <Input
                  id="url-input"
                  type="text"
                  value={url}
                  onChange={(e) => setUrl(e.target.value)}
                  placeholder="e.g., example.com or https://example.com"
                  className="bg-background border-input focus:ring-primary focus:border-primary h-12 pl-10 text-base rounded-lg shadow-inner"
                  aria-label="Website URL input"
                  disabled={isPending}
                />
                <ExternalLink className="absolute left-3 top-1/2 -translate-y-1/2 h-5 w-5 text-muted-foreground" />
              </div>
              <Button
                type="submit"
                className="w-full bg-primary hover:bg-primary/90 text-primary-foreground h-12 text-base font-semibold rounded-lg shadow-md transition-all duration-150 ease-in-out hover:shadow-lg active:scale-[0.98]"
                disabled={isPending}
                aria-label="Fetch website content"
              >
                {isPending ? (
                  <>
                    <Loader2 className="mr-2 h-5 w-5 animate-spin" />
                    Loading Content...
                  </>
                ) : (
                  'Fetch & View'
                )}
              </Button>
            </form>

            {error && !isPending && (
              <Alert variant="destructive" className="mt-6 p-4 rounded-lg">
                <AlertTriangle className="h-5 w-5" />
                <AlertTitle className="font-semibold">Access Denied or Error</AlertTitle>
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            )}
          </CardContent>
        </Card>

        {isPending && !fetchedContent && (
          <Card className="mt-8 shadow-subtle bg-card/80 backdrop-blur-sm rounded-xl animate-pulse">
            <CardHeader>
              <CardTitle className="text-xl font-semibold text-primary/80">Loading Preview...</CardTitle>
            </CardHeader>
            <CardContent className="h-[60vh] flex items-center justify-center">
                <Loader2 className="h-12 w-12 text-primary animate-spin" />
            </CardContent>
          </Card>
        )}

        {fetchedContent && (
          <Card className="mt-8 shadow-xl border-border/80 bg-card/80 backdrop-blur-sm rounded-xl overflow-hidden">
            <CardHeader className="flex flex-row items-center justify-between bg-muted/30 p-4 border-b">
              <div>
                <CardTitle className="text-xl font-semibold text-primary">
                  Content Preview
                </CardTitle>
                {currentFinalUrl && (
                   <CardDescription className="text-xs text-muted-foreground truncate max-w-xs sm:max-w-sm md:max-w-md">
                     Displaying content from: {currentFinalUrl}
                   </CardDescription>
                )}
              </div>
              <Tooltip>
                <TooltipTrigger asChild>
                  <Button
                    variant="ghost"
                    size="icon"
                    onClick={handleFullscreenToggle}
                    className="text-muted-foreground hover:text-primary rounded-full"
                    aria-label={isFullscreen ? "Exit fullscreen" : "Enter fullscreen"}
                  >
                    {isFullscreen ? <Minimize className="h-5 w-5" /> : <Maximize className="h-5 w-5" />}
                  </Button>
                </TooltipTrigger>
                <TooltipContent>
                  <p>{isFullscreen ? "Exit Fullscreen" : "Enter Fullscreen"}</p>
                </TooltipContent>
              </Tooltip>
            </CardHeader>
            <CardContent className="p-0">
              <div
                ref={contentRef}
                className="prose prose-sm sm:prose lg:prose-lg xl:prose-xl max-w-none overflow-auto bg-background h-[70vh] p-4 md:p-6"
                dangerouslySetInnerHTML={{ __html: fetchedContent }}
                style={{ fontFamily: 'sans-serif' }} 
                aria-live="polite"
              />
            </CardContent>
            <CardFooter className="p-4 border-t bg-muted/30">
                 <p className="text-xs text-muted-foreground">
                    Content is displayed as fetched. Some styles or scripts might not render correctly.
                 </p>
            </CardFooter>
          </Card>
        )}
      </div>
    </TooltipProvider>
  );
}
