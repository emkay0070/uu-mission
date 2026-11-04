'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { CheckCircle, Clock, AlertTriangle, LogOut, User } from 'lucide-react';

export default function WelcomePage() {
  const router = useRouter();

  useEffect(() => {
    // Check if user is authenticated
    const token = localStorage.getItem('uu_token');
    if (!token) {
      router.push('/login');
    }
  }, [router]);

  const handleLogout = () => {
    localStorage.removeItem('uu_token');
    router.push('/login');
  };

  return (
    <div className="min-h-screen bg-surface flex flex-col items-center justify-center p-8">
      <div className="absolute inset-0 overflow-hidden pointer-events-none">
        <div className="absolute -top-40 left-1/2 -translate-x-1/2 w-[600px] h-[600px] bg-brand-600/10 rounded-full blur-3xl" />
      </div>

      <div className="relative w-full max-w-2xl animate-fade-up">
        <div className="flex flex-col items-center gap-4 mb-8">
          <div className="w-20 h-20 rounded-full bg-green-500/20 flex items-center justify-center">
            <CheckCircle className="w-10 h-10 text-green-400" />
          </div>
          <div className="text-center">
            <h1 className="text-3xl font-bold text-slate-100 mb-2">Welcome to UU Mission</h1>
            <p className="text-slate-400">Your account has been successfully created</p>
          </div>
        </div>

        <div className="card p-8 space-y-6">
          <div className="flex items-start gap-4 p-4 bg-green-500/10 border border-green-500/30 rounded-lg">
            <CheckCircle className="w-6 h-6 text-green-400 shrink-0 mt-0.5" />
            <div>
              <h3 className="text-sm font-medium text-slate-200 mb-1">Account Created</h3>
              <p className="text-xs text-slate-400">Your account is now active and you can access your profile.</p>
            </div>
          </div>

          <div className="flex items-start gap-4 p-4 bg-blue-500/10 border border-blue-500/30 rounded-lg">
            <Clock className="w-6 h-6 text-blue-400 shrink-0 mt-0.5" />
            <div>
              <h3 className="text-sm font-medium text-slate-200 mb-1">Church Affiliation Pending Approval</h3>
              <p className="text-xs text-slate-400">
                If you selected a local church during registration, your affiliation is awaiting review by authorized officers. 
                You will be notified once it is approved.
              </p>
            </div>
          </div>

          <div className="flex items-start gap-4 p-4 bg-amber-500/10 border border-amber-500/30 rounded-lg">
            <AlertTriangle className="w-6 h-6 text-amber-400 shrink-0 mt-0.5" />
            <div>
              <h3 className="text-sm font-medium text-slate-200 mb-1">No Mission Assignment Yet</h3>
              <p className="text-xs text-slate-400">
                Your account does not currently have a campaign or operational assignment. 
                Additional mission tools and features will become available when authorized assignments are made.
              </p>
            </div>
          </div>

          <div className="flex items-start gap-4 p-4 bg-slate-700/50 border border-slate-600 rounded-lg">
            <User className="w-6 h-6 text-slate-400 shrink-0 mt-0.5" />
            <div>
              <h3 className="text-sm font-medium text-slate-200 mb-1">What's Next?</h3>
              <ul className="text-xs text-slate-400 space-y-1 mt-2">
                <li>• Complete your profile information</li>
                <li>• Wait for church affiliation approval (if applicable)</li>
                <li>• Contact your local church leadership about mission opportunities</li>
                <li>• Watch for assignment notifications from mission coordinators</li>
              </ul>
            </div>
          </div>

          <div className="pt-4 border-t border-slate-700">
            <button
              onClick={handleLogout}
              className="flex items-center gap-2 px-4 py-2 rounded-lg border border-slate-600 text-slate-300 hover:bg-slate-700 transition-colors w-full justify-center"
            >
              <LogOut className="w-4 h-4" />
              Sign Out
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
