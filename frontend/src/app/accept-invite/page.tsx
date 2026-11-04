'use client';

import { useState, useEffect, Suspense } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useForm } from 'react-hook-form';
import { Mail, XCircle, AlertCircle, Eye, EyeOff, User, Briefcase } from 'lucide-react';

function AcceptInviteContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const token = searchParams.get('token');

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [validToken, setValidToken] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);
  const [bio, setBio] = useState('');
  const [trainingNotes, setTrainingNotes] = useState('');

  const {
    register,
    handleSubmit,
    watch,
    formState: { errors },
  } = useForm({
    defaultValues: {
      password: '',
      password_confirmation: '',
      first_name: '',
      last_name: '',
    },
  });

  const password = watch('password');

  useEffect(() => {
    if (!token) {
      setError('Invalid invitation link. No token provided.');
    } else {
      setValidToken(true);
    }
  }, [token]);

  const onSubmit = async (data: any) => {
    if (!token) return;

    setLoading(true);
    setError(null);

    try {
      const response = await fetch(`http://localhost:8000/api/v1/invitations/${token}/accept`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          ...data,
          bio,
          training_notes: trainingNotes,
        }),
      });

      const result = await response.json();

      if (!response.ok) {
        throw new Error(result.message || 'Failed to accept invitation');
      }

      localStorage.setItem('uu_token', result.token);
      router.push('/missionary');
    } catch (err: any) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  if (!validToken) {
    return (
      <div className="min-h-screen bg-surface flex flex-col items-center justify-center p-8">
        <div className="absolute inset-0 overflow-hidden pointer-events-none">
          <div className="absolute -top-40 left-1/2 -translate-x-1/2 w-[600px] h-[600px] bg-brand-600/10 rounded-full blur-3xl" />
        </div>

        <div className="relative w-full max-w-md animate-fade-up">
          <div className="card p-12 text-center">
            <div className="w-20 h-20 bg-red-500/20 rounded-full flex items-center justify-center mx-auto mb-8">
              <XCircle className="w-10 h-10 text-red-400" />
            </div>
            <h2 className="text-2xl font-semibold text-slate-100 mb-3">Invalid Invitation</h2>
            <p className="text-slate-400 mb-8 leading-relaxed">
              {error || 'This invitation link is invalid or has expired.'}
            </p>
            <button
              onClick={() => router.push('/login')}
              className="btn-primary w-full"
            >
              Go to Login
            </button>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-surface flex flex-col items-center justify-center p-8">
      <div className="absolute inset-0 overflow-hidden pointer-events-none">
        <div className="absolute -top-40 left-1/2 -translate-x-1/2 w-[600px] h-[600px] bg-brand-600/10 rounded-full blur-3xl" />
      </div>

      <div className="relative w-full max-w-md animate-fade-up">
        <div className="flex flex-col items-center gap-4 mb-10">
          <div className="w-16 h-16 rounded-2xl bg-brand-600 flex items-center justify-center shadow-lg shadow-brand-600/30">
            <Mail className="w-8 h-8 text-white" />
          </div>
          <div className="text-center">
            <h1 className="text-3xl font-bold text-slate-100 mb-2">Accept Invitation</h1>
            <p className="text-slate-400">Create your account to join UU Mission</p>
          </div>
        </div>

        <div className="card p-8">
          {error && (
            <div className="flex items-start gap-3 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 mb-6">
              <AlertCircle className="w-5 h-5 text-red-400 shrink-0 mt-0.5" />
              <p className="text-sm text-red-400">{error}</p>
            </div>
          )}

          <form onSubmit={handleSubmit(onSubmit)} className="space-y-5">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-medium text-slate-400 mb-2">
                  First Name
                </label>
                <input
                  type="text"
                  {...register('first_name', { required: 'First name is required' })}
                  className={`input ${errors.first_name ? 'input-error' : ''}`}
                  placeholder="John"
                />
                {errors.first_name && (
                  <p className="text-xs text-red-400 mt-1.5">{errors.first_name.message as string}</p>
                )}
              </div>
              <div>
                <label className="block text-xs font-medium text-slate-400 mb-2">
                  Last Name
                </label>
                <input
                  type="text"
                  {...register('last_name', { required: 'Last name is required' })}
                  className={`input ${errors.last_name ? 'input-error' : ''}`}
                  placeholder="Doe"
                />
                {errors.last_name && (
                  <p className="text-xs text-red-400 mt-1.5">{errors.last_name.message as string}</p>
                )}
              </div>
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-400 mb-2">
                Password
              </label>
              <div className="relative">
                <input
                  type={showPassword ? 'text' : 'password'}
                  {...register('password', {
                    required: 'Password is required',
                    minLength: { value: 8, message: 'Password must be at least 8 characters' },
                  })}
                  className={`input pr-10 ${errors.password ? 'input-error' : ''}`}
                  placeholder="••••••••"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300 transition-colors"
                >
                  {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
              {errors.password && (
                <p className="text-xs text-red-400 mt-1.5">{errors.password.message as string}</p>
              )}
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-400 mb-2">
                Confirm Password
              </label>
              <div className="relative">
                <input
                  type={showConfirmPassword ? 'text' : 'password'}
                  {...register('password_confirmation', {
                    required: 'Please confirm your password',
                    validate: (value) => value === password || 'Passwords do not match',
                  })}
                  className={`input pr-10 ${errors.password_confirmation ? 'input-error' : ''}`}
                  placeholder="••••••••"
                />
                <button
                  type="button"
                  onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300 transition-colors"
                >
                  {showConfirmPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
              {errors.password_confirmation && (
                <p className="text-xs text-red-400 mt-1.5">{errors.password_confirmation.message as string}</p>
              )}
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-400 mb-2">
                Bio (Optional)
              </label>
              <textarea
                value={bio}
                onChange={(e) => setBio(e.target.value)}
                rows={3}
                className="input min-h-[60px] resize-none"
                placeholder="Tell us about yourself..."
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-400 mb-2">
                Training Notes (Optional)
              </label>
              <textarea
                value={trainingNotes}
                onChange={(e) => setTrainingNotes(e.target.value)}
                rows={3}
                className="input min-h-[60px] resize-none"
                placeholder="Relevant training or qualifications..."
              />
            </div>

            <button
              type="submit"
              disabled={loading}
              className="btn-primary w-full mt-6"
            >
              {loading ? 'Creating Account...' : 'Accept Invitation & Create Account'}
            </button>
          </form>

          <p className="text-xs text-slate-500 text-center mt-6">
            Already have an account?{' '}
            <button
              onClick={() => router.push('/login')}
              className="text-brand-400 hover:text-brand-300 transition-colors font-medium"
            >
              Sign In
            </button>
          </p>
        </div>
      </div>
    </div>
  );
}

export default function AcceptInvitePage() {
  return (
    <Suspense fallback={<div className="min-h-screen flex items-center justify-center">Loading...</div>}>
      <AcceptInviteContent />
    </Suspense>
  );
}
