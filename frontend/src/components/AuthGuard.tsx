'use client';
import { useEffect } from 'react';
import { useRouter, usePathname } from 'next/navigation';
import { useAuthStore } from '@/store/auth';

export function AuthGuard({ children }: { children: React.ReactNode }) {
  const { user, initialized, initialize } = useAuthStore();
  const router = useRouter();
  const pathname = usePathname();

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { initialize(); }, []);

  useEffect(() => {
    if (initialized && !user) {
      // Only redirect to login if not already on login/signup pages
      if (pathname !== '/login' && pathname !== '/signup' && pathname !== '/verify-email') {
        router.replace('/login');
      }
    }
    
    // Role-based routing
    if (initialized && user) {
      const isAdmin = user.primary_role === 'admin';
      const isAdminRoute = pathname.startsWith('/admin');
      const isPublicRoute = ['/login', '/signup', '/verify-email', '/forgot-password', '/reset-password'].includes(pathname);
      
      // Admin trying to access missionary routes (excluding public routes)
      if (isAdmin && !isAdminRoute && !isPublicRoute) {
        router.replace('/admin/campaigns');
      }
      
      // Non-admin (including null primary_role) trying to access admin routes
      if (!isAdmin && isAdminRoute) {
        router.replace('/dashboard');
      }
    }
  }, [initialized, user, router, pathname]);

  if (!initialized) {
    return (
      <div className="min-h-screen bg-surface flex items-center justify-center">
        <div className="flex flex-col items-center gap-4">
          <div className="w-10 h-10 border-2 border-brand-500 border-t-transparent rounded-full animate-spin" />
          <p className="text-slate-400 text-sm">Loading workspace…</p>
        </div>
      </div>
    );
  }

  if (!user) return null;
  return <>{children}</>;
}
