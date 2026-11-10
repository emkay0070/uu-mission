'use client';
import { useState, useRef, useEffect } from 'react';
import Link from 'next/link';
import { Languages, Mic, MessageSquare, Volume2, Copy, History, Book, ArrowRight, Play, Loader2, Square, RotateCcw, Trash2, StopCircle, Bookmark, BookmarkCheck, X, User, MapPin, Church, Briefcase, Shield, ChevronRight, Check } from 'lucide-react';
import { translationApi, transcribeApi, synthesisApi, api, type TranslationRequest } from '@/lib/api';
import { savePhrase, getSavedPhrases, deletePhrase, updatePhraseWithAudio, type SavedPhrase } from '@/lib/indexedDB';
import { useAuthStore } from '@/store/auth';
import CascadingOrgHierarchyPicker from './CascadingOrgHierarchyPicker';

interface FormData {
  personal: {
    first_name: string;
    middle_name: string;
    last_name: string;
    preferred_name: string;
    gender: string;
    date_of_birth: string;
    nationality: string;
  };
  contact_residence: {
    contact: {
      phone: string;
      whatsapp_phone: string;
      alternative_phone: string;
      email: string;
      preferred_contact_method: string;
      emergency_contact_name: string;
      emergency_contact_relationship: string;
      emergency_contact_phone: string;
      occupation: string;
    };
    address: {
      country: string;
      region: string;
      district: string;
      county_municipality: string;
      parish_town: string;
      village_area: string;
      physical_address: string;
      is_primary: boolean;
    };
  };
  church_affiliation: {
    local_church_id: number | null;
  };
  ministry_profile: {
    skills: Array<{
      skill_category: string;
      skill_name: string;
      proficiency_level: string;
      years_experience: number;
    }>;
    languages: Array<{
      language_code: string;
      is_native: boolean;
      proficiency_level: string;
    }>;
    mission_experience: Array<{
      mission_name: string;
      location: string;
      started_on: string;
      ended_on: string;
      role: string;
      description: string;
    }>;
  };
  readiness: {
    training: Array<{
      training_type: string;
      training_name: string;
      training_provider: string;
      completed_on: string;
      expires_on: string;
    }>;
    readiness_notes: string;
  };
}

interface PersonOnboardingWizardProps {
  onSave: (data: FormData) => void;
  onCancel: () => void;
  mode?: 'missionary' | 'upgrade';
  personId?: number | null;
}

const STEPS = [
  { id: 1, label: 'Personal Details', icon: User },
  { id: 2, label: 'Contact Info', icon: MapPin },
  { id: 3, label: 'Emergency Contact', icon: MapPin },
  { id: 4, label: 'Residence', icon: MapPin },
  { id: 5, label: 'Church Affiliation', icon: Church },
  { id: 6, label: 'Skills', icon: Briefcase },
  { id: 7, label: 'Languages', icon: Briefcase },
  { id: 8, label: 'Mission Experience', icon: Briefcase },
  { id: 9, label: 'Readiness', icon: Shield },
];

export default function PersonOnboardingWizard({ onSave, onCancel, mode = 'missionary', personId = null }: PersonOnboardingWizardProps) {
  const { user } = useAuthStore();
  const [step, setStep] = useState(1);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [selectedChurch, setSelectedChurch] = useState<any>(null);
  const [direction, setDirection] = useState<'forward' | 'back'>('forward');
  const [animating, setAnimating] = useState(false);
  const [languageInput, setLanguageInput] = useState({ name: '', proficiency: '' });
  const [addedLanguages, setAddedLanguages] = useState<Array<{name: string, proficiency: string}>>([]);
  const [availableLanguages, setAvailableLanguages] = useState<Array<{code: string, name: string}>>([]);
  const [showCustomInput, setShowCustomInput] = useState(false);

  const [skillInput, setSkillInput] = useState({ category: '', name: '', proficiency: '', years: '' });
  const [addedSkills, setAddedSkills] = useState<Array<{category: string, name: string, proficiency: string, years: string}>>([]);
  const [availableSkillCategories, setAvailableSkillCategories] = useState<Array<{code: string, label: string}>>([]);
  const [showCustomSkillInput, setShowCustomSkillInput] = useState(false);

  // Lock body scroll while wizard is open
  useEffect(() => {
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = '';
    };
  }, []);

  // Fetch available languages when entering language step
  useEffect(() => {
    if (step === 7 && availableLanguages.length === 0) {
      api.get<{ code: string; name: string }[]>('/ref/languages')
        .then(data => setAvailableLanguages(data))
        .catch(err => console.error('Failed to fetch languages:', err));
    }
  }, [step, availableLanguages.length]);

  // Fetch available skill categories when entering skills step
  useEffect(() => {
    if (step === 6 && availableSkillCategories.length === 0) {
      api.get<{ code: string; label: string }[]>('/ref/skill-categories')
        .then(data => setAvailableSkillCategories(data))
        .catch(err => console.error('Failed to fetch skill categories:', err));
    }
  }, [step, availableSkillCategories.length]);

  const [formData, setFormData] = useState<FormData>({
    personal: {
      first_name: user?.person?.first_name || '',
      middle_name: '',
      last_name: user?.person?.last_name || '',
      preferred_name: '',
      gender: user?.person?.gender || '',
      date_of_birth: '',
      nationality: 'UG',
    },
    contact_residence: {
      contact: {
        phone: user?.person?.phone || '',
        whatsapp_phone: '',
        alternative_phone: '',
        email: user?.email || '',
        preferred_contact_method: 'phone',
        emergency_contact_name: '',
        emergency_contact_relationship: '',
        emergency_contact_phone: '',
        occupation: '',
      },
      address: {
        country: 'Uganda',
        region: '',
        district: '',
        county_municipality: '',
        parish_town: '',
        village_area: '',
        physical_address: '',
        is_primary: true,
      },
    },
    church_affiliation: {
      local_church_id: null,
    },
    ministry_profile: {
      skills: [],
      languages: [],
      mission_experience: [],
    },
    readiness: {
      training: [],
      readiness_notes: '',
    },
  });

  const updateFormData = (section: keyof FormData, data: any) => {
    setFormData(prev => ({
      ...prev,
      [section]: { ...prev[section], ...data },
    }));
  };

  const goTo = (next: number) => {
    if (animating) return;
    setDirection(next > step ? 'forward' : 'back');
    setAnimating(true);
    setTimeout(() => {
      setStep(next);
      setAnimating(false);
    }, 180);
  };

  const handleNext = () => {
    if (step < 7) goTo(step + 1);
  };

  const handleBack = () => {
    if (step > 1) goTo(step - 1);
  };

  const handleSubmit = async () => {
    setLoading(true);
    setError(null);

    try {
      const payload: any = { ...formData, mode };
      if (mode === 'upgrade' && personId) {
        payload.person_id = personId;
      }

      const response = await fetch('/api/v1/onboarding/register', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${localStorage.getItem('uu_token')}`,
        },
        body: JSON.stringify(payload),
      });

      const result = await response.json();

      if (!response.ok) {
        throw new Error(result.message || 'Registration failed');
      }

      onSave(result);
    } catch (err: any) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const renderStep = () => {
    switch (step) {
      case 1:
        return (
          <div className="px-10 pt-8 pb-10 flex flex-col items-center text-center">
            <h2 className="text-3xl font-bold text-white mb-1 tracking-tight">Personal Details</h2>
            <p className="text-slate-400 text-sm mb-10">Tell us about yourself</p>

            <div className="space-y-4 w-full max-w-md">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">First Name *</label>
                  <input
                    type="text"
                    value={formData.personal.first_name}
                    onChange={(e) => updateFormData('personal', { first_name: e.target.value })}
                    className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                    placeholder="John"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Middle Name</label>
                  <input
                    type="text"
                    value={formData.personal.middle_name}
                    onChange={(e) => updateFormData('personal', { middle_name: e.target.value })}
                    className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                    placeholder="Paul"
                  />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Last Name *</label>
                  <input
                    type="text"
                    value={formData.personal.last_name}
                    onChange={(e) => updateFormData('personal', { last_name: e.target.value })}
                    className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                    placeholder="Doe"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Preferred Name</label>
                  <input
                    type="text"
                    value={formData.personal.preferred_name}
                    onChange={(e) => updateFormData('personal', { preferred_name: e.target.value })}
                    className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                    placeholder="Johnny"
                  />
                </div>
              </div>
              <div>
                <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Gender</label>
                <div className="flex flex-wrap gap-2">
                  {[
                    { value: 'male', label: 'Male' },
                    { value: 'female', label: 'Female' },
                    { value: 'unspecified', label: 'Unspecified' },
                  ].map((option) => (
                    <button
                      key={option.value}
                      type="button"
                      onClick={() => updateFormData('personal', { gender: option.value })}
                      className={`px-4 py-2 rounded-full text-sm font-medium transition-all ${
                        formData.personal.gender === option.value
                          ? 'bg-brand-600 text-white border-brand-600'
                          : 'bg-white/5 border border-white/8 text-slate-300 hover:bg-white/10 hover:border-white/15'
                      }`}
                    >
                      {option.label}
                    </button>
                  ))}
                </div>
              </div>
              <div>
                <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Date of Birth</label>
                <input
                  type="date"
                  value={formData.personal.date_of_birth}
                  onChange={(e) => updateFormData('personal', { date_of_birth: e.target.value })}
                  className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all [color-scheme:dark]"
                />
              </div>
              <div className="col-span-2">
                <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Nationality</label>
                <input
                  type="text"
                  value={formData.personal.nationality}
                  onChange={(e) => updateFormData('personal', { nationality: e.target.value })}
                  className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                  placeholder="UG"
                />
              </div>
            </div>
          </div>
        );

      case 2:
        return (
          <div className="px-10 pt-8 pb-10 flex flex-col items-center text-center">
            <h2 className="text-3xl font-bold text-white mb-1 tracking-tight">Contact Info</h2>
            <p className="text-slate-400 text-sm mb-10">Help us stay connected with you for mission updates and coordination</p>

            <div className="space-y-4 w-full max-w-md">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Phone</label>
                  <input
                    type="text"
                    value={formData.contact_residence.contact.phone}
                    onChange={(e) => updateFormData('contact_residence', { contact: { ...formData.contact_residence.contact, phone: e.target.value } })}
                    className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                    placeholder="+256700123456"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">WhatsApp</label>
                  <input
                    type="text"
                    value={formData.contact_residence.contact.whatsapp_phone}
                    onChange={(e) => updateFormData('contact_residence', { contact: { ...formData.contact_residence.contact, whatsapp_phone: e.target.value } })}
                    className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                    placeholder="+256700123456"
                  />
                </div>
              </div>
              <div>
                <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Email</label>
                <input
                  type="email"
                  value={formData.contact_residence.contact.email}
                  onChange={(e) => updateFormData('contact_residence', { contact: { ...formData.contact_residence.contact, email: e.target.value } })}
                  className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                  placeholder="john.doe@example.com"
                />
              </div>
              <div>
                <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Occupation</label>
                <input
                  type="text"
                  value={formData.contact_residence.contact.occupation}
                  onChange={(e) => updateFormData('contact_residence', { contact: { ...formData.contact_residence.contact, occupation: e.target.value } })}
                  className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                  placeholder="Teacher"
                />
              </div>
            </div>
          </div>
        );

      case 3:
        return (
          <div className="px-10 pt-8 pb-10 flex flex-col items-center text-center">
            <h2 className="text-3xl font-bold text-white mb-1 tracking-tight">Emergency Contact</h2>
            <p className="text-slate-400 text-sm mb-10">A trusted person to contact in case of emergency during mission assignments</p>

            <div className="space-y-4 w-full max-w-md">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Name</label>
                  <input
                    type="text"
                    value={formData.contact_residence.contact.emergency_contact_name}
                    onChange={(e) => updateFormData('contact_residence', { contact: { ...formData.contact_residence.contact, emergency_contact_name: e.target.value } })}
                    className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                    placeholder="Jane Doe"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Relationship</label>
                  <input
                    type="text"
                    value={formData.contact_residence.contact.emergency_contact_relationship}
                    onChange={(e) => updateFormData('contact_residence', { contact: { ...formData.contact_residence.contact, emergency_contact_relationship: e.target.value } })}
                    className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                    placeholder="Spouse"
                  />
                </div>
              </div>
              <div>
                <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Phone</label>
                <input
                  type="text"
                  value={formData.contact_residence.contact.emergency_contact_phone}
                  onChange={(e) => updateFormData('contact_residence', { contact: { ...formData.contact_residence.contact, emergency_contact_phone: e.target.value } })}
                  className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                  placeholder="+256700789012"
                />
              </div>
            </div>
          </div>
        );

      case 4:
        return (
          <div className="px-10 pt-8 pb-10 flex flex-col items-center text-center">
            <h2 className="text-3xl font-bold text-white mb-1 tracking-tight">Residence</h2>
            <p className="text-slate-400 text-sm mb-10">Your location helps us assign you to mission sites near you</p>

            <div className="space-y-4 w-full max-w-md">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">District</label>
                  <input
                    type="text"
                    value={formData.contact_residence.address.district}
                    onChange={(e) => updateFormData('contact_residence', { address: { ...formData.contact_residence.address, district: e.target.value } })}
                    className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                    placeholder="Wakiso"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Parish/Town</label>
                  <input
                    type="text"
                    value={formData.contact_residence.address.parish_town}
                    onChange={(e) => updateFormData('contact_residence', { address: { ...formData.contact_residence.address, parish_town: e.target.value } })}
                    className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                    placeholder="Nansana Town"
                  />
                </div>
              </div>
              <div>
                <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Physical Address</label>
                <textarea
                  value={formData.contact_residence.address.physical_address}
                  onChange={(e) => updateFormData('contact_residence', { address: { ...formData.contact_residence.address, physical_address: e.target.value } })}
                  className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm leading-relaxed focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all resize-none min-h-[80px]"
                  placeholder="Plot 123, Kawala Road"
                />
              </div>
            </div>
          </div>
        );

      case 5:
        return (
          <div className="px-10 pt-8 pb-10 flex flex-col items-center text-center">
            <h2 className="text-3xl font-bold text-white mb-1 tracking-tight">Church Affiliation</h2>
            <p className="text-slate-400 text-sm mb-10">Your home church</p>

            <div className="space-y-4 w-full max-w-md">
              <CascadingOrgHierarchyPicker
                onSelect={(church) => {
                  setSelectedChurch(church);
                  updateFormData('church_affiliation', { local_church_id: church.id });
                }}
                selectedId={formData.church_affiliation.local_church_id}
              />
            </div>
          </div>
        );

      case 6:
        return (
          <div className="px-10 pt-8 pb-10 flex flex-col items-center text-center">
            <h2 className="text-3xl font-bold text-white mb-1 tracking-tight">Skills</h2>
            <p className="text-slate-400 text-sm mb-10">Your skills help us match you with the right mission opportunities</p>

            <div className="space-y-6 w-full max-w-md">
              {addedSkills.length > 0 && (
                <div className="space-y-2">
                  {addedSkills.map((skill, idx) => (
                    <div key={idx} className="flex items-center justify-between bg-white/5 rounded-xl px-4 py-3">
                      <span className="text-sm text-slate-200">{skill.category}: {skill.name} ({skill.proficiency})</span>
                      <button
                        type="button"
                        onClick={() => {
                          setAddedSkills(prev => prev.filter((_, i) => i !== idx));
                        }}
                        className="text-slate-500 hover:text-red-400 transition-colors"
                      >
                        <X size={16} />
                      </button>
                    </div>
                  ))}
                </div>
              )}

              <div>
                <h3 className="text-sm font-semibold text-slate-300 mb-3 text-left">Select skill categories</h3>
                <div className="flex flex-wrap gap-2 mb-4">
                  {Array.isArray(availableSkillCategories) && availableSkillCategories.map((cat) => {
                    const isSelected = addedSkills.some(s => s.category === cat.label);
                    return (
                      <button
                        key={cat.code}
                        type="button"
                        onClick={async () => {
                          if (isSelected) {
                            setAddedSkills(prev => prev.filter(s => s.category !== cat.label));
                          } else {
                            setSkillInput({ category: cat.label, name: '', proficiency: 'intermediate', years: '' });
                            setShowCustomSkillInput(true);
                          }
                        }}
                        className={`px-4 py-2 rounded-full text-sm transition-all ${
                          isSelected
                            ? 'bg-brand-500 text-white'
                            : 'bg-white/5 border border-white/8 text-slate-300 hover:bg-white/10'
                        }`}
                      >
                        {cat.label}
                      </button>
                    );
                  })}
                </div>

                {!showCustomSkillInput && (
                  <button
                    type="button"
                    onClick={() => setShowCustomSkillInput(true)}
                    className="text-xs text-brand-400 hover:text-brand-300"
                  >
                    + Add custom skill
                  </button>
                )}

                {showCustomSkillInput && (
                  <div className="space-y-3 mt-4">
                    <div className="grid grid-cols-2 gap-3">
                      <input
                        type="text"
                        placeholder="Category (e.g., Music)"
                        value={skillInput.category}
                        onChange={(e) => setSkillInput(prev => ({ ...prev, category: e.target.value }))}
                        className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                      />
                      <input
                        type="text"
                        placeholder="Skill name (e.g., Piano)"
                        value={skillInput.name}
                        onChange={(e) => setSkillInput(prev => ({ ...prev, name: e.target.value }))}
                        className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                      />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                      <select
                        value={skillInput.proficiency}
                        onChange={(e) => setSkillInput(prev => ({ ...prev, proficiency: e.target.value }))}
                        className="w-full bg-white/5 border border-white/8 rounded-full px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                      >
                        <option value="">Proficiency...</option>
                        <option value="beginner">Beginner</option>
                        <option value="intermediate">Intermediate</option>
                        <option value="advanced">Advanced</option>
                        <option value="expert">Expert</option>
                      </select>
                      <input
                        type="number"
                        placeholder="Years of experience"
                        value={skillInput.years}
                        onChange={(e) => setSkillInput(prev => ({ ...prev, years: e.target.value }))}
                        className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                      />
                    </div>
                    <div className="flex gap-2">
                      <button
                        type="button"
                        onClick={async () => {
                          if (skillInput.category && skillInput.name && skillInput.proficiency) {
                            try {
                              await api.post('/workspace/my-skills', {
                                skill_category: skillInput.category,
                                skill_name: skillInput.name,
                                proficiency_level: skillInput.proficiency,
                                years_experience: skillInput.years ? parseInt(skillInput.years) : null,
                              });
                              setAddedSkills(prev => [...prev, { ...skillInput }]);
                              setSkillInput({ category: '', name: '', proficiency: '', years: '' });
                              setShowCustomSkillInput(false);
                            } catch (err) {
                              console.error('Failed to add skill:', err);
                            }
                          }
                        }}
                        className="text-xs text-brand-400 hover:text-brand-300"
                      >
                        + Add Skill
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                          setShowCustomSkillInput(false);
                          setSkillInput({ category: '', name: '', proficiency: '', years: '' });
                        }}
                        className="text-xs text-slate-500 hover:text-slate-400"
                      >
                        Cancel
                      </button>
                    </div>
                  </div>
                )}
              </div>
            </div>
          </div>
        );

      case 7:
        return (
          <div className="px-10 pt-8 pb-10 flex flex-col items-center text-center">
            <h2 className="text-3xl font-bold text-white mb-1 tracking-tight">Languages</h2>
            <p className="text-slate-400 text-sm mb-10">Your language abilities help us assign you to appropriate mission areas</p>

            <div className="space-y-6 w-full max-w-md">
              {addedLanguages.length > 0 && (
                <div className="space-y-2">
                  {addedLanguages.map((lang, idx) => (
                    <div key={idx} className="flex items-center justify-between bg-white/5 rounded-xl px-4 py-3">
                      <span className="text-sm text-slate-200">{lang.name} - {lang.proficiency}</span>
                      <button
                        type="button"
                        onClick={() => {
                          setAddedLanguages(prev => prev.filter((_, i) => i !== idx));
                        }}
                        className="text-slate-500 hover:text-red-400 transition-colors"
                      >
                        <X size={16} />
                      </button>
                    </div>
                  ))}
                </div>
              )}

              <div>
                <h3 className="text-sm font-semibold text-slate-300 mb-3 text-left">Select languages you speak</h3>
                <div className="flex flex-wrap gap-2 mb-4">
                  {Array.isArray(availableLanguages) && availableLanguages.map((lang) => {
                    const isSelected = addedLanguages.some(l => l.name === lang.name);
                    return (
                      <button
                        key={lang.code}
                        type="button"
                        onClick={async () => {
                          if (isSelected) {
                            setAddedLanguages(prev => prev.filter(l => l.name !== lang.name));
                          } else {
                            try {
                              await api.post('/workspace/my-languages', {
                                language_name: lang.name,
                                proficiency_level: 'conversational',
                              });
                              setAddedLanguages(prev => [...prev, { name: lang.name, proficiency: 'conversational' }]);
                            } catch (err) {
                              console.error('Failed to add language:', err);
                            }
                          }
                        }}
                        className={`px-4 py-2 rounded-full text-sm transition-all ${
                          isSelected
                            ? 'bg-brand-500 text-white'
                            : 'bg-white/5 border border-white/8 text-slate-300 hover:bg-white/10'
                        }`}
                      >
                        {lang.name}
                      </button>
                    );
                  })}
                </div>

                {!showCustomInput && (
                  <button
                    type="button"
                    onClick={() => setShowCustomInput(true)}
                    className="text-xs text-brand-400 hover:text-brand-300"
                  >
                    + Add custom language
                  </button>
                )}

                {showCustomInput && (
                  <div className="space-y-3 mt-4">
                    <div className="grid grid-cols-2 gap-3">
                      <input
                        type="text"
                        placeholder="Language name (e.g., Luganda, Lango)"
                        value={languageInput.name}
                        onChange={(e) => setLanguageInput(prev => ({ ...prev, name: e.target.value }))}
                        className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                      />
                      <select
                        value={languageInput.proficiency}
                        onChange={(e) => setLanguageInput(prev => ({ ...prev, proficiency: e.target.value }))}
                        className="w-full bg-white/5 border border-white/8 rounded-full px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                      >
                        <option value="">Proficiency...</option>
                        <option value="basic">Basic</option>
                        <option value="conversational">Conversational</option>
                        <option value="fluent">Fluent</option>
                        <option value="native">Native</option>
                      </select>
                    </div>
                    <div className="flex gap-2">
                      <button
                        type="button"
                        onClick={async () => {
                          if (languageInput.name && languageInput.proficiency) {
                            try {
                              await api.post('/workspace/my-languages', {
                                language_name: languageInput.name,
                                proficiency_level: languageInput.proficiency,
                              });
                              setAddedLanguages(prev => [...prev, { name: languageInput.name, proficiency: languageInput.proficiency }]);
                              setLanguageInput({ name: '', proficiency: '' });
                              setShowCustomInput(false);
                            } catch (err) {
                              console.error('Failed to add language:', err);
                            }
                          }
                        }}
                        className="text-xs text-brand-400 hover:text-brand-300"
                      >
                        + Add Language
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                          setShowCustomInput(false);
                          setLanguageInput({ name: '', proficiency: '' });
                        }}
                        className="text-xs text-slate-500 hover:text-slate-400"
                      >
                        Cancel
                      </button>
                    </div>
                  </div>
                )}
              </div>
            </div>
          </div>
        );

      case 8:
        return (
          <div className="px-10 pt-8 pb-10 flex flex-col items-center text-center">
            <h2 className="text-3xl font-bold text-white mb-1 tracking-tight">Mission Experience</h2>
            <p className="text-slate-400 text-sm mb-10">Your previous mission experience helps us understand your background</p>

            <div className="space-y-6 w-full max-w-md">
              <div>
                <h3 className="text-sm font-semibold text-slate-300 mb-3 text-left">Add previous mission experience</h3>
                <div className="space-y-3">
                <input
                  type="text"
                  placeholder="Mission name"
                  className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                />
                <input
                  type="text"
                  placeholder="Location"
                  className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                />
                <button type="button" className="text-xs text-brand-400 hover:text-brand-300">
                  + Add Experience
                </button>
                </div>
              </div>
            </div>
          </div>
        );

      case 9:
        return (
          <div className="px-10 pt-8 pb-10 flex flex-col items-center text-center">
            <h2 className="text-3xl font-bold text-white mb-1 tracking-tight">Readiness</h2>
            <p className="text-slate-400 text-sm mb-10">Training and availability help us plan mission deployments effectively</p>

            <div className="space-y-6 w-full max-w-md">
              <div>
                <h3 className="text-sm font-semibold text-slate-300 mb-3 text-left">Training</h3>
                <div className="space-y-3">
                <div className="grid grid-cols-2 gap-3">
                  <select className="w-full bg-white/5 border border-white/8 rounded-full px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all">
                    <option value="">Training type...</option>
                    <option value="mission">Mission</option>
                    <option value="safeguarding">Safeguarding</option>
                    <option value="first_aid">First Aid</option>
                    <option value="other">Other</option>
                  </select>
                  <input
                    type="text"
                    placeholder="Training name"
                    className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                  />
                </div>
                <div className="grid grid-cols-2 gap-3">
                  <input
                    type="date"
                    placeholder="Completed on"
                    className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all [color-scheme:dark]"
                  />
                  <input
                    type="date"
                    placeholder="Expires on"
                    className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all [color-scheme:dark]"
                  />
                </div>
                <button type="button" className="text-xs text-brand-400 hover:text-brand-300">
                  + Add Training
                </button>
                </div>
              </div>

              <div>
                <h3 className="text-sm font-semibold text-slate-300 mb-3 text-left">Availability & Notes</h3>
                <div className="space-y-3">
                <div>
                  <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Availability</label>
                  <input
                    type="text"
                    value={formData.readiness.readiness_notes}
                    onChange={(e) => updateFormData('readiness', { readiness_notes: e.target.value })}
                    className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                    placeholder="Available July 2026, 2-3 weeks"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Additional Notes</label>
                  <textarea
                    className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm leading-relaxed focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all resize-none min-h-[80px]"
                    placeholder="Any accessibility requirements, dietary restrictions, etc."
                  />
                </div>
                </div>
              </div>
            </div>
          </div>
        );

      default:
        return null;
    }
  };

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
        onClick={onCancel}
      />

      {/* panel */}
      <div className="relative">
        <div
          className="relative bg-slate-950/80 backdrop-blur-3xl rounded-[2rem] shadow-2xl shadow-black/70 overflow-hidden my-4 sm:my-0"
          style={{ boxShadow: '0 32px 80px -12px rgba(0,0,0,0.8), inset 0 1px 0 rgba(255,255,255,0.04)' }}
        >
          {/* close */}
          <button
            onClick={onCancel}
            className="absolute top-5 right-5 z-10 w-9 h-9 rounded-full bg-white/8 border border-white/10 flex items-center justify-center text-slate-400 hover:text-slate-100 hover:bg-white/15 transition-all"
          >
            <X className="w-4 h-4" />
          </button>

          {/* content area */}
          <div
            className={`transition-all duration-200 ease-in-out ${contentClass}`}
            style={{ minHeight: '420px' }}
          >
            {error && (
              <div className="px-10 pt-8">
                <div className="p-3 bg-red-500/10 border border-red-500/30 rounded-lg text-red-400 text-sm">
                  {error}
                </div>
              </div>
            )}
            {renderStep()}
          </div>

          {/* navigation */}
          <div className="px-10 pb-10 flex items-center justify-center gap-4">
            {step > 1 ? (
              <button
                type="button"
                onClick={handleBack}
                disabled={animating}
                className="px-8 py-3.5 rounded-2xl bg-white/5 text-slate-300 text-sm font-semibold hover:bg-white/10 transition-all"
              >
                Back
              </button>
            ) : (
              <button
                type="button"
                onClick={onCancel}
                className="px-8 py-3.5 rounded-2xl bg-white/5 text-slate-300 text-sm font-semibold hover:bg-white/10 transition-all"
              >
                Cancel
              </button>
            )}

            {step < 7 ? (
              <button
                type="button"
                onClick={handleNext}
                disabled={animating}
                className="px-10 py-3.5 rounded-2xl bg-brand-600 text-white text-sm font-semibold hover:bg-brand-500 disabled:opacity-40 disabled:cursor-not-allowed transition-all flex items-center gap-2 shadow-lg shadow-brand-600/30"
              >
                Continue
                <ChevronRight className="w-4 h-4" />
              </button>
            ) : (
              <button
                type="button"
                onClick={handleSubmit}
                disabled={loading || animating}
                className="px-10 py-3.5 rounded-2xl bg-brand-600 text-white text-sm font-semibold hover:bg-brand-500 disabled:opacity-40 disabled:cursor-not-allowed transition-all flex items-center gap-2 shadow-lg shadow-brand-600/30"
              >
                {loading ? (
                  <>
                    <span className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                    Registering…
                  </>
                ) : (
                  <>
                    <Check className="w-4 h-4" />
                    Complete Registration
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
