'use client';

import { useState, useEffect, Suspense } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import Link from 'next/link';
import { Globe, CheckCircle, AlertCircle, Loader2 } from 'lucide-react';

type VerificationStatus = 'loading' | 'success' | 'error';

function VerifyEmailContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [status, setStatus] = useState<VerificationStatus>('loading');
  const [error, setError] = useState<string>('');

  useEffect(() => {
    const token = searchParams.get('token');

    if (!token) {
      setStatus('error');
      setError('No verification token provided. Please check your email link or request a new verification email.');
      return;
    }

    verifyEmail(token);
  }, [searchParams]);

  const verifyEmail = async (token: string) => {
    try {
      const response = await fetch(`http://localhost:8000/api/v1/auth/verify-email?token=${token}`, {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
      });

      const result = await response.json();

      if (!response.ok) {
        setStatus('error');
        setError(result.message || result.errors?.token?.[0] || 'Verification failed. Please try again.');
        return;
      }

      setStatus('success');
    } catch (err) {
      setStatus('error');
      setError('Unable to connect to the server. Please check your internet connection and try again.');
    }
  };

  return (
    <div className="min-h-screen bg-surface flex flex-col items-center justify-center p-8">
      {/* Background glow */}
      <div className="absolute inset-0 overflow-hidden pointer-events-none">
        <div className="absolute -top-40 left-1/2 -translate-x-1/2 w-[600px] h-[600px] bg-brand-600/10 rounded-full blur-3xl" />
      </div>

      <div className="relative w-full max-w-md animate-fade-up">
        {/* Logo */}
        <div className="flex flex-col items-center gap-4 mb-10">
          <div className="w-16 h-16 rounded-2xl bg-brand-600 flex items-center justify-center shadow-lg shadow-brand-600/30">
            <Globe className="w-8 h-8 text-white" />
          </div>
          <div className="text-center">
            <h1 className="text-3xl font-bold text-slate-100 mb-2">Email Verification</h1>
            <p className="text-slate-400">UU Mission Platform</p>
          </div>
        </div>

        {/* Card */}
        <div className="card p-12 text-center">
          {status === 'loading' && (
            <div className="flex flex-col items-center gap-6">
              <div className="w-20 h-20 bg-brand-500/20 rounded-full flex items-center justify-center">
                <Loader2 className="w-10 h-10 text-brand-400 animate-spin" />
              </div>
              <div>
                <h2 className="text-xl font-semibold text-slate-100 mb-2">Verifying your email...</h2>
                <p className="text-sm text-slate-400">Please wait while we confirm your account.</p>
              </div>
            </div>
          )}

          {status === 'success' && (
            <div className="flex flex-col items-center gap-6">
              <div className="w-20 h-20 bg-green-500/20 rounded-full flex items-center justify-center">
                <CheckCircle className="w-10 h-10 text-green-400" />
              </div>
              <div>
                <h2 className="text-xl font-semibold text-slate-100 mb-2">Email Verified Successfully</h2>
                <p className="text-sm text-slate-400 mb-8">
                  Your account has been activated. You can now sign in to access your workspace.
                </p>
              </div>
              <Link href="/login" className="btn-primary w-full">
                Continue to Login
              </Link>
            </div>
          )}

          {status === 'error' && (
            <div className="flex flex-col items-center gap-6">
              <div className="w-20 h-20 bg-red-500/20 rounded-full flex items-center justify-center">
                <AlertCircle className="w-10 h-10 text-red-400" />
              </div>
              <div>
                <h2 className="text-xl font-semibold text-slate-100 mb-2">Verification Failed</h2>
                <p className="text-sm text-slate-400 mb-6 leading-relaxed">{error}</p>
              </div>
              <div className="flex flex-col gap-3 w-full">
                <Link href="/login" className="btn-primary w-full">
                  Go to Login
                </Link>
                <Link href="/signup" className="btn-secondary w-full">
                  Create New Account
                </Link>
              </div>
            </div>
          )}
        </div>

        <p className="text-xs text-slate-600 text-center mt-6">
          Uganda Union Conference · Mission Operations
        </p>
      </div>
    </div>
  );
}

export default function VerifyEmailPage() {
  return (
    <Suspense fallback={
      <div className="min-h-screen bg-surface flex flex-col items-center justify-center p-8">
        <div className="w-20 h-20 bg-brand-500/20 rounded-full flex items-center justify-center">
          <Loader2 className="w-10 h-10 text-brand-400 animate-spin" />
        </div>
      </div>
    }>
      <VerifyEmailContent />
    </Suspense>
  );
}
