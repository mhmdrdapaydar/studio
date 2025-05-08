import type { Metadata } from 'next';
import { Inter } from 'next/font/google'; // Changed from Geist to Inter
import './globals.css';
import { Toaster } from "@/components/ui/toaster";

const inter = Inter({ // Changed from geistSans to inter
  variable: '--font-sans', // Changed variable name for consistency
  subsets: ['latin'],
});

// Removed geistMono as Inter can cover mono-like needs or a specific mono font can be added if needed

export const metadata: Metadata = {
  title: 'UnblockMe',
  description: 'Access filtered websites seamlessly.',
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en">
      <body className={`${inter.variable} font-sans antialiased`}> {/* Apply Inter font class */}
        {children}
        <Toaster />
      </body>
    </html>
  );
}
