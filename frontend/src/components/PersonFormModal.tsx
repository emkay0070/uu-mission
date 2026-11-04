'use client';

import { useState, useEffect } from 'react';
import { ChevronRight, X, Check, User, Phone, Loader2 } from 'lucide-react';

export interface PersonFormData {
  first_name: string;
  last_name: string;
  gender: string;
  phone: string;
  date_of_birth: string;
}

interface Props {
  isOpen: boolean;
  onClose: () => void;
  onSave: (data: PersonFormData) => Promise<void>;
  isSaving: boolean;
}

const STEPS = [
  { id: 1, label: 'Basic Info', icon: User },
  { id: 2, label: 'Contact',    icon: Phone },
];

export default function PersonFormModal({
  isOpen, onClose, onSave, isSaving,
}: Props) {
  const [step, setStep] = useState(1);
  const [form, setForm] = useState<PersonFormData>({
    first_name: '',
    last_name: '',
    gender: '',
    phone: '',
    date_of_birth: '',
  });
  const [direction, setDirection] = useState<'forward' | 'back'>('forward');
  const [animating, setAnimating] = useState(false);

  // reset on open
  useEffect(() => {
    if (isOpen) {
      setStep(1);
      setForm({
        first_name: '',
        last_name: '',
        gender: '',
        phone: '',
        date_of_birth: '',
      });
    }
  }, [isOpen]);

  // Lock body scroll while modal is open
  useEffect(() => {
    if (isOpen) {
      document.body.style.overflow = 'hidden';
    }
    return () => {
      document.body.style.overflow = '';
    };
  }, [isOpen]);

  if (!isOpen) return null;

  const goTo = (next: number) => {
    if (animating) return;
    setDirection(next > step ? 'forward' : 'back');
    setAnimating(true);
    setTimeout(() => {
      setStep(next);
      setAnimating(false);
    }, 180);
  };

  const handleSave = async () => {
    await onSave(form);
  };

  const hasName = form.first_name || form.last_name;

  /* ── Date formatted nicely ── */
  const prettyBirthDate = form.date_of_birth
    ? new Date(form.date_of_birth + 'T12:00:00').toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
    : '—';

  /* ── Animation classes ── */
  const contentClass = animating
    ? direction === 'forward'
      ? 'opacity-0 translate-x-4'
      : 'opacity-0 -translate-x-4'
    : 'opacity-100 translate-x-0';

  return (
    <div className="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-4 sm:p-8 overflow-y-auto overflow-x-hidden">
      {/* backdrop */}
      <div
        className="fixed inset-0 bg-black/70 backdrop-blur-2xl"
        onClick={onClose}
      />

      {/* panel */}
      <div className="relative">
        <div
          className="relative bg-slate-950/80 backdrop-blur-3xl rounded-[2rem] shadow-2xl shadow-black/70 overflow-hidden my-4 sm:my-0"
          style={{ boxShadow: '0 32px 80px -12px rgba(0,0,0,0.8), inset 0 1px 0 rgba(255,255,255,0.04)' }}
        >
          {/* close */}
          <button
            onClick={onClose}
            className="absolute top-5 right-5 z-10 w-9 h-9 rounded-full bg-white/8 border border-white/10 flex items-center justify-center text-slate-400 hover:text-slate-100 hover:bg-white/15 transition-all"
          >
            <X className="w-4 h-4" />
          </button>

          {/* content area */}
          <div
            className={`transition-all duration-200 ease-in-out ${contentClass}`}
            style={{ minHeight: '420px' }}
          >
            {/* ── Step 1: Basic Info ── */}
            {step === 1 && (
              <div className="px-10 pt-8 pb-10 flex flex-col items-center text-center">
                <h2 className="text-3xl font-bold text-white mb-1 tracking-tight">Basic Info</h2>
                <p className="text-slate-400 text-sm mb-10">Let's start with their name</p>

                {/* Name fields */}
                <div className="space-y-4 mb-10 w-full max-w-xs">
                  <div className="text-left">
                    <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-3">First Name</label>
                    <input
                      type="text"
                      value={form.first_name}
                      onChange={e => setForm({ ...form, first_name: e.target.value })}
                      placeholder="Enter first name"
                      className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-lg font-medium focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all placeholder:text-slate-600"
                    />
                  </div>
                  <div className="text-left">
                    <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-3">Last Name</label>
                    <input
                      type="text"
                      value={form.last_name}
                      onChange={e => setForm({ ...form, last_name: e.target.value })}
                      placeholder="Enter last name"
                      className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-lg font-medium focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all placeholder:text-slate-600"
                    />
                  </div>
                </div>
                <p className="text-xs text-slate-500 mb-8">At least one name is required</p>

                {/* Gender dropdown */}
                <div className="w-full">
                  <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-3">Gender</label>
                  <select
                    value={form.gender}
                    onChange={e => setForm({ ...form, gender: e.target.value })}
                    className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                  >
                    <option value="">Select gender</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="unspecified">Unspecified</option>
                  </select>
                </div>
              </div>
            )}

            {/* ── Step 2: Contact ── */}
            {step === 2 && (
              <div className="px-10 pt-8 pb-10 flex flex-col items-center text-center">
                <h2 className="text-3xl font-bold text-white mb-1 tracking-tight">Contact</h2>
                <p className="text-slate-400 text-sm mb-10">How can we reach them?</p>

                <div className="space-y-6 mb-10 w-full max-w-xs">
                  {/* Phone */}
                  <div className="text-left">
                    <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-3">Phone</label>
                    <input
                      type="tel"
                      value={form.phone}
                      onChange={e => setForm({ ...form, phone: e.target.value })}
                      placeholder="Enter phone number"
                      className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all placeholder:text-slate-600"
                    />
                  </div>

                  {/* Date of Birth */}
                  <div className="text-left">
                    <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-3">Date of Birth</label>
                    <input
                      type="date"
                      value={form.date_of_birth}
                      onChange={e => setForm({ ...form, date_of_birth: e.target.value })}
                      className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                    />
                    {form.date_of_birth && (
                      <p className="text-slate-400 text-sm mt-2">{prettyBirthDate}</p>
                    )}
                  </div>
                </div>

                {/* Review summary card */}
                <div className="bg-white/4 rounded-2xl p-5 space-y-3 w-full max-w-xs text-left">
                  <p className="text-xs font-semibold uppercase tracking-widest text-slate-500 mb-4">Summary</p>
                  <SummaryRow label="Name" value={`${form.first_name} ${form.last_name}`.trim() || '—'} />
                  {form.gender && <SummaryRow label="Gender" value={form.gender} />}
                  {form.phone && <SummaryRow label="Phone" value={form.phone} />}
                  {form.date_of_birth && <SummaryRow label="DOB" value={prettyBirthDate} />}
                </div>
              </div>
            )}

          </div>

          {/* navigation */}
          <div className="px-10 pb-10 flex items-center justify-center gap-4">
            {step > 1 ? (
              <button
                type="button"
                onClick={() => goTo(step - 1)}
                disabled={animating}
                className="px-8 py-3.5 rounded-2xl bg-white/5 text-slate-300 text-sm font-semibold hover:bg-white/10 transition-all"
              >
                Back
              </button>
            ) : (
              <button
                type="button"
                onClick={onClose}
                className="px-8 py-3.5 rounded-2xl bg-white/5 text-slate-300 text-sm font-semibold hover:bg-white/10 transition-all"
              >
                Cancel
              </button>
            )}

            {step < STEPS.length ? (
              <button
                type="button"
                onClick={() => goTo(step + 1)}
                disabled={animating || !hasName}
                className="px-10 py-3.5 rounded-2xl bg-brand-600 text-white text-sm font-semibold hover:bg-brand-500 disabled:opacity-40 disabled:cursor-not-allowed transition-all flex items-center gap-2 shadow-lg shadow-brand-600/30"
              >
                Continue
                <ChevronRight className="w-4 h-4" />
              </button>
            ) : (
              <button
                type="button"
                onClick={handleSave}
                disabled={isSaving || !hasName}
                className="px-10 py-3.5 rounded-2xl bg-brand-600 text-white text-sm font-semibold hover:bg-brand-500 disabled:opacity-40 disabled:cursor-not-allowed transition-all flex items-center gap-2 shadow-lg shadow-brand-600/30"
              >
                {isSaving ? (
                  <>
                    <Loader2 className="w-4 h-4 animate-spin" />
                    Saving…
                  </>
                ) : (
                  <>
                    <Check className="w-4 h-4" />
                    Create Contact
                  </>
                )}
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

function SummaryRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-start justify-between gap-4 py-1">
      <span className="text-xs text-slate-500 uppercase tracking-widest shrink-0">{label}</span>
      <span className="text-sm text-slate-200 text-right">{value}</span>
    </div>
  );
}
