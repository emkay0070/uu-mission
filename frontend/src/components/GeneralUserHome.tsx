'use client';

import { useState } from 'react';
import { useAuthStore } from '@/store/auth';
import PersonOnboardingWizard from './PersonOnboardingWizard';
import { Globe, User, Mail, ArrowRight, CheckCircle } from 'lucide-react';

interface GeneralUserHomeProps {
  onComplete?: () => void;
}

export default function GeneralUserHome({ onComplete }: GeneralUserHomeProps) {
  const { user, refreshUser } = useAuthStore();
  const [showWizard, setShowWizard] = useState(false);
  const firstName = user?.person?.first_name ?? user?.email?.split('@')[0] ?? 'there';

  const handleWizardComplete = async () => {
    setShowWizard(false);
    await refreshUser();
    onComplete?.();
  };

  if (showWizard) {
    return (
      <div className="max-w-4xl mx-auto p-6">
        <PersonOnboardingWizard
          onSave={handleWizardComplete}
          onCancel={() => setShowWizard(false)}
        />
      </div>
    );
  }

  return (
    <div className="p-4 md:p-6 max-w-4xl mx-auto space-y-6 animate-fade-up">
      {/* Welcome Header */}
      <div className="text-center md:text-left">
        <p className="text-slate-500 text-sm">Welcome to UU Mission</p>
        <h1 className="text-2xl md:text-3xl font-bold text-slate-100 mt-0.5">
          {firstName}
        </h1>
        <p className="text-slate-400 text-sm mt-1">Your account is ready</p>
      </div>

      {/* Account Status Card */}
      <div className="glass-card p-6 md:p-8">
        <div className="flex items-start gap-4">
          <div className="w-12 h-12 rounded-2xl bg-green-500/20 border border-green-500/30 flex items-center justify-center shrink-0">
            <CheckCircle className="w-6 h-6 text-green-400" />
          </div>
          <div className="flex-1">
            <h2 className="text-lg font-semibold text-slate-100 mb-2">Account Verified</h2>
            <p className="text-sm text-slate-400 leading-relaxed">
              Your email has been verified and your account is active. You can now explore the platform and register as a missionary when you're ready.
            </p>
          </div>
        </div>
      </div>

      {/* About UU Mission Card */}
      <div className="glass-card p-6 md:p-8">
        <div className="flex items-start gap-4 mb-4">
          <div className="w-12 h-12 rounded-2xl bg-brand-600/20 border border-brand-600/30 flex items-center justify-center shrink-0">
            <Globe className="w-6 h-6 text-brand-400" />
          </div>
          <div>
            <h2 className="text-lg font-semibold text-slate-100 mb-2">About UU Mission</h2>
            <p className="text-sm text-slate-400 leading-relaxed">
              Uganda Union Mission coordinates mission operations across Uganda, connecting missionaries with local churches and communities to share the gospel through service and outreach.
            </p>
          </div>
        </div>
      </div>

      {/* Become a Missionary Card */}
      <div className="glass-card p-6 md:p-8 border-2 border-brand-500/30">
        <div className="flex items-start gap-4 mb-6">
          <div className="w-12 h-12 rounded-2xl bg-brand-600/20 border border-brand-600/30 flex items-center justify-center shrink-0">
            <User className="w-6 h-6 text-brand-400" />
          </div>
          <div className="flex-1">
            <h2 className="text-lg font-semibold text-slate-100 mb-2">Become a Missionary</h2>
            <p className="text-sm text-slate-400 leading-relaxed mb-4">
              Register as a missionary to join mission campaigns, receive assignments, and connect with teams serving across Uganda.
            </p>
            <button
              onClick={() => setShowWizard(true)}
              className="btn-primary inline-flex items-center gap-2"
            >
              Start Registration
              <ArrowRight className="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      {/* Account Info Card */}
      <div className="glass-card p-6 md:p-8">
        <div className="flex items-start gap-4">
          <div className="w-12 h-12 rounded-2xl bg-slate-700/50 border border-slate-600/30 flex items-center justify-center shrink-0">
            <Mail className="w-6 h-6 text-slate-400" />
          </div>
          <div className="flex-1">
            <h2 className="text-lg font-semibold text-slate-100 mb-2">Your Account</h2>
            <div className="space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-slate-400">Email</span>
                <span className="text-slate-200">{user?.email}</span>
              </div>
              {user?.person?.full_name && (
                <div className="flex justify-between">
                  <span className="text-slate-400">Name</span>
                  <span className="text-slate-200">{user.person.full_name}</span>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
