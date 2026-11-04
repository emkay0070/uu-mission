import type { Metadata, Viewport } from 'next';
import './globals.css';
import { ServiceWorkerRegistration } from '@/components/ServiceWorkerRegistration';
import { NetworkStatus } from '@/components/NetworkStatus';

export const metadata: Metadata = {
  title: { default: 'UU Mission', template: '%s — UU Mission' },
  description: 'Uganda Union Mission Operations Platform',
  manifest: '/manifest.json',
  appleWebApp: {
    capable: true,
    statusBarStyle: 'black-translucent',
    title: 'UU Mission',
  },
};

export const viewport: Viewport = {
  themeColor: '#0f172a',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" className="dark">
      <head>
        <link rel="icon" href="/icon-192.svg" type="image/svg+xml" />
        <link rel="apple-touch-icon" href="/icon-192.svg" />
      </head>
      <body>
        <ServiceWorkerRegistration />
        <NetworkStatus />
        {children}
      </body>
    </html>
  );
}
