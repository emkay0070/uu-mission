/**
 * Origin of the Laravel API that Next.js proxies `/api/*` requests to.
 *
 * Defaults to the local development backend so `npm run dev` works unchanged.
 * Set BACKEND_URL in the hosting environment when the API is reachable at a
 * different address (for example a separate service or an internal hostname).
 */
const BACKEND_ORIGIN = process.env.BACKEND_URL ?? 'http://localhost:8000';

/** @type {import('next').NextConfig} */
const nextConfig = {
  allowedDevOrigins: ['192.168.10.100'],
  async rewrites() {
    return [
      {
        source: '/api/:path*',
        destination: `${BACKEND_ORIGIN}/api/:path*`,
      },
    ];
  },
};

export default nextConfig;
