'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { User, MapPin, Church, Briefcase, Shield, Check, Globe, Eye, EyeOff, AlertCircle } from 'lucide-react';
import Link from 'next/link';
import OrgHierarchyPicker from '@/components/OrgHierarchyPicker';

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
    membership_type: string;
    membership_number: string;
    baptism_date: string;
    baptism_location: string;
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
  account: {
    email: string;
    password: string;
    password_confirmation: string;
  };
}

const steps = [
  { id: 1, title: 'Personal Details', icon: User },
  { id: 2, title: 'Contact & Residence', icon: MapPin },
  { id: 3, title: 'Church Affiliation', icon: Church },
  { id: 4, title: 'Ministry Profile', icon: Briefcase },
  { id: 5, title: 'Readiness', icon: Shield },
  { id: 6, title: 'Create Account', icon: Globe },
];

export default function RegisterPage() {
  const router = useRouter();
  const [currentStep, setCurrentStep] = useState(1);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);
  const [verificationToken, setVerificationToken] = useState<string | null>(null);
  const [selectedChurch, setSelectedChurch] = useState<any>(null);
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);

  const [formData, setFormData] = useState<FormData>({
    personal: {
      first_name: '',
      middle_name: '',
      last_name: '',
      preferred_name: '',
      gender: '',
      date_of_birth: '',
      nationality: 'UG',
    },
    contact_residence: {
      contact: {
        phone: '',
        whatsapp_phone: '',
        alternative_phone: '',
        email: '',
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
      membership_type: 'member',
      membership_number: '',
      baptism_date: '',
      baptism_location: '',
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
    account: {
      email: '',
      password: '',
      password_confirmation: '',
    },
  });

  const updateFormData = (section: keyof FormData, data: any) => {
    setFormData(prev => ({
      ...prev,
      [section]: { ...prev[section], ...data },
    }));
  };

  const handleNext = () => {
    if (currentStep < 6) {
      setCurrentStep(currentStep + 1);
    }
  };

  const handleBack = () => {
    if (currentStep > 1) {
      setCurrentStep(currentStep - 1);
    }
  };

  const handleSubmit = async () => {
    setLoading(true);
    setError(null);

    try {
      const response = await fetch('http://localhost:8000/api/v1/auth/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData),
      });

      const result = await response.json();

      if (!response.ok) {
        throw new Error(result.message || 'Registration failed');
      }

      setSuccess(true);
      setVerificationToken(result.verification_token);
    } catch (err: any) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const handleVerifyEmail = async () => {
    if (!verificationToken) return;

    setLoading(true);
    setError(null);

    try {
      const response = await fetch('http://localhost:8000/api/v1/auth/verify-email', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: verificationToken }),
      });

      const result = await response.json();

      if (!response.ok) {
        throw new Error(result.message || 'Verification failed');
      }

      router.push('/login');
    } catch (err: any) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  if (success) {
    return (
      <div className="min-h-screen bg-surface flex flex-col items-center justify-center p-8">
        <div className="absolute inset-0 overflow-hidden pointer-events-none">
          <div className="absolute -top-40 left-1/2 -translate-x-1/2 w-[600px] h-[600px] bg-brand-600/10 rounded-full blur-3xl" />
        </div>

        <div className="relative w-full max-w-md animate-fade-up">
          <div className="card p-12 text-center">
            <div className="w-20 h-20 bg-green-500/20 rounded-full flex items-center justify-center mx-auto mb-8">
              <Check className="w-10 h-10 text-green-400" />
            </div>
            <h2 className="text-2xl font-semibold text-slate-100 mb-3">Registration Successful</h2>
            <p className="text-slate-400 mb-8 leading-relaxed">
              Please verify your email to activate your account. A verification link has been sent to {formData.account.email}.
            </p>
            {verificationToken && (
              <button
                onClick={handleVerifyEmail}
                disabled={loading}
                className="btn-primary w-full"
              >
                {loading ? 'Verifying...' : 'Verify Email (Testing)'}
              </button>
            )}
          </div>
        </div>
      </div>
    );
  }

  const renderStep = () => {
    switch (currentStep) {
      case 1:
        return (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-medium text-slate-400 mb-2">First Name *</label>
                <input
                  type="text"
                  value={formData.personal.first_name}
                  onChange={(e) => updateFormData('personal', { first_name: e.target.value })}
                  className="input"
                  placeholder="John"
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-slate-400 mb-2">Middle Name</label>
                <input
                  type="text"
                  value={formData.personal.middle_name}
                  onChange={(e) => updateFormData('personal', { middle_name: e.target.value })}
                  className="input"
                  placeholder="Paul"
                />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-medium text-slate-400 mb-2">Last Name *</label>
                <input
                  type="text"
                  value={formData.personal.last_name}
                  onChange={(e) => updateFormData('personal', { last_name: e.target.value })}
                  className="input"
                  placeholder="Doe"
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-slate-400 mb-2">Preferred Name</label>
                <input
                  type="text"
                  value={formData.personal.preferred_name}
                  onChange={(e) => updateFormData('personal', { preferred_name: e.target.value })}
                  className="input"
                  placeholder="Johnny"
                />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-medium text-slate-400 mb-2">Gender</label>
                <select
                  value={formData.personal.gender}
                  onChange={(e) => updateFormData('personal', { gender: e.target.value })}
                  className="input"
                >
                  <option value="">Select...</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                  <option value="unspecified">Unspecified</option>
                </select>
              </div>
              <div>
                <label className="block text-xs font-medium text-slate-400 mb-2">Date of Birth</label>
                <input
                  type="date"
                  value={formData.personal.date_of_birth}
                  onChange={(e) => updateFormData('personal', { date_of_birth: e.target.value })}
                  className="input"
                />
              </div>
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-2">Nationality</label>
              <input
                type="text"
                value={formData.personal.nationality}
                onChange={(e) => updateFormData('personal', { nationality: e.target.value })}
                className="input"
                placeholder="UG"
              />
            </div>
          </div>
        );

      case 2:
        return (
          <div className="space-y-6">
            <div>
              <h3 className="text-sm font-medium text-slate-300 mb-3">Contact Information</h3>
              <div className="space-y-3">
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-2">Phone</label>
                    <input
                      type="text"
                      value={formData.contact_residence.contact.phone}
                      onChange={(e) => updateFormData('contact_residence', { contact: { ...formData.contact_residence.contact, phone: e.target.value } })}
                      className="input"
                      placeholder="+256700123456"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-2">WhatsApp</label>
                    <input
                      type="text"
                      value={formData.contact_residence.contact.whatsapp_phone}
                      onChange={(e) => updateFormData('contact_residence', { contact: { ...formData.contact_residence.contact, whatsapp_phone: e.target.value } })}
                      className="input"
                      placeholder="+256700123456"
                    />
                  </div>
                </div>
                <div>
                  <label className="block text-xs font-medium text-slate-400 mb-2">Email</label>
                  <input
                    type="email"
                    value={formData.contact_residence.contact.email}
                    onChange={(e) => updateFormData('contact_residence', { contact: { ...formData.contact_residence.contact, email: e.target.value } })}
                    className="input"
                    placeholder="john.doe@example.com"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-slate-400 mb-2">Occupation</label>
                  <input
                    type="text"
                    value={formData.contact_residence.contact.occupation}
                    onChange={(e) => updateFormData('contact_residence', { contact: { ...formData.contact_residence.contact, occupation: e.target.value } })}
                    className="input"
                    placeholder="Teacher"
                  />
                </div>
              </div>
            </div>

            <div>
              <h3 className="text-sm font-medium text-slate-300 mb-3">Emergency Contact</h3>
              <div className="space-y-3">
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-2">Name</label>
                    <input
                      type="text"
                      value={formData.contact_residence.contact.emergency_contact_name}
                      onChange={(e) => updateFormData('contact_residence', { contact: { ...formData.contact_residence.contact, emergency_contact_name: e.target.value } })}
                      className="input"
                      placeholder="Jane Doe"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-2">Relationship</label>
                    <input
                      type="text"
                      value={formData.contact_residence.contact.emergency_contact_relationship}
                      onChange={(e) => updateFormData('contact_residence', { contact: { ...formData.contact_residence.contact, emergency_contact_relationship: e.target.value } })}
                      className="input"
                      placeholder="Spouse"
                    />
                  </div>
                </div>
                <div>
                  <label className="block text-xs font-medium text-slate-400 mb-2">Phone</label>
                  <input
                    type="text"
                    value={formData.contact_residence.contact.emergency_contact_phone}
                    onChange={(e) => updateFormData('contact_residence', { contact: { ...formData.contact_residence.contact, emergency_contact_phone: e.target.value } })}
                    className="input"
                    placeholder="+256700789012"
                  />
                </div>
              </div>
            </div>

            <div>
              <h3 className="text-sm font-medium text-slate-300 mb-3">Residence</h3>
              <div className="space-y-3">
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-2">District</label>
                    <input
                      type="text"
                      value={formData.contact_residence.address.district}
                      onChange={(e) => updateFormData('contact_residence', { address: { ...formData.contact_residence.address, district: e.target.value } })}
                      className="input"
                      placeholder="Wakiso"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-2">Parish/Town</label>
                    <input
                      type="text"
                      value={formData.contact_residence.address.parish_town}
                      onChange={(e) => updateFormData('contact_residence', { address: { ...formData.contact_residence.address, parish_town: e.target.value } })}
                      className="input"
                      placeholder="Nansana Town"
                    />
                  </div>
                </div>
                <div>
                  <label className="block text-xs font-medium text-slate-400 mb-2">Physical Address</label>
                  <textarea
                    value={formData.contact_residence.address.physical_address}
                    onChange={(e) => updateFormData('contact_residence', { address: { ...formData.contact_residence.address, physical_address: e.target.value } })}
                    className="input min-h-[80px]"
                    placeholder="Plot 123, Kawala Road"
                  />
                </div>
              </div>
            </div>
          </div>
        );

      case 3:
        return (
          <div className="space-y-4">
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-2">Local Church</label>
              <OrgHierarchyPicker
                onSelect={(church) => {
                  setSelectedChurch(church);
                  updateFormData('church_affiliation', { local_church_id: church.id });
                }}
                selectedId={formData.church_affiliation.local_church_id || undefined}
                placeholder="Select local church (optional)..."
              />
              <p className="text-xs text-slate-500 mt-1">You can register without selecting a church.</p>
            </div>
            {selectedChurch && (
              <>
                <div>
                  <label className="block text-xs font-medium text-slate-400 mb-2">Membership Type</label>
                  <select
                    value={formData.church_affiliation.membership_type}
                    onChange={(e) => updateFormData('church_affiliation', { membership_type: e.target.value })}
                    className="input"
                  >
                    <option value="member">Member</option>
                    <option value="associate">Associate</option>
                    <option value="partner">Partner</option>
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-medium text-slate-400 mb-2">Membership Number</label>
                  <input
                    type="text"
                    value={formData.church_affiliation.membership_number}
                    onChange={(e) => updateFormData('church_affiliation', { membership_number: e.target.value })}
                    className="input"
                    placeholder="UG-CUC-EKZ-KTD-001"
                  />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-2">Baptism Date</label>
                    <input
                      type="date"
                      value={formData.church_affiliation.baptism_date}
                      onChange={(e) => updateFormData('church_affiliation', { baptism_date: e.target.value })}
                      className="input"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-slate-400 mb-2">Baptism Location</label>
                    <input
                      type="text"
                      value={formData.church_affiliation.baptism_location}
                      onChange={(e) => updateFormData('church_affiliation', { baptism_location: e.target.value })}
                      className="input"
                      placeholder="Kampala Central SDA Church"
                    />
                  </div>
                </div>
              </>
            )}
          </div>
        );

      case 4:
        return (
          <div className="space-y-6">
            <div>
              <h3 className="text-sm font-medium text-slate-300 mb-3">Skills (Optional)</h3>
              <p className="text-xs text-slate-500 mb-3">Add skills relevant to mission work</p>
              <div className="space-y-3">
                {formData.ministry_profile.skills.map((skill, index) => (
                  <div key={index} className="grid grid-cols-2 gap-3 p-3 bg-slate-800 rounded-lg">
                    <input
                      type="text"
                      placeholder="Skill category (e.g., music)"
                      value={skill.skill_category}
                      onChange={(e) => {
                        const newSkills = [...formData.ministry_profile.skills];
                        newSkills[index] = { ...newSkills[index], skill_category: e.target.value };
                        updateFormData('ministry_profile', { skills: newSkills });
                      }}
                      className="input"
                    />
                    <input
                      type="text"
                      placeholder="Skill name (e.g., Piano)"
                      value={skill.skill_name}
                      onChange={(e) => {
                        const newSkills = [...formData.ministry_profile.skills];
                        newSkills[index] = { ...newSkills[index], skill_name: e.target.value };
                        updateFormData('ministry_profile', { skills: newSkills });
                      }}
                      className="input"
                    />
                    <select
                      value={skill.proficiency_level || ''}
                      onChange={(e) => {
                        const newSkills = [...formData.ministry_profile.skills];
                        newSkills[index] = { ...newSkills[index], proficiency_level: e.target.value };
                        updateFormData('ministry_profile', { skills: newSkills });
                      }}
                      className="input"
                    >
                      <option value="">Proficiency...</option>
                      <option value="beginner">Beginner</option>
                      <option value="intermediate">Intermediate</option>
                      <option value="advanced">Advanced</option>
                      <option value="expert">Expert</option>
                    </select>
                    <input
                      type="number"
                      placeholder="Years experience"
                      value={skill.years_experience || ''}
                      onChange={(e) => {
                        const newSkills = [...formData.ministry_profile.skills];
                        newSkills[index] = { ...newSkills[index], years_experience: parseInt(e.target.value) || 0 };
                        updateFormData('ministry_profile', { skills: newSkills });
                      }}
                      className="input"
                    />
                    <button
                      type="button"
                      onClick={() => {
                        const newSkills = formData.ministry_profile.skills.filter((_, i) => i !== index);
                        updateFormData('ministry_profile', { skills: newSkills });
                      }}
                      className="text-xs text-red-400 hover:text-red-300 col-span-2"
                    >
                      Remove
                    </button>
                  </div>
                ))}
                <button
                  type="button"
                  onClick={() => {
                    updateFormData('ministry_profile', {
                      skills: [...formData.ministry_profile.skills, { skill_category: '', skill_name: '', proficiency_level: '', years_experience: 0 }]
                    });
                  }}
                  className="text-xs text-brand-400 hover:text-brand-300"
                >
                  + Add Skill
                </button>
              </div>
            </div>

            <div>
              <h3 className="text-sm font-medium text-slate-300 mb-3">Languages (Optional)</h3>
              <div className="space-y-3">
                {formData.ministry_profile.languages.map((lang, index) => (
                  <div key={index} className="grid grid-cols-2 gap-3 p-3 bg-slate-800 rounded-lg">
                    <input
                      type="text"
                      placeholder="Language code (e.g., en, lg)"
                      value={lang.language_code}
                      onChange={(e) => {
                        const newLanguages = [...formData.ministry_profile.languages];
                        newLanguages[index] = { ...newLanguages[index], language_code: e.target.value };
                        updateFormData('ministry_profile', { languages: newLanguages });
                      }}
                      className="input"
                    />
                    <select
                      value={lang.proficiency_level || ''}
                      onChange={(e) => {
                        const newLanguages = [...formData.ministry_profile.languages];
                        newLanguages[index] = { ...newLanguages[index], proficiency_level: e.target.value };
                        updateFormData('ministry_profile', { languages: newLanguages });
                      }}
                      className="input"
                    >
                      <option value="">Proficiency...</option>
                      <option value="basic">Basic</option>
                      <option value="conversational">Conversational</option>
                      <option value="fluent">Fluent</option>
                      <option value="native">Native</option>
                    </select>
                    <label className="flex items-center gap-2 col-span-2">
                      <input
                        type="checkbox"
                        checked={lang.is_native}
                        onChange={(e) => {
                          const newLanguages = [...formData.ministry_profile.languages];
                          newLanguages[index] = { ...newLanguages[index], is_native: e.target.checked };
                          updateFormData('ministry_profile', { languages: newLanguages });
                        }}
                        className="w-4 h-4 rounded border-slate-600 bg-slate-700 text-brand-600"
                      />
                      <span className="text-xs text-slate-400">Native language</span>
                    </label>
                    <button
                      type="button"
                      onClick={() => {
                        const newLanguages = formData.ministry_profile.languages.filter((_, i) => i !== index);
                        updateFormData('ministry_profile', { languages: newLanguages });
                      }}
                      className="text-xs text-red-400 hover:text-red-300 col-span-2"
                    >
                      Remove
                    </button>
                  </div>
                ))}
                <button
                  type="button"
                  onClick={() => {
                    updateFormData('ministry_profile', {
                      languages: [...formData.ministry_profile.languages, { language_code: '', is_native: false, proficiency_level: '' }]
                    });
                  }}
                  className="text-xs text-brand-400 hover:text-brand-300"
                >
                  + Add Language
                </button>
              </div>
            </div>

            <div>
              <h3 className="text-sm font-medium text-slate-300 mb-3">Previous Mission Experience (Optional)</h3>
              <div className="space-y-3">
                {formData.ministry_profile.mission_experience.map((exp, index) => (
                  <div key={index} className="space-y-3 p-3 bg-slate-800 rounded-lg">
                    <input
                      type="text"
                      placeholder="Mission name"
                      value={exp.mission_name}
                      onChange={(e) => {
                        const newExp = [...formData.ministry_profile.mission_experience];
                        newExp[index] = { ...newExp[index], mission_name: e.target.value };
                        updateFormData('ministry_profile', { mission_experience: newExp });
                      }}
                      className="input"
                    />
                    <input
                      type="text"
                      placeholder="Location"
                      value={exp.location || ''}
                      onChange={(e) => {
                        const newExp = [...formData.ministry_profile.mission_experience];
                        newExp[index] = { ...newExp[index], location: e.target.value };
                        updateFormData('ministry_profile', { mission_experience: newExp });
                      }}
                      className="input"
                    />
                    <div className="grid grid-cols-2 gap-3">
                      <input
                        type="date"
                        placeholder="Started on"
                        value={exp.started_on || ''}
                        onChange={(e) => {
                          const newExp = [...formData.ministry_profile.mission_experience];
                          newExp[index] = { ...newExp[index], started_on: e.target.value };
                          updateFormData('ministry_profile', { mission_experience: newExp });
                        }}
                        className="input"
                      />
                      <input
                        type="date"
                        placeholder="Ended on"
                        value={exp.ended_on || ''}
                        onChange={(e) => {
                          const newExp = [...formData.ministry_profile.mission_experience];
                          newExp[index] = { ...newExp[index], ended_on: e.target.value };
                          updateFormData('ministry_profile', { mission_experience: newExp });
                        }}
                        className="input"
                      />
                    </div>
                    <input
                      type="text"
                      placeholder="Role"
                      value={exp.role || ''}
                      onChange={(e) => {
                        const newExp = [...formData.ministry_profile.mission_experience];
                        newExp[index] = { ...newExp[index], role: e.target.value };
                        updateFormData('ministry_profile', { mission_experience: newExp });
                      }}
                      className="input"
                    />
                    <textarea
                      placeholder="Description"
                      value={exp.description || ''}
                      onChange={(e) => {
                        const newExp = [...formData.ministry_profile.mission_experience];
                        newExp[index] = { ...newExp[index], description: e.target.value };
                        updateFormData('ministry_profile', { mission_experience: newExp });
                      }}
                      className="input min-h-[60px]"
                    />
                    <button
                      type="button"
                      onClick={() => {
                        const newExp = formData.ministry_profile.mission_experience.filter((_, i) => i !== index);
                        updateFormData('ministry_profile', { mission_experience: newExp });
                      }}
                      className="text-xs text-red-400 hover:text-red-300"
                    >
                      Remove Experience
                    </button>
                  </div>
                ))}
                <button
                  type="button"
                  onClick={() => {
                    updateFormData('ministry_profile', {
                      mission_experience: [...formData.ministry_profile.mission_experience, { mission_name: '', location: '', started_on: '', ended_on: '', role: '', description: '' }]
                    });
                  }}
                  className="text-xs text-brand-400 hover:text-brand-300"
                >
                  + Add Experience
                </button>
              </div>
            </div>
          </div>
        );

      case 5:
        return (
          <div className="space-y-6">
            <div>
              <h3 className="text-sm font-medium text-slate-300 mb-3">Training (Optional)</h3>
              <div className="space-y-3">
                {formData.readiness.training.map((training, index) => (
                  <div key={index} className="space-y-3 p-3 bg-slate-800 rounded-lg">
                    <div className="grid grid-cols-2 gap-3">
                      <select
                        value={training.training_type}
                        onChange={(e) => {
                          const newTraining = [...formData.readiness.training];
                          newTraining[index] = { ...newTraining[index], training_type: e.target.value };
                          updateFormData('readiness', { training: newTraining });
                        }}
                        className="input"
                      >
                        <option value="">Training type...</option>
                        <option value="mission">Mission</option>
                        <option value="safeguarding">Safeguarding</option>
                        <option value="first_aid">First Aid</option>
                        <option value="other">Other</option>
                      </select>
                      <input
                        type="text"
                        placeholder="Training name"
                        value={training.training_name}
                        onChange={(e) => {
                          const newTraining = [...formData.readiness.training];
                          newTraining[index] = { ...newTraining[index], training_name: e.target.value };
                          updateFormData('readiness', { training: newTraining });
                        }}
                        className="input"
                      />
                    </div>
                    <input
                      type="text"
                      placeholder="Training provider"
                      value={training.training_provider || ''}
                      onChange={(e) => {
                        const newTraining = [...formData.readiness.training];
                        newTraining[index] = { ...newTraining[index], training_provider: e.target.value };
                        updateFormData('readiness', { training: newTraining });
                      }}
                      className="input"
                    />
                    <div className="grid grid-cols-2 gap-3">
                      <input
                        type="date"
                        placeholder="Completed on"
                        value={training.completed_on || ''}
                        onChange={(e) => {
                          const newTraining = [...formData.readiness.training];
                          newTraining[index] = { ...newTraining[index], completed_on: e.target.value };
                          updateFormData('readiness', { training: newTraining });
                        }}
                        className="input"
                      />
                      <input
                        type="date"
                        placeholder="Expires on"
                        value={training.expires_on || ''}
                        onChange={(e) => {
                          const newTraining = [...formData.readiness.training];
                          newTraining[index] = { ...newTraining[index], expires_on: e.target.value };
                          updateFormData('readiness', { training: newTraining });
                        }}
                        className="input"
                      />
                    </div>
                    <button
                      type="button"
                      onClick={() => {
                        const newTraining = formData.readiness.training.filter((_, i) => i !== index);
                        updateFormData('readiness', { training: newTraining });
                      }}
                      className="text-xs text-red-400 hover:text-red-300"
                    >
                      Remove Training
                    </button>
                  </div>
                ))}
                <button
                  type="button"
                  onClick={() => {
                    updateFormData('readiness', {
                      training: [...formData.readiness.training, { training_type: '', training_name: '', training_provider: '', completed_on: '', expires_on: '' }]
                    });
                  }}
                  className="text-xs text-brand-400 hover:text-brand-300"
                >
                  + Add Training
                </button>
              </div>
            </div>

            <div>
              <h3 className="text-sm font-medium text-slate-300 mb-3">Availability & Notes</h3>
              <div className="space-y-3">
                <div>
                  <label className="block text-xs font-medium text-slate-400 mb-2">Availability</label>
                  <input
                    type="text"
                    value={formData.readiness.readiness_notes}
                    onChange={(e) => updateFormData('readiness', { readiness_notes: e.target.value })}
                    className="input"
                    placeholder="Available July 2026, 2-3 weeks"
                  />
                </div>
              </div>
            </div>
          </div>
        );

      case 6:
        return (
          <div className="space-y-4">
            <div>
              <label className="block text-xs font-medium text-slate-400 mb-2">Email *</label>
              <input
                type="email"
                value={formData.account.email}
                onChange={(e) => updateFormData('account', { email: e.target.value })}
                className="input"
                placeholder="john.doe@example.com"
                required
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-400 mb-2">Password *</label>
              <div className="relative">
                <input
                  type={showPassword ? 'text' : 'password'}
                  value={formData.account.password}
                  onChange={(e) => updateFormData('account', { password: e.target.value })}
                  className="input pr-10"
                  placeholder="••••••••"
                  required
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300 transition-colors"
                >
                  {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
              <p className="text-xs text-slate-500 mt-1">Must be at least 8 characters with uppercase, lowercase, and numbers.</p>
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-400 mb-2">Confirm Password *</label>
              <div className="relative">
                <input
                  type={showConfirmPassword ? 'text' : 'password'}
                  value={formData.account.password_confirmation}
                  onChange={(e) => updateFormData('account', { password_confirmation: e.target.value })}
                  className="input pr-10"
                  placeholder="••••••••"
                  required
                />
                <button
                  type="button"
                  onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300 transition-colors"
                >
                  {showConfirmPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
            </div>

            <div className="pt-4">
              <label className="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" className="w-4 h-4 mt-1 rounded border-slate-600 bg-slate-700 text-brand-600 focus:ring-brand-500" required />
                <span className="text-xs text-slate-400">
                  I agree to the UU Mission terms of service and privacy policy. I understand that my church affiliation will require approval from authorized officers.
                </span>
              </label>
            </div>
          </div>
        );

      default:
        return null;
    }
  };

  return (
    <div className="min-h-screen bg-surface flex flex-col items-center justify-center p-8">
      <div className="absolute inset-0 overflow-hidden pointer-events-none">
        <div className="absolute -top-40 left-1/2 -translate-x-1/2 w-[600px] h-[600px] bg-brand-600/10 rounded-full blur-3xl" />
      </div>

      <div className="relative w-full max-w-2xl animate-fade-up">
        <div className="flex flex-col items-center gap-4 mb-8">
          <div className="w-16 h-16 rounded-2xl bg-brand-600 flex items-center justify-center shadow-lg shadow-brand-600/30">
            <Globe className="w-8 h-8 text-white" />
          </div>
          <div className="text-center">
            <h1 className="text-3xl font-bold text-slate-100 mb-2">Register with UU Mission</h1>
            <p className="text-slate-400">Create your account and complete your profile</p>
          </div>
        </div>

        <div className="card">
          {error && (
            <div className="mb-6 p-3 bg-red-500/10 border border-red-500/30 rounded-lg text-red-400 text-sm flex items-start gap-3">
              <AlertCircle className="w-5 h-5 shrink-0 mt-0.5" />
              {error}
            </div>
          )}

          {/* Step Content */}
          <div className="mb-6 min-h-[300px] px-4">
            {renderStep()}
          </div>

          {/* Navigation */}
          <div className="flex justify-between px-4">
            <button
              onClick={handleBack}
              disabled={currentStep === 1}
              className="px-4 py-2 rounded-lg border border-slate-600 text-slate-300 hover:bg-slate-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              Back
            </button>
            {currentStep < 6 ? (
              <button
                onClick={handleNext}
                className="btn-primary"
              >
                Next
              </button>
            ) : (
              <button
                onClick={handleSubmit}
                disabled={loading}
                className="btn-primary"
              >
                {loading ? 'Creating Account...' : 'Complete Registration'}
              </button>
            )}
          </div>
        </div>

        <p className="text-xs text-slate-500 text-center mt-6">
          Already have an account?{' '}
          <Link href="/login" className="text-brand-400 hover:text-brand-300 transition-colors font-medium">
            Sign In
          </Link>
        </p>
      </div>
    </div>
  );
}
