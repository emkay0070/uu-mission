'use client';

import { useState, useEffect, useRef } from 'react';
import { useRouter } from 'next/navigation';
import { Users2, Plus, Search, MapPin, Phone, Calendar, User, X, Loader2, AlertCircle, Filter, ChevronRight, ChevronLeft, CheckCircle } from 'lucide-react';
import { peopleApi, workspaceApi, outreachEncountersApi, outreachContactsApi, refApi, type Person, type MyAssignment, type EncounterMethod, type OutreachContact } from '@/lib/api';

type EngagementStage = { id: number; code: string; label: string; color_key: string; sort_order: number; is_active: boolean };

export default function PeoplePage() {
  const router = useRouter();
  const [people, setPeople] = useState<OutreachContact[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState('');
  const [engagementFilter, setEngagementFilter] = useState('');
  const [showFilterDropdown, setShowFilterDropdown] = useState(false);
  const [showAddForm, setShowAddForm] = useState(false);
  const filterDropdownRef = useRef<HTMLDivElement>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isCheckingDuplicates, setIsCheckingDuplicates] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [assignment, setAssignment] = useState<MyAssignment | null>(null);
  const [engagementStages, setEngagementStages] = useState<EngagementStage[]>([]);
  const [duplicates, setDuplicates] = useState<Person[]>([]);
  const [encounterMethods, setEncounterMethods] = useState<EncounterMethod[]>([]);
  const [currentStep, setCurrentStep] = useState(1);

  const [formData, setFormData] = useState({
    first_name: '',
    last_name: '',
    gender: '',
    phone: '',
    village: '',
    date_of_birth: '',
    notes: '',
    engagement_stage: 'new_contact',
    encounter_date: new Date().toISOString().split('T')[0],
    encounter_method_id: '',
    encounter_notes: '',
  });

  const getEngagementStageColor = (colorKey: string) => {
    const colors: Record<string, string> = {
      blue: 'bg-blue-500/20 text-blue-400',
      cyan: 'bg-cyan-500/20 text-cyan-400',
      purple: 'bg-purple-500/20 text-purple-400',
      amber: 'bg-amber-500/20 text-amber-400',
      emerald: 'bg-emerald-500/20 text-emerald-400',
      green: 'bg-green-500/20 text-green-400',
      indigo: 'bg-indigo-500/20 text-indigo-400',
      slate: 'bg-slate-500/20 text-slate-400',
      red: 'bg-red-500/20 text-red-400',
    };
    return colors[colorKey] || 'bg-slate-500/20 text-slate-400';
  };

  useEffect(() => {
    loadPeople();
    loadAssignment();
    loadEncounterMethods();
    loadEngagementStages();
  }, []);

  const loadAssignment = async () => {
    try {
      const response = await workspaceApi.myAssignment();
      setAssignment(response.assignment);
    } catch (err) {
      console.error('Failed to load assignment:', err);
    }
  };

  const loadEncounterMethods = async () => {
    try {
      const methods = await refApi.encounterMethods();
      setEncounterMethods(methods);
    } catch (err) {
      console.error('Failed to load encounter methods:', err);
    }
  };

  const loadEngagementStages = async () => {
    try {
      const stages = await refApi.engagementStages();
      setEngagementStages(stages);
    } catch (err) {
      console.error('Failed to load engagement stages:', err);
    }
  };

  // Close filter dropdown when clicking outside
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (filterDropdownRef.current && !filterDropdownRef.current.contains(event.target as Node)) {
        setShowFilterDropdown(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const loadPeople = async () => {
    setIsLoading(true);
    try {
      const response = await outreachContactsApi.list({ per_page: 50 });
      setPeople(response.data);
    } catch (err: any) {
      setError(err.message || 'Failed to load outreach contacts');
    } finally {
      setIsLoading(false);
    }
  };

  const handleSearch = async () => {
    setIsLoading(true);
    try {
      const params: any = { per_page: 50 };
      if (searchQuery.trim()) {
        params.search = searchQuery;
      }
      if (engagementFilter) {
        params.engagement_stage = engagementFilter;
      }
      if (assignment?.campaign?.id) {
        params.campaign_id = assignment.campaign.id;
      }
      const response = await outreachContactsApi.list(params);
      setPeople(response.data);
    } catch (err: any) {
      setError(err.message || 'Failed to search outreach contacts');
    } finally {
      setIsLoading(false);
    }
  };

  const checkForDuplicates = async () => {
    // The duplicate endpoint requires both names, so hold off until we have
    // them. This also avoids a pointless request on every keystroke.
    const firstName = formData.first_name.trim();
    const lastName = formData.last_name.trim();

    if (!firstName || !lastName) {
      setDuplicates([]);
      return;
    }

    setIsCheckingDuplicates(true);
    try {
      // Scoped server-side to the caller's authorised campaigns/sites.
      const response = await peopleApi.checkDuplicates({
        first_name: firstName,
        last_name: lastName,
        phone: formData.phone?.trim() || undefined,
      });
      setDuplicates(response.duplicates || []);
    } catch (err) {
      console.error('Failed to check duplicates:', err);
    } finally {
      setIsCheckingDuplicates(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    setError(null);

    try {
      const contactData: any = {
        first_name: formData.first_name || null,
        last_name: formData.last_name || null,
        middle_name: null,
        preferred_name: null,
        engagement_stage: formData.engagement_stage,
        gender: formData.gender || null,
        phone: formData.phone || null,
        village: formData.village || null,
        date_of_birth: formData.date_of_birth || null,
        nationality: null,
        email: null,
      };

      // Only include notes if it has actual content
      if (formData.notes && formData.notes.trim() !== '') {
        contactData.notes = formData.notes.trim();
      }

      if (assignment?.campaign?.id) contactData.campaign_id = assignment.campaign.id;
      if (assignment?.site?.id) contactData.mission_site_id = assignment.site.id;

      const contact = await outreachContactsApi.create(contactData);

      // Create initial encounter
      if (assignment?.campaign?.id) {
        const encounterData: any = {
          person_id: contact.person_id,
          campaign_id: assignment.campaign.id,
          mission_site_id: assignment.site?.id,
          encounter_method_id: formData.encounter_method_id ? parseInt(formData.encounter_method_id) : undefined,
          encounter_date: formData.encounter_date,
        };
        // Only include notes if it has actual content
        if (formData.encounter_notes && formData.encounter_notes.trim() !== '') {
          encounterData.notes = formData.encounter_notes.trim();
        }
        await outreachEncountersApi.create(encounterData);
      }

      // Reset form
      setFormData({
        first_name: '',
        last_name: '',
        gender: '',
        phone: '',
        village: '',
        date_of_birth: '',
        notes: '',
        engagement_stage: engagementStages.find(s => s.code === 'new_contact')?.code || 'new_contact',
        encounter_date: new Date().toISOString().split('T')[0],
        encounter_method_id: '',
        encounter_notes: '',
      });
      setDuplicates([]);

      setShowAddForm(false);
      loadPeople();

      // Navigate to contact profile
      router.push(`/people/${contact.id}`);
    } catch (err: any) {
      setError(err.message || 'Failed to register contact');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleSelectDuplicate = (person: Person) => {
    setShowAddForm(false);
    router.push(`/people/${person.id}`);
  };

  return (
    <div className="min-h-screen p-4 md:p-6">
      <div className="max-w-4xl mx-auto">
        {/* Header */}
        <div className="mb-6">
          <h1 className="text-2xl md:text-3xl font-bold text-slate-100 mb-2">Outreach Contacts</h1>
          <p className="text-slate-400 text-sm">People you have deliberately chosen to reach out to, and the follow-up that continues it.</p>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-2 md:grid-cols-3 gap-3 mb-6">
          <div className="glass-card p-4">
            <div className="flex items-center gap-2 mb-2">
              <Users2 className="w-4 h-4 text-brand-400" />
              <span className="text-sm font-medium text-slate-100">Total</span>
            </div>
            <p className="text-xl font-bold text-slate-100">{people.length}</p>
          </div>
          <div className="glass-card p-4">
            <div className="flex items-center gap-2 mb-2">
              <MapPin className="w-4 h-4 text-emerald-400" />
              <span className="text-sm font-medium text-slate-100">Villages</span>
            </div>
            <p className="text-xl font-bold text-slate-100">
              {new Set(people.map(p => p.village).filter(Boolean)).size}
            </p>
          </div>
          <div className="glass-card p-4 col-span-2 md:col-span-1">
            <div className="flex items-center gap-2 mb-2">
              <Calendar className="w-4 h-4 text-purple-400" />
              <span className="text-sm font-medium text-slate-100">This Week</span>
            </div>
            <p className="text-xl font-bold text-slate-100">
              {people.filter(p => {
                if (!p.date_registered) return false;
                const regDate = new Date(p.date_registered);
                const weekAgo = new Date();
                weekAgo.setDate(weekAgo.getDate() - 7);
                return regDate >= weekAgo;
              }).length}
            </p>
          </div>
        </div>

        {/* Search and Add */}
        <div className="flex gap-2 mb-6">
          <div className="flex-1 relative min-w-0">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
            <input
              type="text"
              placeholder="Search by name, phone, or village..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
              className="w-full pl-10 pr-16 py-2.5 glass-input text-slate-100 placeholder-slate-400 focus:outline-none focus:border-brand-400"
            />
            <button
              onClick={handleSearch}
              className="absolute right-2 top-1/2 -translate-y-1/2 px-2 py-1 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-xs font-medium transition-colors"
            >
              Search
            </button>
          </div>
          <div className="relative" ref={filterDropdownRef}>
            <button
              onClick={() => setShowFilterDropdown(!showFilterDropdown)}
              className="glass-button flex items-center gap-2 px-3 py-2.5 text-slate-100 focus:outline-none focus:border-brand-400"
            >
              <Filter className="w-4 h-4" />
              <span className="hidden sm:inline">Filter</span>
              {engagementFilter && <span className="w-2 h-2 bg-brand-400 rounded-full" />}
            </button>
            {showFilterDropdown && (
              <div className="absolute right-0 top-full mt-2 w-48 glass-card shadow-lg z-10">
                <div className="p-2">
                  <label className="block text-xs text-slate-400 mb-1">Engagement Stage</label>
                  <select
                    value={engagementFilter}
                    onChange={(e) => {
                      setEngagementFilter(e.target.value);
                      setShowFilterDropdown(false);
                      handleSearch();
                    }}
                    className="w-full px-3 py-2 glass-input text-slate-100 focus:outline-none focus:border-brand-400"
                  >
                    <option value="">All Stages</option>
                    {engagementStages.map((stage) => (
                      <option key={stage.code} value={stage.code}>{stage.label}</option>
                    ))}
                  </select>
                </div>
              </div>
            )}
          </div>
          <button
            onClick={() => setShowAddForm(true)}
            className="px-3 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl font-medium transition-colors flex items-center gap-2"
          >
            <Plus className="w-4 h-4" />
            <span className="hidden sm:inline">Create Contact</span>
          </button>
        </div>

        {/* Error */}
        {error && (
          <div className="mb-4 p-3 bg-red-500/10 backdrop-blur-xl border border-red-500/30 rounded-2xl text-red-400 text-sm">
            {error}
          </div>
        )}

        {/* People List */}
        <div className="glass-card overflow-hidden">
          {isLoading ? (
            <div className="flex items-center justify-center py-12">
              <Loader2 className="w-6 h-6 animate-spin text-slate-400" />
            </div>
          ) : people.length === 0 ? (
            <div className="text-center py-12">
              <Users2 className="w-12 h-12 mx-auto mb-3 text-slate-500" />
              <p className="text-slate-400">No contacts found</p>
              <p className="text-sm text-slate-500 mt-1">Click "Create Contact" to register someone</p>
            </div>
          ) : (
            <div className="divide-y divide-white/10">
              {people.map((contact) => (
                <div key={contact.id} className="p-4 hover:bg-white/10 transition-colors cursor-pointer" onClick={() => router.push(`/people/${contact.id}`)}>
                  <div className="flex items-start gap-3">
                    <div className="w-10 h-10 rounded-full bg-brand-500/20 flex items-center justify-center flex-shrink-0">
                      <User className="w-5 h-5 text-brand-400" />
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-start justify-between gap-2 mb-1">
                        <div>
                          <p className="text-sm font-medium text-slate-100">{contact.person?.first_name} {contact.person?.last_name}</p>
                          {contact.campaign && (
                            <p className="text-xs text-slate-400">{contact.campaign.name}</p>
                          )}
                        </div>
                        {contact.engagement_stage && (
                          <span className={`inline-block px-2 py-0.5 rounded-full text-xs font-medium ${getEngagementStageColor(contact.engagement_stage.color_key)}`}>
                            {contact.engagement_stage.label}
                          </span>
                        )}
                      </div>
                      <div className="flex items-center gap-4 text-xs text-slate-400">
                        {contact.person?.phone && (
                          <div className="flex items-center gap-1">
                            <Phone className="w-3 h-3" />
                            <span>{contact.person.phone}</span>
                          </div>
                        )}
                        {contact.village && (
                          <div className="flex items-center gap-1">
                            <MapPin className="w-3 h-3" />
                            <span>{contact.village}</span>
                          </div>
                        )}
                      </div>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Create Contact Modal */}
        {showAddForm && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4">
            <div className="relative bg-slate-950/80 backdrop-blur-3xl rounded-[2rem] shadow-2xl shadow-black/70 overflow-hidden w-full max-w-lg max-h-[90vh] overflow-y-auto" style={{ boxShadow: '0 32px 80px -12px rgba(0,0,0,0.8), inset 0 1px 0 rgba(255,255,255,0.04)' }}>
              {/* Close button */}
              <button
                onClick={() => {
                  setShowAddForm(false);
                  setCurrentStep(1);
                }}
                className="absolute top-5 right-5 z-10 w-9 h-9 rounded-full bg-white/8 border border-white/10 flex items-center justify-center text-slate-400 hover:text-slate-100 hover:bg-white/15 transition-all"
              >
                <X className="w-4 h-4" />
              </button>

              {/* Step Title */}
              <div className="px-10 pt-8 pb-6 text-center">
                <h3 className="text-2xl font-bold text-white mb-1 tracking-tight">
                  {[
                    'Basic Information',
                    'Additional Details',
                    'Engagement Stage',
                    'Initial Encounter',
                    'General Notes',
                  ][currentStep - 1]}
                </h3>
                <div className="inline-flex items-center gap-2 px-4 py-1.5 bg-white/5 border border-white/8 rounded-full mt-2">
                  <span className="text-xs font-semibold text-brand-400">{currentStep}</span>
                  <span className="text-xs text-slate-500">/</span>
                  <span className="text-xs text-slate-400">5</span>
                </div>
              </div>

              <form onSubmit={handleSubmit} className="px-10 pb-10 space-y-4">
                {/* Duplicate Warning */}
                {duplicates.length > 0 && (
                  <div className="p-3 bg-amber-500/10 backdrop-blur-sm border border-amber-500/30 rounded-2xl">
                    <div className="flex items-start gap-2 mb-2">
                      <AlertCircle className="w-4 h-4 text-amber-400 mt-0.5" />
                      <p className="text-sm text-amber-400 font-medium">
                        {duplicates.length} possible match{duplicates.length > 1 ? 'es' : ''} found
                      </p>
                    </div>
                    <div className="space-y-2">
                      {duplicates.map((dup) => (
                        <div
                          key={dup.id}
                          onClick={() => handleSelectDuplicate(dup)}
                          className="p-2 bg-white/5 cursor-pointer hover:bg-white/10 transition-colors rounded-xl"
                        >
                          <p className="text-sm text-slate-100 font-medium">{dup.first_name} {dup.last_name}</p>
                          {dup.phone && <p className="text-xs text-slate-400">{dup.phone}</p>}
                        </div>
                      ))}
                    </div>
                  </div>
                )}

                {/* Step 1: Basic Information */}
                {currentStep === 1 && (
                  <div className="space-y-4 w-full max-w-md mx-auto animate-in fade-in slide-in-from-bottom-4 duration-300">
                    <div className="grid grid-cols-2 gap-4">
                      <div>
                        <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">First Name *</label>
                        <input
                          type="text"
                          value={formData.first_name}
                          onChange={(e) => {
                            setFormData({ ...formData, first_name: e.target.value });
                            checkForDuplicates();
                          }}
                          className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                          placeholder="John"
                        />
                      </div>
                      <div>
                        <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Last Name *</label>
                        <input
                          type="text"
                          value={formData.last_name}
                          onChange={(e) => {
                            setFormData({ ...formData, last_name: e.target.value });
                            checkForDuplicates();
                          }}
                          className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                          placeholder="Doe"
                        />
                      </div>
                    </div>
                    <p className="text-xs text-slate-500 text-center">At least one name is required</p>

                    <div className="grid grid-cols-2 gap-4">
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
                              onClick={() => setFormData({ ...formData, gender: option.value })}
                              className={`px-4 py-2 rounded-full text-sm font-medium transition-all ${
                                formData.gender === option.value
                                  ? 'bg-brand-500 text-white border-brand-500'
                                  : 'bg-white/5 border border-white/8 text-slate-300 hover:bg-white/10 hover:border-white/15'
                              }`}
                            >
                              {option.label}
                            </button>
                          ))}
                        </div>
                      </div>
                      <div>
                        <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Phone</label>
                        <input
                          type="tel"
                          value={formData.phone}
                          onChange={(e) => {
                            setFormData({ ...formData, phone: e.target.value });
                            checkForDuplicates();
                          }}
                          className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                          placeholder="+256..."
                        />
                      </div>
                    </div>
                  </div>
                )}

                {/* Step 2: Additional Details */}
                {currentStep === 2 && (
                  <div className="space-y-4 w-full max-w-md mx-auto animate-in fade-in slide-in-from-bottom-4 duration-300">
                    <div>
                      <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Village/Area</label>
                      <input
                        type="text"
                        value={formData.village}
                        onChange={(e) => setFormData({ ...formData, village: e.target.value })}
                        className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all"
                        placeholder="Kireka"
                      />
                    </div>

                    <div>
                      <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Date of Birth</label>
                      <input
                        type="date"
                        value={formData.date_of_birth}
                        onChange={(e) => setFormData({ ...formData, date_of_birth: e.target.value })}
                        className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all [color-scheme:dark]"
                      />
                    </div>
                  </div>
                )}

                {/* Step 3: Engagement Stage */}
                {currentStep === 3 && (
                  <div className="space-y-4 w-full max-w-md mx-auto animate-in fade-in slide-in-from-bottom-4 duration-300">
                    <div>
                      <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Current Engagement Stage</label>
                      <div className="flex flex-wrap gap-2">
                        {engagementStages.map((stage) => (
                          <button
                            key={stage.code}
                            type="button"
                            onClick={() => setFormData({ ...formData, engagement_stage: stage.code })}
                            className={`px-4 py-2 rounded-full text-sm font-medium transition-all ${
                              formData.engagement_stage === stage.code
                                ? 'bg-brand-500 text-white border-brand-500'
                                : 'bg-white/5 border border-white/8 text-slate-300 hover:bg-white/10 hover:border-white/15'
                            }`}
                          >
                            {stage.label}
                          </button>
                        ))}
                      </div>
                    </div>
                  </div>
                )}

                {/* Step 4: Initial Encounter */}
                {currentStep === 4 && (
                  <div className="space-y-4 w-full max-w-md mx-auto animate-in fade-in slide-in-from-bottom-4 duration-300">
                    <div>
                      <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Encounter Date</label>
                      <input
                        type="date"
                        value={formData.encounter_date}
                        onChange={(e) => setFormData({ ...formData, encounter_date: e.target.value })}
                        className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all [color-scheme:dark]"
                      />
                    </div>

                    <div>
                      <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Encounter Method</label>
                      <div className="flex flex-wrap gap-2">
                        {encounterMethods.map((method) => (
                          <button
                            key={method.id}
                            type="button"
                            onClick={() => setFormData({ ...formData, encounter_method_id: method.id.toString() })}
                            className={`px-4 py-2 rounded-full text-sm font-medium transition-all ${
                              formData.encounter_method_id === method.id.toString()
                                ? 'bg-brand-500 text-white border-brand-500'
                                : 'bg-white/5 border border-white/8 text-slate-300 hover:bg-white/10 hover:border-white/15'
                            }`}
                          >
                            {method.label}
                          </button>
                        ))}
                      </div>
                    </div>

                    <div>
                      <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">Encounter Notes</label>
                      <textarea
                        value={formData.encounter_notes}
                        onChange={(e) => setFormData({ ...formData, encounter_notes: e.target.value })}
                        rows={3}
                        className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all resize-none"
                        placeholder="Notes about the initial encounter..."
                      />
                    </div>
                  </div>
                )}

                {/* Step 5: General Notes */}
                {currentStep === 5 && (
                  <div className="space-y-4 w-full max-w-md mx-auto animate-in fade-in slide-in-from-bottom-4 duration-300">
                    <div>
                      <label className="block text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2 text-left">General Notes</label>
                      <textarea
                        value={formData.notes}
                        onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                        rows={4}
                        className="w-full bg-white/5 border border-white/8 rounded-2xl px-5 py-4 text-slate-100 text-sm focus:outline-none focus:border-brand-500/50 focus:bg-white/8 transition-all resize-none"
                        placeholder="Any additional notes about this contact..."
                      />
                    </div>
                  </div>
                )}

                {/* Navigation Buttons */}
                <div className="flex items-center justify-center gap-4 pt-4">
                  {currentStep > 1 ? (
                    <button
                      type="button"
                      onClick={() => setCurrentStep(currentStep - 1)}
                      className="px-8 py-3.5 rounded-2xl bg-white/5 text-slate-300 text-sm font-semibold hover:bg-white/10 transition-all"
                    >
                      Back
                    </button>
                  ) : (
                    <button
                      type="button"
                      onClick={() => {
                        setShowAddForm(false);
                        setCurrentStep(1);
                      }}
                      className="px-8 py-3.5 rounded-2xl bg-white/5 text-slate-300 text-sm font-semibold hover:bg-white/10 transition-all"
                    >
                      Cancel
                    </button>
                  )}

                  {currentStep < 5 ? (
                    <button
                      type="button"
                      onClick={() => setCurrentStep(currentStep + 1)}
                      className="px-10 py-3.5 rounded-2xl bg-brand-600 text-white text-sm font-semibold hover:bg-brand-500 transition-all flex items-center gap-2 shadow-lg shadow-brand-600/30"
                    >
                      Continue
                      <ChevronRight className="w-4 h-4" />
                    </button>
                  ) : (
                    <button
                      type="submit"
                      disabled={isSubmitting || duplicates.length > 0}
                      className="px-10 py-3.5 rounded-2xl bg-brand-600 text-white text-sm font-semibold hover:bg-brand-500 disabled:opacity-40 disabled:cursor-not-allowed transition-all flex items-center gap-2 shadow-lg shadow-brand-600/30"
                    >
                      {isSubmitting ? (
                        <>
                          <span className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                          Creating…
                        </>
                      ) : (
                        <>
                          <CheckCircle className="w-4 h-4" />
                          Create Contact
                        </>
                      )}
                    </button>
                  )}
                </div>
              </form>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
