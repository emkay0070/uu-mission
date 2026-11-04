'use client';
import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { useForm } from 'react-hook-form';
import { Globe, Eye, EyeOff, AlertCircle } from 'lucide-react';
import { useAuthStore } from '@/store/auth';
import type { ApiError } from '@/lib/api';

type Fields = { email: string; password: string };

export default function LoginPage() {
  const router  = useRouter();
  const { login, loading } = useAuthStore();
  const [show, setShow]   = useState(false);
  const [error, setError] = useState('');

  const { register, handleSubmit, formState: { errors } } = useForm<Fields>();

  async function onSubmit(data: Fields) {
    setError('');
    try {
      await login(data.email, data.password);
      // Redirect to root - it will determine the appropriate route based on role
      router.replace('/');
    } catch (e) {
      const err = e as ApiError;
      setError(err.errors?.email?.[0] ?? err.message ?? 'Login failed');
    }
  }

  return (
    <div className="min-h-screen bg-surface flex flex-col items-center justify-center p-4">
      {/* Background glow */}
      <div className="absolute inset-0 overflow-hidden pointer-events-none">
        <div className="absolute -top-40 left-1/2 -translate-x-1/2 w-[600px] h-[600px] bg-brand-600/10 rounded-full blur-3xl" />
      </div>

      <div className="relative w-full max-w-sm animate-fade-up">
        {/* Logo */}
        <div className="flex flex-col items-center gap-3 mb-8">
          <div className="w-14 h-14 rounded-2xl bg-brand-600 flex items-center justify-center shadow-lg shadow-brand-600/30">
            <Globe className="w-7 h-7 text-white" />
          </div>
          <div className="text-center">
            <h1 className="text-2xl font-bold text-slate-100">UU Mission</h1>
            <p className="text-sm text-slate-400 mt-0.5">Operations Platform</p>
          </div>
        </div>

        {/* Card */}
        <div className="card p-7">
          <h2 className="text-lg font-semibold text-slate-100 mb-1">Sign in</h2>
          <p className="text-sm text-slate-400 mb-6">Enter your credentials to access your workspace.</p>

          {error && (
            <div className="flex items-start gap-2.5 rounded-lg border border-red-500/30 bg-red-500/10 px-3.5 py-3 mb-5">
              <AlertCircle className="w-4 h-4 text-red-400 shrink-0 mt-0.5" />
              <p className="text-sm text-red-400">{error}</p>
            </div>
          )}

          <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1.5">
                Email address
              </label>
              <input
                id="email"
                type="email"
                placeholder="your@email.com"
                autoComplete="email"
                className={`input ${errors.email ? 'input-error' : ''}`}
                {...register('email', { required: 'Email is required' })}
              />
              {errors.email && (
                <p className="text-xs text-red-400 mt-1">{errors.email.message}</p>
              )}
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-400 mb-1.5">
                Password
              </label>
              <div className="relative">
                <input
                  id="password"
                  type={show ? 'text' : 'password'}
                  placeholder="••••••••"
                  autoComplete="current-password"
                  className={`input pr-10 ${errors.password ? 'input-error' : ''}`}
                  {...register('password', { required: 'Password is required' })}
                />
                <button
                  type="button"
                  onClick={() => setShow(!show)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300 transition-colors"
                >
                  {show ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
              {errors.password && (
                <p className="text-xs text-red-400 mt-1">{errors.password.message}</p>
              )}
            </div>

            <button
              type="submit"
              disabled={loading}
              className="btn-primary w-full mt-2"
            >
              {loading ? (
                <span className="flex items-center gap-2">
                  <span className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                  Signing in…
                </span>
              ) : 'Sign in'}
            </button>
          </form>

          <div className="flex items-center justify-between mt-4">
            <button
              type="button"
              onClick={() => router.push('/signup')}
              className="text-xs text-brand-400 hover:text-brand-300 transition-colors"
            >
              Create an account
            </button>
            <button
              type="button"
              onClick={() => router.push('/forgot-password')}
              className="text-xs text-slate-500 hover:text-slate-400 transition-colors"
            >
              Forgot password?
            </button>
          </div>
        </div>

        <p className="text-xs text-slate-600 text-center mt-6">
          Uganda Union Conference · Mission Operations
        </p>
      </div>
    </div>
  );
}
