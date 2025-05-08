import { UrlInputSection } from '@/components/url-input-section';

export default function Home() {
  return (
    <main className="flex min-h-screen flex-col items-center p-4 md:p-12 bg-background">
      <div className="w-full max-w-3xl space-y-8">
        <header className="text-center">
          <h1 className="text-4xl md:text-5xl font-bold text-primary drop-shadow-sm">UnblockMe</h1>
          <p className="mt-2 text-lg text-muted-foreground">
            Enter the URL of a website to access it without restrictions.
          </p>
        </header>
        <UrlInputSection />
      </div>
      <footer className="mt-12 text-center text-sm text-muted-foreground">
        <p>&copy; {new Date().getFullYear()} UnblockMe. All rights reserved.</p>
        <p className="mt-1 text-xs">
          Please use this service responsibly and respect website terms of service.
        </p>
      </footer>
    </main>
  );
}
