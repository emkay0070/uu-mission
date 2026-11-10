'use client';

import { useState } from 'react';
import { User, Mail, Phone, MapPin, Church, ArrowLeft, ArrowRight, Check } from 'lucide-react';

interface SimplePersonRegistrationProps {
  onSave: (result: any) => void;
  onCancel: () => void;
}

export default function SimplePersonRegistration({ onSave, onCancel }: SimplePersonRegistrationProps) {
  const [step, setStep] = useState(1);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [formData, setFormData] = useState({
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
      local_church_id: '',
      membership_type: 'member',
      membership_number: '',
      baptism_date: '',
      baptism_location: '',
    },
  });

  const updateField = (section: string, field: string, value: any) => {
    setFormData(prev => ({
      ...prev,
      [section]: {
        ...prev[section as keyof typeof prev],
        [field]: value,
      },
    }));
  };

  const updateNestedField = (section: string, subsection: string, field: string, value: any) => {
    setFormData(prev => ({
      ...prev,
      [section]: {
        ...prev[section as keyof typeof prev],
        [subsection]: {
          ...(prev[section as keyof typeof prev] as any)[subsection],
          [field]: value,
        },
      },
    }));
  };

  const handleSubmit = async () => {
    setLoading(true);
    setError(null);

    try {
      const response = await fetch('/api/v1/onboarding/register', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${localStorage.getItem('uu_token')}`,
        },
        body: JSON.stringify({ ...formData, mode: 'person' }),
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

  const goTo = (stepNum: number) => setStep(stepNum);
  const handleNext = () => goTo(step + 1);
  const handleBack = () => goTo(step - 1);

  const renderStep = () => {
    switch (step) {
      case 1:
        return (
          <div className="space-y-6">
            <h2 className="text-xl font-semibold text-slate-100">Personal Information</h2>
            
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">First Name *</label>
                <input
                  type="text"
                  value={formData.personal.first_name}
                  onChange={(e) => updateField('personal', 'first_name', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Middle Name</label>
                <input
                  type="text"
                  value={formData.personal.middle_name}
                  onChange={(e) => updateField('personal', 'middle_name', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Last Name *</label>
                <input
                  type="text"
                  value={formData.personal.last_name}
                  onChange={(e) => updateField('personal', 'last_name', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Preferred Name</label>
                <input
                  type="text"
                  value={formData.personal.preferred_name}
                  onChange={(e) => updateField('personal', 'preferred_name', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Gender</label>
                <select
                  value={formData.personal.gender}
                  onChange={(e) => updateField('personal', 'gender', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                >
                  <option value="">Select</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                  <option value="unspecified">Unspecified</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Date of Birth</label>
                <input
                  type="date"
                  value={formData.personal.date_of_birth}
                  onChange={(e) => updateField('personal', 'date_of_birth', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                />
              </div>
              <div className="md:col-span-2">
                <label className="block text-sm font-medium text-slate-300 mb-2">Nationality</label>
                <input
                  type="text"
                  value={formData.personal.nationality}
                  onChange={(e) => updateField('personal', 'nationality', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                />
              </div>
            </div>
          </div>
        );

      case 2:
        return (
          <div className="space-y-6">
            <h2 className="text-xl font-semibold text-slate-100">Contact Information</h2>
            
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Phone *</label>
                <input
                  type="tel"
                  value={formData.contact_residence.contact.phone}
                  onChange={(e) => updateNestedField('contact_residence', 'contact', 'phone', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                  placeholder="+256 XXX XXX XXX"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">WhatsApp Phone</label>
                <input
                  type="tel"
                  value={formData.contact_residence.contact.whatsapp_phone}
                  onChange={(e) => updateNestedField('contact_residence', 'contact', 'whatsapp_phone', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                  placeholder="+256 XXX XXX XXX"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Alternative Phone</label>
                <input
                  type="tel"
                  value={formData.contact_residence.contact.alternative_phone}
                  onChange={(e) => updateNestedField('contact_residence', 'contact', 'alternative_phone', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Email</label>
                <input
                  type="email"
                  value={formData.contact_residence.contact.email}
                  onChange={(e) => updateNestedField('contact_residence', 'contact', 'email', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Preferred Contact Method</label>
                <select
                  value={formData.contact_residence.contact.preferred_contact_method}
                  onChange={(e) => updateNestedField('contact_residence', 'contact', 'preferred_contact_method', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                >
                  <option value="phone">Phone</option>
                  <option value="whatsapp">WhatsApp</option>
                  <option value="email">Email</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Occupation</label>
                <input
                  type="text"
                  value={formData.contact_residence.contact.occupation}
                  onChange={(e) => updateNestedField('contact_residence', 'contact', 'occupation', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                />
              </div>
            </div>

            <h3 className="text-lg font-semibold text-slate-100 mt-6">Emergency Contact</h3>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Name</label>
                <input
                  type="text"
                  value={formData.contact_residence.contact.emergency_contact_name}
                  onChange={(e) => updateNestedField('contact_residence', 'contact', 'emergency_contact_name', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Relationship</label>
                <input
                  type="text"
                  value={formData.contact_residence.contact.emergency_contact_relationship}
                  onChange={(e) => updateNestedField('contact_residence', 'contact', 'emergency_contact_relationship', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Phone</label>
                <input
                  type="tel"
                  value={formData.contact_residence.contact.emergency_contact_phone}
                  onChange={(e) => updateNestedField('contact_residence', 'contact', 'emergency_contact_phone', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                />
              </div>
            </div>

            <h3 className="text-lg font-semibold text-slate-100 mt-6">Residence Address</h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Country</label>
                <input
                  type="text"
                  value={formData.contact_residence.address.country}
                  onChange={(e) => updateNestedField('contact_residence', 'address', 'country', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">District</label>
                <input
                  type="text"
                  value={formData.contact_residence.address.district}
                  onChange={(e) => updateNestedField('contact_residence', 'address', 'district', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Town/Parish</label>
                <input
                  type="text"
                  value={formData.contact_residence.address.parish_town}
                  onChange={(e) => updateNestedField('contact_residence', 'address', 'parish_town', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Physical Address</label>
                <input
                  type="text"
                  value={formData.contact_residence.address.physical_address}
                  onChange={(e) => updateNestedField('contact_residence', 'address', 'physical_address', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                />
              </div>
            </div>
          </div>
        );

      case 3:
        return (
          <div className="space-y-6">
            <h2 className="text-xl font-semibold text-slate-100">Church Affiliation</h2>
            
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="md:col-span-2">
                <label className="block text-sm font-medium text-slate-300 mb-2">Local Church</label>
                <input
                  type="text"
                  value={formData.church_affiliation.local_church_id}
                  onChange={(e) => updateField('church_affiliation', 'local_church_id', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                  placeholder="Enter church ID or name"
                />
                <p className="text-xs text-slate-500 mt-1">Enter the church ID from the organization hierarchy</p>
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Membership Type</label>
                <select
                  value={formData.church_affiliation.membership_type}
                  onChange={(e) => updateField('church_affiliation', 'membership_type', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                >
                  <option value="member">Member</option>
                  <option value="regular_attender">Regular Attender</option>
                  <option value="visitor">Visitor</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Membership Number</label>
                <input
                  type="text"
                  value={formData.church_affiliation.membership_number}
                  onChange={(e) => updateField('church_affiliation', 'membership_number', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Baptism Date</label>
                <input
                  type="date"
                  value={formData.church_affiliation.baptism_date}
                  onChange={(e) => updateField('church_affiliation', 'baptism_date', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Baptism Location</label>
                <input
                  type="text"
                  value={formData.church_affiliation.baptism_location}
                  onChange={(e) => updateField('church_affiliation', 'baptism_location', e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-slate-200 focus:outline-none focus:border-brand-500"
                />
              </div>
            </div>
          </div>
        );

      default:
        return null;
    }
  };

  return (
    <div className="max-w-4xl mx-auto">
      <div className="flex items-center justify-between mb-6">
        <button
          onClick={onCancel}
          className="flex items-center gap-2 text-slate-400 hover:text-slate-200 transition-colors"
        >
          <ArrowLeft className="w-4 h-4" />
          Cancel
        </button>
        <div className="flex items-center gap-2">
          {[1, 2, 3].map((i) => (
            <div
              key={i}
              className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium transition-colors ${
                i <= step ? 'bg-brand-600 text-white' : 'bg-slate-700 text-slate-400'
              }`}
            >
              {i < step ? <Check className="w-4 h-4" /> : i}
            </div>
          ))}
        </div>
      </div>

      <div className="glass-card p-6 md:p-8">
        {renderStep()}

        {error && (
          <div className="mt-6 p-4 bg-red-500/10 border border-red-500/30 rounded-lg text-red-400 text-sm">
            {error}
          </div>
        )}

        <div className="flex items-center justify-between mt-8">
          <button
            onClick={handleBack}
            disabled={step === 1 || loading}
            className="flex items-center gap-2 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <ArrowLeft className="w-4 h-4" />
            Back
          </button>
          {step < 3 ? (
            <button
              onClick={handleNext}
              disabled={loading}
              className="flex items-center gap-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              Next
              <ArrowRight className="w-4 h-4" />
            </button>
          ) : (
            <button
              onClick={handleSubmit}
              disabled={loading || !formData.personal.first_name || !formData.personal.last_name}
              className="flex items-center gap-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {loading ? 'Registering...' : 'Register Person'}
              <Check className="w-4 h-4" />
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
