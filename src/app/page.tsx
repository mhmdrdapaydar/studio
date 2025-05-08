import { UrlInputSection } from '@/components/url-input-section';
import { ExternalLink } from 'lucide-react';

export default function Home() {
  return (
    <main className="flex min-h-screen flex-col items-center justify-center p-6 sm:p-12 bg-gradient-to-br from-background to-secondary/30">
      <div className="w-full max-w-4xl space-y-10">
        <header className="text-center space-y-3">
          <div className="inline-flex items-center justify-center p-3 bg-primary/10 rounded-full mb-4">
            <ExternalLink className="h-10 w-10 text-primary" />
          </div>
          <h1 className="text-5xl sm:text-6xl font-extrabold text-primary drop-shadow-lg">
            UnblockMe
          </h1>
          <p className="mt-3 text-xl text-muted-foreground max-w-xl mx-auto">
            Seamlessly access and view content from any website. Enter a URL below to get started.
          </p>
        </header>
        
        <UrlInputSection />

      </div>
      <footer className="mt-16 py-6 text-center text-sm text-muted-foreground border-t border-border w-full max-w-4xl">
        <p>&copy; {new Date().getFullYear()} UnblockMe. All rights reserved.</p>
        <p className="mt-1 text-xs">
          This service is for accessing publicly available content. Please use responsibly and respect website terms of service.
        </p>
      </footer>
    </main>
  );
}
