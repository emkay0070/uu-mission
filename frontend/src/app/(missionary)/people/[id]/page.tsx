'use client';

import { useState, useEffect } from 'react';
import { useRouter, useParams } from 'next/navigation';
import { ArrowLeft, User, MapPin, Phone, Calendar, Loader2, Plus, X } from 'lucide-react';
import { peopleApi, workspaceApi, refApi, campaignsApi, type Person, type OutreachEncounter, type BaptismRecord, type MyAssignment, type EncounterMethod, type EngagementStageHistory, type EngagementStage, outreachEncountersApi, baptismRecordsApi } from '@/lib/api';
import EncounterFormModal, { type EncounterFormData } from '@/components/EncounterFormModal';

export default function PersonProfilePage() {
  const router = useRouter();
  const params = useParams();
  const personId = parseInt(params.id as string);
  
  const [person, setPerson] = useState<Person | null>(null);
  const [encounters, setEncounters] = useState<OutreachEncounter[]>([]);
  const [baptisms, setBaptisms] = useState<BaptismRecord[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [engagementStages, setEngagementStages] = useState<EngagementStage[]>([]);
  const [assignment, setAssignment] = useState<MyAssignment | null>(null);
  const [encounterMethods, setEncounterMethods] = useState<EncounterMethod[]>([]);
  const [authorisedUsers, setAuthorisedUsers] = useState<Array<{ user_id: number; person_id: number; name: string; email?: string }>>([]);
  
  const [showEncounterForm, setShowEncounterForm] = useState(false);
  const [showVerifyForm, setShowVerifyForm] = useState(false);
  const [selectedBaptism, setSelectedBaptism] = useState<BaptismRecord | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [completingEncounter, setCompletingEncounter] = useState<number | null>(null);
  
  const [encounterFormData, setEncounterFormData] = useState({
    encounter_date: new Date().toISOString().split('T')[0],
    encounter_method_id: '',
    notes: '',
    stage_after_encounter_id: '',
    next_action: '',
    follow_up_date: '',
  });

  const [verifyFormData, setVerifyFormData] = useState({
    status: 'verified' as 'verified' | 'rejected',
    notes: '',
  });

  useEffect(() => {
    loadPerson();
    loadAssignment();
    loadEncounterMethods();
    loadEngagementStages();
    loadAuthorisedUsers();
  }, [personId]);

  const loadPerson = async () => {
    setIsLoading(true);
    try {
      // peopleApi.get() already loads encounters, baptisms, stage history via show()
      const personData = await peopleApi.get(personId);
      setPerson(personData);
      // Use the encounters and baptisms already loaded in personData
      setEncounters(personData.outreach_encounters || []);
      setBaptisms(personData.baptism_records || []);
    } catch (err: any) {
      setError(err.message || 'Failed to load person');
    } finally {
      setIsLoading(false);
    }
  };

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

  const loadAuthorisedUsers = async () => {
    try {
      const response = await workspaceApi.myAssignment();
      if (response.assignment?.campaign?.id) {
        const users = await campaignsApi.authorisedUsers(response.assignment.campaign.id, {
          site_id: response.assignment.site?.id,
        });
        setAuthorisedUsers(users);
      }
    } catch (err) {
      console.error('Failed to load authorised users:', err);
    }
  };

  /**
   * Resolve a Tailwind colour class pair from a color_key string.
   * The key is stored directly on the EngagementStage object.
   */
  const colorKeyToClass = (colorKey: string | null | undefined): string => {
    const colors: Record<string, string> = {
      blue:    'bg-blue-500/20 text-blue-400',
      cyan:    'bg-cyan-500/20 text-cyan-400',
      purple:  'bg-purple-500/20 text-purple-400',
      amber:   'bg-amber-500/20 text-amber-400',
      emerald: 'bg-emerald-500/20 text-emerald-400',
      green:   'bg-green-500/20 text-green-400',
      indigo:  'bg-indigo-500/20 text-indigo-400',
      slate:   'bg-slate-500/20 text-slate-400',
      red:     'bg-red-500/20 text-red-400',
      orange:  'bg-orange-500/20 text-orange-400',
      gray:    'bg-gray-500/20 text-gray-400',
      teal:    'bg-teal-500/20 text-teal-400',
      violet:  'bg-violet-500/20 text-violet-400',
    };
    return colors[colorKey ?? ''] ?? 'bg-slate-500/20 text-slate-400';
  };

  /**
   * Accept either a stage object or a stage code string (fallback via loaded ref data).
   */
  const getEngagementStageColor = (stageOrCode: EngagementStage | string | null | undefined): string => {
    if (!stageOrCode) return 'bg-slate-500/20 text-slate-400';
    if (typeof stageOrCode === 'object') {
      return colorKeyToClass(stageOrCode.color_key);
    }
    const stageData = engagementStages.find(s => s.code === stageOrCode);
    return colorKeyToClass(stageData?.color_key);
  };

  const getVerificationStatusColor = (status: string) => {
    const colors: Record<string, string> = {
      pending: 'bg-amber-500/20 text-amber-400',
      verified: 'bg-green-500/20 text-green-400',
      rejected: 'bg-red-500/20 text-red-400',
    };
    return colors[status] || 'bg-slate-500/20 text-slate-400';
  };

  const handleAddEncounter = async (data: EncounterFormData) => {
    setIsSubmitting(true);
    try {
      if (!assignment?.campaign?.id) {
        setError('No active campaign assignment');
        return;
      }
      const payload: any = {
        person_id: personId,
        campaign_id: assignment.campaign.id,
        mission_site_id: assignment.site?.id,
        encounter_method_id: data.encounter_method_id ? parseInt(data.encounter_method_id) : undefined,
        encounter_date: data.encounter_date,
        notes: data.notes || undefined,
      };
      if (data.stage_after_encounter_id) payload.stage_after_encounter_id = parseInt(data.stage_after_encounter_id);
      if (data.next_action)              payload.next_action = data.next_action;
      if (data.follow_up_date)           payload.follow_up_date = data.follow_up_date;
      if (data.assigned_follow_up_user_id) payload.assigned_follow_up_user_id = parseInt(data.assigned_follow_up_user_id);
      await outreachEncountersApi.create(payload);
      setShowEncounterForm(false);
      loadPerson();
    } catch (err: any) {
      setError(err.message || 'Failed to add encounter');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleVerifyBaptism = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedBaptism) return;
    setIsSubmitting(true);
    try {
      await baptismRecordsApi.verify(selectedBaptism.id, {
        status: verifyFormData.status,
        notes: verifyFormData.notes,
      });
      setVerifyFormData({ status: 'verified', notes: '' });
      setSelectedBaptism(null);
      setShowVerifyForm(false);
      loadPerson();
    } catch (err: any) {
      setError(err.message || 'Failed to verify baptism');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleCompleteFollowUp = async (encounterId: number) => {
    setCompletingEncounter(encounterId);
    try {
      await outreachEncountersApi.complete(encounterId, {});
      loadPerson();
    } catch (err: any) {
      setError(err.message || 'Failed to complete follow-up');
    } finally {
      setCompletingEncounter(null);
    }
  };

  const handleRescheduleFollowUp = async (encounterId: number, newDate: string) => {
    setCompletingEncounter(encounterId);
    try {
      await outreachEncountersApi.reschedule(encounterId, { follow_up_date: newDate });
      loadPerson();
    } catch (err: any) {
      setError(err.message || 'Failed to reschedule follow-up');
    } finally {
      setCompletingEncounter(null);
    }
  };

  if (isLoading) {
    return (
      <div className="min-h-screen bg-surface p-4 md:p-6">
        <div className="max-w-4xl mx-auto flex items-center justify-center py-12">
          <Loader2 className="w-6 h-6 animate-spin text-slate-400" />
        </div>
      </div>
    );
  }

  if (error || !person) {
    return (
      <div className="min-h-screen bg-surface p-4 md:p-6">
        <div className="max-w-4xl mx-auto">
          <button
            onClick={() => router.back()}
            className="flex items-center gap-2 text-slate-400 hover:text-slate-100 mb-6"
          >
            <ArrowLeft className="w-4 h-4" />
            Back
          </button>
          <div className="p-4 bg-red-500/10 border border-red-500/20 rounded-xl text-red-400">
            {error || 'Person not found'}
          </div>
        </div>
      </div>
    );
  }

  // Build timeline â€” uses separately-fetched encounters/baptisms arrays
  // plus engagement_stage_history from the person object (loaded by show())
  const timelineEvents = [
    ...encounters.map(e => ({
      type: 'encounter' as const,
      date: e.encounter_date,
      data: e,
    })),
    ...baptisms.map(b => ({
      type: 'baptism' as const,
      date: b.baptism_date,
      data: b,
    })),
    ...(person.engagement_stage_history || []).map(h => ({
      type: 'stage_change' as const,
      date: h.changed_at,
      data: h,
    })),
  ].sort((a, b) => new Date(b.date).getTime() - new Date(a.date).getTime());

  return (
    <div className="min-h-screen bg-surface p-4 md:p-6">
      <div className="max-w-4xl mx-auto">
        <button
          onClick={() => router.back()}
          className="flex items-center gap-2 text-slate-400 hover:text-slate-100 mb-6"
        >
          <ArrowLeft className="w-4 h-4" />
          Back to Contacts
        </button>

        {/* Profile Header */}
        <div className="bg-surface-2 border border-surface-border rounded-2xl p-6 mb-6">
          <div className="flex items-start gap-4 mb-4">
            <div className="w-16 h-16 rounded-full bg-brand-500/20 flex items-center justify-center flex-shrink-0">
              <User className="w-8 h-8 text-brand-400" />
            </div>
            <div className="flex-1">
              <h1 className="text-2xl font-bold text-slate-100 mb-1">{person.full_name}</h1>
              <div className="flex items-center gap-2 flex-wrap">
                {/* engagement_stage is the correct snake_case key from Laravel */}
                {person.engagement_stage && (
                  <span className={`inline-block px-3 py-1 rounded-full text-sm font-medium capitalize ${getEngagementStageColor(person.engagement_stage)}`}>
                    {person.engagement_stage.label}
                  </span>
                )}
              </div>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {person.phone && (
              <div className="flex items-center gap-2 text-slate-300">
                <Phone className="w-4 h-4 text-slate-400" />
                <span>{person.phone}</span>
              </div>
            )}
            {person.village && (
              <div className="flex items-center gap-2 text-slate-300">
                <MapPin className="w-4 h-4 text-slate-400" />
                <span>{person.village}</span>
              </div>
            )}
            {person.date_of_birth && (
              <div className="flex items-center gap-2 text-slate-300">
                <Calendar className="w-4 h-4 text-slate-400" />
                <span>DOB: {new Date(person.date_of_birth).toLocaleDateString()}</span>
              </div>
            )}
            {person.date_registered && (
              <div className="flex items-center gap-2 text-slate-300">
                <Calendar className="w-4 h-4 text-slate-400" />
                <span>Registered: {new Date(person.date_registered).toLocaleDateString()}</span>
              </div>
            )}
          </div>

          {person.notes && (
            <div className="mt-4 p-3 bg-surface-3 rounded-lg">
              <p className="text-sm text-slate-300">{person.notes}</p>
            </div>
          )}
        </div>

        {/* Follow-up Section */}
        {encounters.some(e => e.follow_up_date && !e.completed_at && new Date(e.follow_up_date) >= new Date()) && (
          <div className="bg-surface-2 border border-surface-border rounded-2xl p-6 mb-6">
            <h2 className="text-lg font-semibold text-slate-100 mb-4">Follow-ups</h2>
            {encounters
              .filter(e => e.follow_up_date && !e.completed_at && new Date(e.follow_up_date) >= new Date())
              .sort((a, b) => new Date(a.follow_up_date!).getTime() - new Date(b.follow_up_date!).getTime())
              .map((encounter) => (
                <div key={encounter.id} className="p-4 bg-surface-3 rounded-lg mb-3 last:mb-0">
                  <div className="flex items-center justify-between mb-2">
                    <span className="text-sm font-medium text-slate-100">Next action</span>
                    <span className={`text-xs px-2 py-0.5 rounded-full ${
                      new Date(encounter.follow_up_date!) < new Date()
                        ? 'bg-orange-500/20 text-orange-400'
                        : 'bg-blue-500/20 text-blue-400'
                    }`}>
                      {new Date(encounter.follow_up_date!) < new Date() ? 'Overdue' : 'Upcoming'}
                    </span>
                  </div>
                  {encounter.next_action && (
                    <p className="text-sm text-slate-300 mb-2">{encounter.next_action}</p>
                  )}
                  <div className="flex items-center justify-between gap-4 text-xs text-slate-400 mb-3">
                    <div className="flex items-center gap-4">
                      <span>Due: {new Date(encounter.follow_up_date!).toLocaleDateString()}</span>
                      {/* assigned_follow_up_user is the correct snake_case key */}
                      {encounter.assigned_follow_up_user && (
                        <span>Assigned: {encounter.assigned_follow_up_user.person?.full_name || encounter.assigned_follow_up_user.email}</span>
                      )}
                    </div>
                  </div>
                  <div className="flex gap-2">
                    <button
                      onClick={() => handleCompleteFollowUp(encounter.id)}
                      disabled={completingEncounter === encounter.id}
                      className="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-xs font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                      {completingEncounter === encounter.id ? 'Completing...' : 'Complete'}
                    </button>
                    <button
                      onClick={() => {
                        const newDate = prompt('Enter new follow-up date (YYYY-MM-DD):');
                        if (newDate) handleRescheduleFollowUp(encounter.id, newDate);
                      }}
                      disabled={completingEncounter === encounter.id}
                      className="px-3 py-1.5 bg-surface-3 hover:bg-surface-3/80 text-slate-100 rounded-lg text-xs font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                      Reschedule
                    </button>
                  </div>
                </div>
              ))}
          </div>
        )}

        {/* Journey Timeline */}
        <div className="bg-surface-2 border border-surface-border rounded-2xl p-6 mb-6">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-semibold text-slate-100">Journey Timeline</h2>
            <button
              onClick={() => setShowEncounterForm(true)}
              className="flex items-center gap-2 px-3 py-1.5 bg-brand-600 hover:bg-brand-500 text-white rounded-lg text-sm font-medium transition-colors"
            >
              <Plus className="w-4 h-4" />
              Add Encounter
            </button>
          </div>
          {timelineEvents.length === 0 ? (
            <p className="text-slate-400 text-sm">No journey events recorded yet.</p>
          ) : (
            <div className="space-y-4">
              {timelineEvents.map((event, idx) => (
                <div key={idx} className="flex gap-4">
                  <div className="flex flex-col items-center">
                    <div className={`w-3 h-3 rounded-full ${
                      event.type === 'encounter' ? 'bg-brand-400' :
                      event.type === 'baptism' ? 'bg-emerald-400' :
                      event.data.is_correction ? 'bg-amber-400' : 'bg-violet-400'
                    }`} />
                    {idx < timelineEvents.length - 1 && <div className="w-0.5 h-full bg-surface-border mt-2" />}
                  </div>
                  <div className="flex-1 pb-4">
                    {event.type === 'encounter' && (
                      <div className="p-3 bg-surface-3 rounded-lg">
                        <div className="flex items-center justify-between mb-2">
                          <span className="text-sm font-medium text-slate-100">Outreach Encounter</span>
                          <span className="text-xs text-slate-400">{new Date(event.date).toLocaleDateString()}</span>
                        </div>
                        {event.data.method && (
                          <p className="text-sm text-slate-400 mb-1">Method: {event.data.method.label}</p>
                        )}
                        {event.data.campaign && (
                          <p className="text-sm text-slate-400 mb-1">Campaign: {event.data.campaign.name}</p>
                        )}
                        {event.data.site && (
                          <p className="text-sm text-slate-400 mb-1">Site: {event.data.site.name}</p>
                        )}
                        {/* stage_before and stage_after are the correct snake_case keys */}
                        {event.data.stage_before && event.data.stage_after && (
                          <div className="flex items-center gap-2 mt-2">
                            <span className={`inline-block px-2 py-0.5 rounded-full text-xs font-medium opacity-60 ${getEngagementStageColor(event.data.stage_before)}`}>
                              {event.data.stage_before.label}
                            </span>
                            <span className="text-slate-400">â†’</span>
                            <span className={`inline-block px-2 py-0.5 rounded-full text-xs font-medium ${getEngagementStageColor(event.data.stage_after)}`}>
                              {event.data.stage_after.label}
                            </span>
                          </div>
                        )}
                        {event.data.next_action && (
                          <p className="text-sm text-slate-300 mt-2">
                            <span className="font-medium">Next action:</span> {event.data.next_action}
                          </p>
                        )}
                        {event.data.follow_up_date && (
                          <p className="text-sm text-slate-400">
                            Follow-up: {new Date(event.data.follow_up_date).toLocaleDateString()}
                          </p>
                        )}
                        {event.data.notes && (
                          <p className="text-sm text-slate-300 mt-2">{event.data.notes}</p>
                        )}
                      </div>
                    )}
                    {event.type === 'baptism' && (
                      <div className="p-3 bg-surface-3 rounded-lg">
                        <div className="flex items-center justify-between mb-2">
                          <span className="text-sm font-medium text-slate-100">Baptism</span>
                          <div className="flex items-center gap-2">
                            <span className={`text-xs px-2 py-0.5 rounded-full ${getVerificationStatusColor(event.data.verification_status)}`}>
                              {event.data.verification_status}
                            </span>
                            {event.data.verification_status === 'pending' && (
                              <button
                                onClick={() => {
                                  setSelectedBaptism(event.data);
                                  setShowVerifyForm(true);
                                }}
                                className="text-xs text-brand-400 hover:text-brand-300"
                              >
                                Verify
                              </button>
                            )}
                          </div>
                        </div>
                        <p className="text-sm text-slate-400 mb-1">Date: {new Date(event.date).toLocaleDateString()}</p>
                        {event.data.location && (
                          <p className="text-sm text-slate-400 mb-1">Location: {event.data.location}</p>
                        )}
                        {/* officiated_by is the correct snake_case key */}
                        {event.data.officiated_by && (
                          <p className="text-sm text-slate-400 mb-1">Officiated by: {event.data.officiated_by.full_name}</p>
                        )}
                        {event.data.notes && (
                          <p className="text-sm text-slate-300 mt-2">{event.data.notes}</p>
                        )}
                      </div>
                    )}
                    {event.type === 'stage_change' && (
                      <div className={`p-3 bg-surface-3 rounded-lg ${event.data.is_correction ? 'border border-amber-500/30' : ''}`}>
                        <div className="flex items-center justify-between mb-2">
                          <span className="text-sm font-medium text-slate-100">
                            {event.data.is_correction ? 'Engagement Stage Corrected' : 'Engagement Stage Changed'}
                          </span>
                          <span className="text-xs text-slate-400">{new Date(event.date).toLocaleDateString()}</span>
                        </div>
                        <div className="flex items-center gap-2 mb-2">
                          {/* from_stage and to_stage are the correct snake_case keys */}
                          {event.data.from_stage && (
                            <span className={`inline-block px-2 py-0.5 rounded-full text-xs font-medium opacity-60 ${getEngagementStageColor(event.data.from_stage)}`}>
                              {event.data.from_stage.label}
                            </span>
                          )}
                          <span className="text-slate-400">â†’</span>
                          {event.data.to_stage && (
                            <span className={`inline-block px-2 py-0.5 rounded-full text-xs font-medium ${getEngagementStageColor(event.data.to_stage)}`}>
                              {event.data.to_stage.label}
                            </span>
                          )}
                        </div>
                        {event.data.is_correction && event.data.correction_reason && (
                          <p className="text-sm text-slate-300 mb-1">
                            <span className="font-medium">Reason:</span> {event.data.correction_reason}
                          </p>
                        )}
                        {/* changed_by is the correct snake_case key */}
                        {event.data.changed_by && (
                          <p className="text-xs text-slate-400">
                            Changed by: {event.data.changed_by.person?.full_name || event.data.changed_by.email}
                          </p>
                        )}
                        {/* outreach_encounter is the correct snake_case key */}
                        {event.data.outreach_encounter && (
                          <p className="text-xs text-slate-400">Linked to encounter on {new Date(event.data.outreach_encounter.encounter_date).toLocaleDateString()}</p>
                        )}
                        {event.data.notes && (
                          <p className="text-sm text-slate-300 mt-2">{event.data.notes}</p>
                        )}
                      </div>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Activities & Attendance */}
        <div className="bg-surface-2 border border-surface-border rounded-2xl p-6 mb-6">
          <h2 className="text-lg font-semibold text-slate-100 mb-4">Activities &amp; Attendance</h2>
          {/* activity_participations is the correct snake_case key */}
          {person.activity_participations && person.activity_participations.length > 0 ? (
            <div className="space-y-2">
              {person.activity_participations.map((participation) => (
                <div key={participation.id} className="p-3 bg-surface-3 rounded-lg">
                  <p className="text-sm font-medium text-slate-100">{participation.activity?.title}</p>
                  <p className="text-xs text-slate-400">
                    Joined: {participation.joined_date ? new Date(participation.joined_date).toLocaleDateString() : 'N/A'} â€¢ Status: {participation.status}
                  </p>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-slate-400 text-sm">No activity participations recorded.</p>
          )}
        </div>

        {/* Church Membership */}
        <div className="bg-surface-2 border border-surface-border rounded-2xl p-6 mb-6">
          <h2 className="text-lg font-semibold text-slate-100 mb-4">Church Membership</h2>
          {/* org_memberships is the correct snake_case key */}
          {person.org_memberships && person.org_memberships.length > 0 ? (
            <div className="space-y-2">
              {person.org_memberships.map((membership) => (
                <div key={membership.id} className="p-3 bg-surface-3 rounded-lg">
                  <p className="text-sm font-medium text-slate-100">{membership.orgUnit?.name}</p>
                  <p className="text-xs text-slate-400">
                    Type: {membership.membership_type} â€¢ Joined: {membership.joined_on ? new Date(membership.joined_on).toLocaleDateString() : 'N/A'}
                  </p>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-slate-400 text-sm">No church memberships recorded.</p>
          )}
        </div>

      </div>

      {/* visionOS Encounter Modal */}
      <EncounterFormModal
        isOpen={showEncounterForm}
        onClose={() => setShowEncounterForm(false)}
        onSave={handleAddEncounter}
        person={person}
        assignment={assignment}
        encounterMethods={encounterMethods}
        engagementStages={engagementStages}
        authorisedUsers={authorisedUsers}
        isSaving={isSubmitting}
      />

      {/* Verify Baptism Modal */}
      {showVerifyForm && selectedBaptism && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="bg-surface-2 w-full max-w-md rounded-2xl">
            <div className="sticky top-0 bg-surface-2 border-b border-surface-border p-4 flex items-center justify-between">
              <h3 className="font-semibold text-slate-100">Verify Baptism</h3>
              <button
                onClick={() => setShowVerifyForm(false)}
                className="p-2 rounded-lg hover:bg-surface-3/50 transition-colors text-slate-400"
              >
                <X className="w-5 h-5" />
              </button>
            </div>
            <form onSubmit={handleVerifyBaptism} className="p-4 space-y-4">
              <div>
                <label className="block text-sm font-medium text-slate-100 mb-1">Verification Status</label>
                <select
                  required
                  value={verifyFormData.status}
                  onChange={(e) => setVerifyFormData({ ...verifyFormData, status: e.target.value as 'verified' | 'rejected' })}
                  className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                >
                  <option value="verified">Verified</option>
                  <option value="rejected">Rejected</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-100 mb-1">Notes</label>
                <textarea
                  value={verifyFormData.notes}
                  onChange={(e) => setVerifyFormData({ ...verifyFormData, notes: e.target.value })}
                  rows={3}
                  className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400 resize-none"
                />
              </div>
              <div className="flex gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setShowVerifyForm(false)}
                  className="flex-1 px-4 py-2 bg-surface-3 hover:bg-surface-3/80 text-slate-100 rounded-xl font-medium transition-colors"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="flex-1 px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white rounded-xl font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                >
                  {isSubmitting && <Loader2 className="w-4 h-4 animate-spin" />}
                  Submit
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
