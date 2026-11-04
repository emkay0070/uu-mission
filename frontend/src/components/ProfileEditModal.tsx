'use client';

import { useState, useEffect } from 'react';
import { X, Check, User, Phone, Calendar, Mail, Briefcase, GraduationCap, Globe, Building2, ChevronRight, Loader2 } from 'lucide-react';
import { cn } from './ui';

const STEPS = [
  { id: 1, label: 'Personal', icon: User },
  { id: 2, label: 'Missionary', icon: Briefcase },
  { id: 3, label: 'Affiliation', icon: Building2 },
];

interface Props {
  isOpen: boolean;
  onClose: () => void;
  onSave: (data: ProfileFormData) => Promise<void>;
  isSaving: boolean;
  initialData?: ProfileFormData;
}

export interface ProfileFormData {
  // Personal
  first_name?: string;
  last_name?: string;
  phone?: string;
  date_of_birth?: string;
  gender?: string;
  // Missionary
  bio?: string;
  training_notes?: string;
  // Affiliation
  home_org_unit_id?: number;
}

export default function ProfileEditModal({ isOpen, onClose, onSave, isSaving, initialData }: Props) {
  const [step, setStep] = useState(1);
  const [direction, setDirection] = useState<'forward' | 'back'>('forward');
  const [animating, setAnimating] = useState(false);
  const [form, setForm] = useState<ProfileFormData>({});

  useEffect(() => {
    if (initialData) {
      setForm(initialData);
    }
  }, [initialData]);

  const goTo = (next: number) => {
    if (animating) return;
    setDirection(next > step ? 'forward' : 'back');
    setAnimating(true);
    setTimeout(() => {
      setStep(next);
      setAnimating(false);
    }, 180);
  };

  const handleSubmit = async () => {
    await onSave(form);
  };

  const contentClass = animating
    ? direction === 'forward'
      ? 'opacity-0 translate-x-4'
      : 'opacity-0 -translate-x-4'
    : 'opacity-100 translate-x-0';

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-4 sm:p-8 overflow-y-auto overflow-x-hidden">
      {/* backdrop */}
      <div className="fixed inset-0 bg-black/70 backdrop-blur-2xl" onClick={onClose} />

      {/* panel */}
      <div className="relative">
        <div className="relative bg-slate-950/80 backdrop-blur-3xl rounded-[2rem] shadow-2xl shadow-black/70 overflow-hidden my-4 sm:my-0">
          {/* close */}
          <button
            onClick={onClose}
            className="absolute top-5 right-5 z-10 w-9 h-9 rounded-full bg-white/8 border border-white/10 flex items-center justify-center text-slate-400 hover:text-slate-100 hover:bg-white/15 transition-all"
          >
            <X className="w-5 h-5" />
          </button>

          {/* content area */}
          <div className={`transition-all duration-200 ease-in-out ${contentClass}`} style={{ minHeight: '420px' }}>
            {/* Personal Info */}
            {step === 1 && (
              <div className="px-10 pb-10 pt-6">
                <div className="flex items-center gap-3 mb-6">
                  <User className="w-6 h-6 text-brand-400" />
                  <h2 className="text-xl font-bold text-slate-100">Personal Information</h2>
                </div>

                <div className="space-y-5">
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-slate-300 mb-2">First Name</label>
                      <input
                        type="text"
                        value={form.first_name || ''}
                        onChange={(e) => setForm({ ...form, first_name: e.target.value })}
                        className="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500/50 focus:border-brand-500/50 transition-all"
                        placeholder="Enter first name"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-slate-300 mb-2">Last Name</label>
                      <input
                        type="text"
                        value={form.last_name || ''}
                        onChange={(e) => setForm({ ...form, last_name: e.target.value })}
                        className="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500/50 focus:border-brand-500/50 transition-all"
                        placeholder="Enter last name"
                      />
                    </div>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-slate-300 mb-2">Phone</label>
                    <input
                      type="tel"
                      value={form.phone || ''}
                      onChange={(e) => setForm({ ...form, phone: e.target.value })}
                      className="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500/50 focus:border-brand-500/50 transition-all"
                      placeholder="Enter phone number"
                    />
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-slate-300 mb-2">Date of Birth</label>
                      <input
                        type="date"
                        value={form.date_of_birth || ''}
                        onChange={(e) => setForm({ ...form, date_of_birth: e.target.value })}
                        className="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500/50 focus:border-brand-500/50 transition-all"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-slate-300 mb-2">Gender</label>
                      <select
                        value={form.gender || ''}
                        onChange={(e) => setForm({ ...form, gender: e.target.value })}
                        className="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500/50 focus:border-brand-500/50 transition-all"
                      >
                        <option value="">Select gender</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="unspecified">Unspecified</option>
                      </select>
                    </div>
                  </div>
                </div>
              </div>
            )}

            {/* Missionary Profile */}
            {step === 2 && (
              <div className="px-10 pb-10 pt-6">
                <div className="flex items-center gap-3 mb-6">
                  <Briefcase className="w-6 h-6 text-brand-400" />
                  <h2 className="text-xl font-bold text-slate-100">Missionary Profile</h2>
                </div>

                <div className="space-y-5">
                  <div>
                    <label className="block text-sm font-medium text-slate-300 mb-2">Bio</label>
                    <textarea
                      value={form.bio || ''}
                      onChange={(e) => setForm({ ...form, bio: e.target.value })}
                      rows={4}
                      className="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500/50 focus:border-brand-500/50 transition-all resize-none"
                      placeholder="Tell us about yourself..."
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-slate-300 mb-2">Training Notes</label>
                    <textarea
                      value={form.training_notes || ''}
                      onChange={(e) => setForm({ ...form, training_notes: e.target.value })}
                      rows={4}
                      className="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500/50 focus:border-brand-500/50 transition-all resize-none"
                      placeholder="Relevant training or qualifications..."
                    />
                  </div>
                </div>
              </div>
            )}

            {/* Organisational Affiliation */}
            {step === 3 && (
              <div className="px-10 pb-10 pt-6">
                <div className="flex items-center gap-3 mb-6">
                  <Building2 className="w-6 h-6 text-brand-400" />
                  <h2 className="text-xl font-bold text-slate-100">Organisational Affiliation</h2>
                </div>

                <div className="space-y-5">
                  <div>
                    <label className="block text-sm font-medium text-slate-300 mb-2">Home Organisation</label>
                    <select
                      value={form.home_org_unit_id || ''}
                      onChange={(e) => setForm({ ...form, home_org_unit_id: e.target.value ? parseInt(e.target.value) : undefined })}
                      className="w-full px-4 py-3 rounded-xl bg-white/5 border border-white/10 text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-brand-500/50 focus:border-brand-500/50 transition-all"
                    >
                      <option value="">Select organisation</option>
                      {/* This would be populated from API */}
                      <option value="1">Sample Organisation</option>
                    </select>
                  </div>

                  <div className="bg-white/4 rounded-xl p-6 mt-6">
                    <h3 className="text-sm font-semibold text-slate-300 mb-4">Summary</h3>
                    <div className="space-y-3 text-sm">
                      <div className="flex justify-between">
                        <span className="text-slate-400">Name</span>
                        <span className="text-slate-200">{form.first_name} {form.last_name || '—'}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-slate-400">Phone</span>
                        <span className="text-slate-200">{form.phone || '—'}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-slate-400">Gender</span>
                        <span className="text-slate-200">{form.gender || '—'}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-slate-400">Bio</span>
                        <span className="text-slate-200 truncate max-w-xs">{form.bio || '—'}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            )}
          </div>

          {/* navigation buttons */}
          <div className="px-10 pb-8 pt-4 flex items-center justify-between gap-4">
            {step > 1 ? (
              <button
                onClick={() => goTo(step - 1)}
                disabled={animating}
                className="px-6 py-3 rounded-xl bg-white/5 text-slate-300 text-sm font-semibold hover:bg-white/10 transition-all"
              >
                Back
              </button>
            ) : (
              <button
                onClick={onClose}
                disabled={isSaving}
                className="px-6 py-3 rounded-xl bg-white/5 text-slate-300 text-sm font-semibold hover:bg-white/10 transition-all"
              >
                Cancel
              </button>
            )}

            {step < STEPS.length ? (
              <button
                onClick={() => goTo(step + 1)}
                disabled={animating}
                className="px-8 py-3 rounded-xl bg-brand-600 text-white text-sm font-semibold hover:bg-brand-500 disabled:opacity-40 disabled:cursor-not-allowed transition-all flex items-center gap-2 shadow-lg shadow-brand-600/30"
              >
                Continue
                <ChevronRight className="w-4 h-4" />
              </button>
            ) : (
              <button
                onClick={handleSubmit}
                disabled={isSaving}
                className="px-8 py-3 rounded-xl bg-brand-600 text-white text-sm font-semibold hover:bg-brand-500 disabled:opacity-40 disabled:cursor-not-allowed transition-all flex items-center gap-2 shadow-lg shadow-brand-600/30"
              >
                {isSaving ? (
                  <>
                    <Loader2 className="w-4 h-4 animate-spin" />
                    Saving...
                  </>
                ) : (
                  'Save Changes'
                )}
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
