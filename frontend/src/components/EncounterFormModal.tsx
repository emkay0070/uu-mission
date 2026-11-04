'use client';

import { useState, useEffect } from 'react';
import { ChevronRight, X, Check, CalendarDays, MessageSquare, TrendingUp, Bell } from 'lucide-react';
import type { EncounterMethod, EngagementStage, Person, MyAssignment } from '@/lib/api';

export interface EncounterFormData {
  encounter_date: string;
  encounter_method_id: string;
  notes: string;
  stage_after_encounter_id: string;
  next_action: string;
  follow_up_date: string;
  assigned_follow_up_user_id: string;
}

interface Props {
  isOpen: boolean;
  onClose: () => void;
  onSave: (data: EncounterFormData) => Promise<void>;
  person: Person;
  assignment: MyAssignment | null;
  encounterMethods: EncounterMethod[];
  engagementStages: EngagementStage[];
  authorisedUsers: Array<{ user_id: number; person_id: number; name: string; email?: string }>;
  isSaving: boolean;
}

const STEPS = [
  { id: 1, label: 'When & How',    icon: CalendarDays },
  { id: 2, label: 'What Happened', icon: MessageSquare },
  { id: 3, label: 'Follow-up',     icon: Bell },
];

const COLOR_MAP: Record<string, { bg: string; ring: string; text: string; selBg: string }> = {
  blue:    { bg: 'bg-blue-500/10',    ring: 'ring-blue-500/30',    text: 'text-blue-300',    selBg: 'bg-blue-500' },
  cyan:    { bg: 'bg-cyan-500/10',    ring: 'ring-cyan-500/30',    text: 'text-cyan-300',    selBg: 'bg-cyan-500' },
  purple:  { bg: 'bg-purple-500/10',  ring: 'ring-purple-500/30',  text: 'text-purple-300',  selBg: 'bg-purple-500' },
  amber:   { bg: 'bg-amber-500/10',   ring: 'ring-amber-500/30',   text: 'text-amber-300',   selBg: 'bg-amber-500' },
  emerald: { bg: 'bg-emerald-500/10', ring: 'ring-emerald-500/30', text: 'text-emerald-300', selBg: 'bg-emerald-500' },
  green:   { bg: 'bg-green-500/10',   ring: 'ring-green-500/30',   text: 'text-green-300',   selBg: 'bg-green-500' },
  indigo:  { bg: 'bg-indigo-500/10',  ring: 'ring-indigo-500/30',  text: 'text-indigo-300',  selBg: 'bg-indigo-500' },
  red:     { bg: 'bg-red-500/10',     ring: 'ring-red-500/30',     text: 'text-red-300',     selBg: 'bg-red-500' },
  orange:  { bg: 'bg-orange-500/10',  ring: 'ring-orange-500/30',  text: 'text-orange-300',  selBg: 'bg-orange-500' },
  teal:    { bg: 'bg-teal-500/10',    ring: 'ring-teal-500/30',    text: 'text-teal-300',    selBg: 'bg-teal-500' },
  violet:  { bg: 'bg-violet-500/10',  ring: 'ring-violet-500/30',  text: 'text-violet-300',  selBg: 'bg-violet-500' },
  slate:   { bg: 'bg-slate-500/10',   ring: 'ring-slate-500/30',   text: 'text-slate-300',   selBg: 'bg-slate-500' },
};
const fallbackColor = COLOR_MAP.slate;

function stageColors(colorKey: string, selected: boolean) {
  const c = COLOR_MAP[colorKey] ?? fallbackColor;
  if (selected) return `${c.selBg} text-white ring-2 ${c.ring} shadow-lg`;
  return `${c.bg} ${c.text} ring-1 ${c.ring} hover:opacity-80`;
}

export default function EncounterFormModal({
  isOpen, onClose, onSave, person, encounterMethods, engagementStages, authorisedUsers, isSaving,
}: Props) {
  const [step, setStep] = useState(1);
  const [form, setForm] = useState<EncounterFormData>({
    encounter_date: new Date().toISOString().split('T')[0],
    encounter_method_id: '',
    notes: '',
    stage_after_encounter_id: '',
    next_action: '',
    follow_up_date: '',
    assigned_follow_up_user_id: '',
  });
  const [direction, setDirection] = useState<'forward' | 'back'>('forward');
  const [animating, setAnimating] = useState(false);

  // reset on open
  useEffect(() => {
    if (isOpen) {
      setStep(1);
      setForm({
        encounter_date: new Date().toISOString().split('T')[0],
        encounter_method_id: '',
        notes: '',
        stage_after_encounter_id: '',
        next_action: '',
        follow_up_date: '',
        assigned_follow_up_user_id: '',
      });
    }
  }, [isOpen]);

  // Lock body scroll while modal is open — prevents the body scrollbar
  // from bleeding through the fixed overlay
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

  /* ── Derived display values for review ── */
  const selectedMethod = encounterMethods.find(m => String(m.id) === form.encounter_method_id);
  const selectedStage  = engagementStages.find(s => String(s.id) === form.stage_after_encounter_id);
  const currentStage   = person.engagement_stage;

  /* ── Date formatted nicely ── */
  const prettyDate = form.encounter_date
    ? new Date(form.encounter_date + 'T12:00:00').toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
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
            {/* ── Step 1: When & How ── */}
            {step === 1 && (
              <div className="px-10 pt-8 pb-10 flex flex-col items-center text-center">
                <h2 className="text-3xl font-bold text-white mb-1 tracking-tight">When &amp; How</h2>
                <p className="text-slate-400 text-sm mb-10">When did you meet {person.first_name}?</p>

                {/* Date selector */}
                <div className="mb-10 w-full max-w-xs">
                  <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-3">Date</label>
                  <div className="relative">
                    <input
                      type="date"
                      value={form.encounter_date}
                      onChange={e => setForm({ ...form, encounter_date: e.target.value })}
                      className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-lg font-medium focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all cursor-pointer text-center"
                    />
                  </div>
                  {form.encounter_date && (
                    <p className="text-slate-400 text-sm mt-2">{prettyDate}</p>
                  )}
                </div>

                {/* Method capsules */}
                <div className="w-full">
                  <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-4">How did you meet?</label>
                  <div className="flex flex-wrap gap-3 justify-center">
                    {encounterMethods.map(method => {
                      const selected = form.encounter_method_id === String(method.id);
                      return (
                        <button
                          key={method.id}
                          type="button"
                          onClick={() => setForm({ ...form, encounter_method_id: selected ? '' : String(method.id) })}
                          className={`px-5 py-2.5 rounded-full text-sm font-medium transition-all duration-200 ${
                            selected
                              ? 'bg-brand-500 text-white ring-2 ring-brand-400/40 shadow-lg shadow-brand-500/30'
                              : 'bg-white/5 text-slate-300 ring-1 ring-white/10 hover:bg-white/10 hover:text-slate-100'
                          }`}
                        >
                          {selected && <Check className="inline w-3.5 h-3.5 mr-1.5 -mt-0.5" />}
                          {method.label}
                        </button>
                      );
                    })}
                    {encounterMethods.length === 0 && (
                      <p className="text-slate-500 text-sm">Loading methods…</p>
                    )}
                  </div>
                </div>
              </div>
            )}

            {/* ── Step 2: What Happened ── */}
            {step === 2 && (
              <div className="px-10 pt-8 pb-10 flex flex-col items-center text-center">
                <h2 className="text-3xl font-bold text-white mb-1 tracking-tight">What Happened</h2>
                <p className="text-slate-400 text-sm mb-10">Notes and engagement progress</p>

                {/* Notes */}
                <div className="mb-10 w-full max-w-sm">
                  <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-3">Notes</label>
                  <textarea
                    value={form.notes}
                    onChange={e => setForm({ ...form, notes: e.target.value })}
                    rows={4}
                    placeholder={`How did the conversation with ${person.first_name} go?`}
                    className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm leading-relaxed focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all resize-none placeholder:text-slate-600 text-left"
                  />
                </div>

                {/* Stage change */}
                <div className="w-full">
                  <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2">Engagement Stage</label>
                  {currentStage && (
                    <p className="text-slate-500 text-xs mb-4">
                      Currently: <span className="text-slate-300">{currentStage.label}</span>
                      {' '}— select a new stage only if it changed
                    </p>
                  )}
                  <div className="flex flex-wrap gap-3 justify-center">
                    {engagementStages.map(stage => {
                      const selected = form.stage_after_encounter_id === String(stage.id);
                      const c = COLOR_MAP[stage.color_key] ?? fallbackColor;
                      return (
                        <button
                          key={stage.id}
                          type="button"
                          onClick={() => setForm({ ...form, stage_after_encounter_id: selected ? '' : String(stage.id) })}
                          className={`px-4 py-2 rounded-full text-sm font-medium transition-all duration-200 ring-1 ${
                            selected
                              ? `${c.selBg} text-white ring-2 ${c.ring} shadow-lg`
                              : `${c.bg} ${c.text} ${c.ring} hover:opacity-80`
                          }`}
                        >
                          {selected && <Check className="inline w-3.5 h-3.5 mr-1.5 -mt-0.5" />}
                          {stage.label}
                        </button>
                      );
                    })}
                  </div>
                </div>
              </div>
            )}

            {/* ── Step 3: Follow-up ── */}
            {step === 3 && (
              <div className="px-10 pt-8 pb-10 flex flex-col items-center text-center">
                <h2 className="text-3xl font-bold text-white mb-1 tracking-tight">Follow-up</h2>
                <p className="text-slate-400 text-sm mb-10">Plan the next step with {person.first_name}</p>

                <div className="space-y-6 mb-10 w-full max-w-xs">
                  {/* Next action */}
                  <div className="text-left">
                    <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-3">Next Action</label>
                    <input
                      type="text"
                      value={form.next_action}
                      onChange={e => setForm({ ...form, next_action: e.target.value })}
                      placeholder="e.g., Arrange a Bible study…"
                      className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all placeholder:text-slate-600"
                    />
                  </div>

                  {/* Follow-up date */}
                  <div className="text-left">
                    <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-3">Follow-up Date</label>
                    <input
                      type="date"
                      value={form.follow_up_date}
                      onChange={e => setForm({ ...form, follow_up_date: e.target.value })}
                      className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                    />
                  </div>

                  {/* Assigned to */}
                  <div className="text-left">
                    <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-3">Assigned To</label>
                    <select
                      value={form.assigned_follow_up_user_id}
                      onChange={e => setForm({ ...form, assigned_follow_up_user_id: e.target.value })}
                      className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                    >
                      <option value="">Unassigned</option>
                      {authorisedUsers.map(user => (
                        <option key={user.user_id} value={user.user_id}>
                          {user.name} {user.email && `(${user.email})`}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>

                {/* Review summary card */}
                <div className="bg-white/4 rounded-2xl p-5 space-y-3 w-full max-w-xs text-left">
                  <p className="text-xs font-semibold uppercase tracking-widest text-slate-500 mb-4">Summary</p>
                  <SummaryRow label="Date" value={prettyDate} />
                  <SummaryRow label="Method" value={selectedMethod?.label || '—'} />
                  {form.notes && <SummaryRow label="Notes" value={form.notes.length > 60 ? form.notes.slice(0, 60) + '…' : form.notes} />}
                  {selectedStage && (
                    <div className="flex items-center justify-between py-1">
                      <span className="text-xs text-slate-500 uppercase tracking-widest">Stage</span>
                      <span className={`px-3 py-1 rounded-full text-xs font-medium ${stageColors(selectedStage.color_key, true)}`}>
                        {selectedStage.label}
                      </span>
                    </div>
                  )}
                  {form.next_action && <SummaryRow label="Next action" value={form.next_action} />}
                  {form.follow_up_date && (
                    <SummaryRow
                      label="Follow-up"
                      value={new Date(form.follow_up_date + 'T12:00:00').toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}
                    />
                  )}
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
                disabled={animating || !form.encounter_date}
                className="px-10 py-3.5 rounded-2xl bg-brand-600 text-white text-sm font-semibold hover:bg-brand-500 disabled:opacity-40 disabled:cursor-not-allowed transition-all flex items-center gap-2 shadow-lg shadow-brand-600/30"
              >
                Continue
                <ChevronRight className="w-4 h-4" />
              </button>
            ) : (
              <button
                type="button"
                onClick={handleSave}
                disabled={isSaving || !form.encounter_date}
                className="px-10 py-3.5 rounded-2xl bg-brand-600 text-white text-sm font-semibold hover:bg-brand-500 disabled:opacity-40 disabled:cursor-not-allowed transition-all flex items-center gap-2 shadow-lg shadow-brand-600/30"
              >
                {isSaving ? (
                  <>
                    <span className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                    Saving…
                  </>
                ) : (
                  <>
                    <Check className="w-4 h-4" />
                    Save Encounter
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
