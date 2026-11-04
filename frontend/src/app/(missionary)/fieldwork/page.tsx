'use client';

import { useState, useEffect } from 'react';
import {
  Calendar, MapPin, X, Loader2, Plus, ChevronRight, Check, User, Search, UserPlus, Users,
  Target, Pencil, Trash2, AlertTriangle, ListChecks, GitCompare, CalendarPlus, UserCog,
} from 'lucide-react';
import {
  attendanceApi, activitiesApi, workspaceApi, peopleApi, refApi,
  type AttendanceRegister, type Activity, type ActivityObjective, type AttendanceParticipant,
  type AttendanceEntryPayload, type MyAssignment, type Person,
  type AttendanceReconciliation, type AttendanceSummary, type AttendanceSummaryCounts,
} from '@/lib/api';
import { hasPermission } from '@/lib/permissions';
import { useAuthStore } from '@/store/auth';
import { EmptyState } from '@/components/ui';

/**
 * Fieldwork — occurrence-based attendance.
 *
 * TWO SEPARATE FACTS ARE SHOWN HERE, NEVER CONFLATED:
 *
 *   1. SITE PRESENCE (Global Site Attendance): the standalone registers that
 *      record who was present at the mission site that day.
 *   2. ACTIVITY PARTICIPATION: the registers opened against a single activity,
 *      recording who took part in THAT activity.
 *
 * A person legitimately appears in BOTH, so the two totals are reported
 * separately and are never added together. Every label says plainly which of
 * the two it describes.
 *
 * A register is an OCCURRENCE. When it belongs to an activity the client
 * prefills the activity's name and date so the same meeting on the same day can
 * never be opened twice by accident.
 *
 * An attendee is recorded in exactly ONE of FOUR identity forms, and the UI
 * never invents a placeholder name to fill the gap:
 *
 *   existing person    → person_id   (an existing Person)
 *   new person         → person_id   (a Person created inline, just now)
 *   name only          → visitor_name (no Person record is created)
 *   anonymous          → is_anonymous (a head was counted, nothing is known)
 *
 * Every row carries a client-generated `entry_uuid`. It is minted ONCE per row
 * and reused on every save, which makes retried saves idempotent instead of
 * creating duplicate attendance rows.
 */

/**
 * A row in the checklist, carrying the idempotency key used when saving.
 *
 * `visitor_name` and `visitor_gender` are CLIENT-SIDE transport fields. The
 * participants endpoint deliberately returns a single resolved `name` rather
 * than the raw columns, so these two are held only for the lifetime of the row
 * and are re-derived on save (see `handleSaveAttendance`).
 */
type DraftRow = AttendanceParticipant & {
  visitor_name?: string | null;
  visitor_gender?: string | null;
};

/** Identity form chosen in the "add attendee" dialog. */
type AddMode = 'existing' | 'new_person' | 'name' | 'anonymous';

/**
 * A person created inline while taking attendance. Only the fields the people
 * endpoint requires are collected: the fourth identity form registers a NEW
 * Person, then records them as an ordinary `person_id` attendee.
 */
type NewPersonDraft = {
  first_name: string;
  last_name: string;
  phone: string;
  gender: string;
};

const REGISTER_TYPES: Array<{ value: string; label: string }> = [
  { value: 'meeting', label: 'Meeting' },
  { value: 'roll_call', label: 'Roll Call' },
  { value: 'service', label: 'Service' },
  { value: 'training', label: 'Training' },
  { value: 'community_event', label: 'Community Event' },
];

/** RFC 4122 v4 UUID, with a fallback for browsers without crypto.randomUUID. */
function newUuid(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

function today(): string {
  return new Date().toISOString().split('T')[0];
}

/** A sensible default register name, so a name is never a blank decision. */
function suggestedName(activity: Activity | undefined, type: string, date: string): string {
  if (activity) return activity.title;
  const label = REGISTER_TYPES.find((t) => t.value === type)?.label ?? 'Meeting';
  return `${label} — ${date}`;
}

export default function FieldworkPage() {
  const [registers, setRegisters] = useState<AttendanceRegister[]>([]);
  const [activities, setActivities] = useState<Activity[]>([]);
  const [selectedRegister, setSelectedRegister] = useState<AttendanceRegister | null>(null);
  const [participants, setParticipants] = useState<DraftRow[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [assignment, setAssignment] = useState<MyAssignment | null>(null);
  const [showCreateForm, setShowCreateForm] = useState(false);

  // Create-register form
  const [newRegisterData, setNewRegisterData] = useState({
    register_name: '',
    register_date: today(),
    register_type: 'meeting',
    activity_id: '',
  });

  // Add-attendee dialog
  const [showAddForm, setShowAddForm] = useState(false);
  const [addMode, setAddMode] = useState<AddMode>('name');
  const [personSearch, setPersonSearch] = useState('');
  const [personResults, setPersonResults] = useState<Person[]>([]);
  const [selectedPerson, setSelectedPerson] = useState<Person | null>(null);
  const [newName, setNewName] = useState('');
  const [newPhone, setNewPhone] = useState('');
  const [newGender, setNewGender] = useState('');
  const [anonymousCount, setAnonymousCount] = useState(1);
  // Fourth identity form: a NEW Person created inline, then added as person_id.
  const [newPerson, setNewPerson] = useState<NewPersonDraft>({
    first_name: '', last_name: '', phone: '', gender: '',
  });

  // ── Objectives (what the activity set out to achieve) ──────────────────────
  // Objectives belong to the ACTIVITY, never to the register: opening a
  // register for an activity shows them, and an authorised user may curate
  // them here without ever recreating the activity.
  const [objectives, setObjectives] = useState<ActivityObjective[]>([]);
  const [objectivesLoading, setObjectivesLoading] = useState(false);
  const [showObjectiveForm, setShowObjectiveForm] = useState(false);
  const [editingObjectiveId, setEditingObjectiveId] = useState<number | null>(null);
  const [objectiveTitle, setObjectiveTitle] = useState('');
  const [objectiveDescription, setObjectiveDescription] = useState('');

  // ── Genuinely unplanned activity ───────────────────────────────────────────
  // Offered only to users who may create activities, and only as a fallback for
  // an activity that genuinely arose on the day and is not in the planned list.
  const [activityTypes, setActivityTypes] = useState<Array<{ id: number; code: string; label: string; category: string }>>([]);
  const [showUnplannedForm, setShowUnplannedForm] = useState(false);
  const [unplannedActivity, setUnplannedActivity] = useState({
    title: '', activity_type_id: '', scheduled_at: today(), location_note: '',
  });

  // ── Reconciliation (read-only) ─────────────────────────────────────────────
  const [reconciliation, setReconciliation] = useState<AttendanceReconciliation | null>(null);
  const [reconciliationLoading, setReconciliationLoading] = useState(false);

  // ── Attendance summaries (read-only, two separate totals) ──────────────────
  const [view, setView] = useState<'registers' | 'summary'>('registers');
  const [summary, setSummary] = useState<AttendanceSummary | null>(null);
  const [summaryLoading, setSummaryLoading] = useState(false);
  const [summaryRange, setSummaryRange] = useState({ date_from: '', date_to: '' });

  const { user } = useAuthStore();
  // Managing objectives is gated on the activity's own update capability —
  // the same boundary the backend uses. Everyone else sees them read-only.
  const canManageObjectives = hasPermission(user, 'activities.update');
  // Authoring an activity requires activities.create; without it the
  // "unplanned activity" fallback is not shown at all.
  const canCreateActivity = hasPermission(user, 'activities.create');

  useEffect(() => {
    loadAssignment();
    loadActivityTypes();
  }, []);

  useEffect(() => {
    if (assignment?.campaign?.id) {
      loadRegisters();
      loadActivities();
    }
  }, [assignment]);

  const loadAssignment = async () => {
    try {
      const response = await workspaceApi.myAssignment();
      setAssignment(response.assignment);
    } catch (err) {
      console.error('Failed to load assignment:', err);
    }
  };

  const loadRegisters = async () => {
    if (!assignment?.campaign?.id) return;
    setIsLoading(true);
    try {
      const response = await attendanceApi.list({
        campaign_id: assignment.campaign.id,
        mission_site_id: assignment.site?.id,
        per_page: 20,
      });
      setRegisters(response.data);
    } catch (err: any) {
      setError(err.message || 'Failed to load registers');
    } finally {
      setIsLoading(false);
    }
  };

  const loadActivities = async () => {
    if (!assignment?.campaign?.id) return;
    try {
      // Completed activities are included: a register is often opened after the
      // event, and the occurrence still needs to be attached to it.
      const response = await activitiesApi.list(assignment.campaign.id, {
        site_id: assignment.site?.id,
      });
      setActivities(response);
    } catch (err) {
      console.error('Failed to load activities:', err);
    }
  };

  const loadParticipants = async (registerId: number) => {
    try {
      const response = await attendanceApi.participants(registerId);
      // Mint the idempotency key once per row; existing rows keep theirs.
      setParticipants(response.map((p) => ({ ...p, entry_uuid: p.entry_uuid ?? newUuid() })));
    } catch (err: any) {
      setError(err.message || 'Failed to load participants');
    }
  };

  const loadActivityTypes = async () => {
    try {
      // Reference data: the kinds of activity a site can run. Needed only to
      // author an activity that genuinely arose on the day.
      setActivityTypes(await refApi.activityTypes());
    } catch (err) {
      console.error('Failed to load activity types:', err);
    }
  };

  /**
   * Open a register for recording: load its attendees, its activity's
   * objectives (when it belongs to an activity) and clear any previous
   * reconciliation so nothing stale is shown against a different register.
   */
  const openRegister = (register: AttendanceRegister) => {
    setSelectedRegister(register);
    setReconciliation(null);
    setObjectives([]);
    loadParticipants(register.id);
    if (register.activity_id !== null) {
      loadObjectives(register.activity_id);
    }
  };

  // ── Objectives for the selected activity ───────────────────────────────────

  const loadObjectives = async (activityId: number) => {
    if (!assignment?.campaign?.id) return;
    setObjectivesLoading(true);
    try {
      setObjectives(await activitiesApi.objectives(assignment.campaign.id, activityId));
    } catch (err) {
      console.error('Failed to load objectives:', err);
      setObjectives([]);
    } finally {
      setObjectivesLoading(false);
    }
  };

  const resetObjectiveForm = () => {
    setShowObjectiveForm(false);
    setEditingObjectiveId(null);
    setObjectiveTitle('');
    setObjectiveDescription('');
  };

  /**
   * Add or update an objective on the ACTIVITY. Objectives are never created on
   * the register and the activity itself is never recreated — the register only
   * displays and (for authorised users) curates the activity's own purpose.
   */
  const handleSaveObjective = async () => {
    if (!assignment?.campaign?.id || !selectedRegister?.activity_id) return;
    if (!objectiveTitle.trim()) {
      setError('Give the objective a short title.');
      return;
    }
    setIsSubmitting(true);
    setError(null);
    try {
      const campaignId = assignment.campaign.id;
      const activityId = selectedRegister.activity_id;
      if (editingObjectiveId !== null) {
        await activitiesApi.updateObjective(campaignId, activityId, editingObjectiveId, {
          title: objectiveTitle.trim(),
          description: objectiveDescription.trim() || null,
        });
      } else {
        await activitiesApi.addObjective(campaignId, activityId, {
          title: objectiveTitle.trim(),
          description: objectiveDescription.trim() || null,
        });
      }
      resetObjectiveForm();
      await loadObjectives(activityId);
    } catch (err: any) {
      setError(err.message || 'Failed to save the objective');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleRemoveObjective = async (objectiveId: number) => {
    if (!assignment?.campaign?.id || !selectedRegister?.activity_id) return;
    setIsSubmitting(true);
    setError(null);
    try {
      await activitiesApi.removeObjective(assignment.campaign.id, selectedRegister.activity_id, objectiveId);
      await loadObjectives(selectedRegister.activity_id);
    } catch (err: any) {
      setError(err.message || 'Failed to remove the objective');
    } finally {
      setIsSubmitting(false);
    }
  };

  const startEditObjective = (objective: ActivityObjective) => {
    setEditingObjectiveId(objective.id);
    setObjectiveTitle(objective.title);
    setObjectiveDescription(objective.description ?? '');
    setShowObjectiveForm(true);
  };

  // ── Reconciliation (pure read) ─────────────────────────────────────────────

  /**
   * Load the read-only reconciliation for the selected register's site and day.
   * It compares SITE attendance against ACTIVITY attendance by permanent
   * identity only, and never mutates a record.
   */
  const loadReconciliation = async () => {
    if (!selectedRegister) return;
    setReconciliationLoading(true);
    setError(null);
    try {
      setReconciliation(await attendanceApi.reconciliation(selectedRegister.id));
    } catch (err: any) {
      setError(err.message || 'Failed to load reconciliation');
    } finally {
      setReconciliationLoading(false);
    }
  };

  // ── Attendance summaries (pure read) ───────────────────────────────────────

  /**
   * Load aggregated attendance. Site attendance and activity attendance are
   * returned as two independent totals and are displayed separately — they are
   * never summed, because the same person legitimately appears in both.
   */
  const loadSummary = async () => {
    if (!assignment?.campaign?.id) return;
    setSummaryLoading(true);
    setError(null);
    try {
      setSummary(await attendanceApi.summary({
        campaign_id: assignment.campaign.id,
        mission_site_id: assignment.site?.id,
        date_from: summaryRange.date_from || undefined,
        date_to: summaryRange.date_to || undefined,
      }));
    } catch (err: any) {
      setError(err.message || 'Failed to load attendance summary');
    } finally {
      setSummaryLoading(false);
    }
  };

  // Load the summary the first time its view is opened.
  useEffect(() => {
    if (view === 'summary') loadSummary();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [view]);

  // ── Register creation ──────────────────────────────────────────────────────

  const openCreateForm = () => {
    setNewRegisterData({
      register_name: suggestedName(undefined, 'meeting', today()),
      register_date: today(),
      register_type: 'meeting',
      activity_id: '',
    });
    // The unplanned-activity fallback is collapsed and cleared each time.
    setShowUnplannedForm(false);
    setUnplannedActivity({ title: '', activity_type_id: '', scheduled_at: today(), location_note: '' });
    setShowCreateForm(true);
  };

  /**
   * Choosing an activity preselects the occurrence's real name and date, so the
   * register is created against the activity rather than as a free-standing
   * duplicate of it.
   */
  const handleActivitySelection = (activityId: string) => {
    const activity = activities.find((a) => String(a.id) === activityId);

    // Selecting a planned activity means the unplanned fallback is not needed.
    if (activityId) setShowUnplannedForm(false);

    setNewRegisterData((prev) => ({
      ...prev,
      activity_id: activityId,
      register_name: activity ? activity.title : suggestedName(undefined, prev.register_type, prev.register_date),
      register_date: activity ? activity.scheduled_at.split('T')[0] : prev.register_date,
    }));
  };

  /**
   * Author an activity that GENUINELY arose on the day (is_planned = false) and
   * open a register against it.
   *
   * This is deliberately a fallback, not a shortcut: the copy tells the user to
   * check the planned list first, and the backend refuses a duplicate of an
   * existing occurrence. It returns the created (or, on a duplicate, existing)
   * activity so it can be attached to the register straight away.
   */
  const createUnplannedActivity = async (campaignId: number, siteId: number): Promise<number | null> => {
    if (!unplannedActivity.title.trim() || !unplannedActivity.activity_type_id) {
      setError('Give the unplanned activity a title and a type, or choose one from the planned list.');
      return null;
    }
    try {
      const created = await activitiesApi.create(campaignId, {
        campaign_id: campaignId,
        mission_site_id: siteId,
        activity_type_id: parseInt(unplannedActivity.activity_type_id),
        title: unplannedActivity.title.trim(),
        scheduled_at: unplannedActivity.scheduled_at,
        location_note: unplannedActivity.location_note.trim() || null,
        is_planned: false,
      });
      return created.id;
    } catch (err: any) {
      // A duplicate occurrence is refused by the backend (409) so a real
      // planned activity is never cloned. Surface that clearly and let the user
      // choose it from the list instead.
      setError(err.message || 'Failed to record the unplanned activity');
      return null;
    }
  };

  const handleCreateRegister = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!assignment?.campaign?.id || !assignment?.site?.id) {
      setError('You are not assigned to a mission site, so a register cannot be opened.');
      return;
    }

    setIsSubmitting(true);
    setError(null);

    try {
      let activityId = newRegisterData.activity_id;

      // A register against a newly authored activity is what makes the
      // unplanned flow "create then open against it".
      if (!activityId && showUnplannedForm) {
        const createdId = await createUnplannedActivity(assignment.campaign.id, assignment.site.id);
        if (createdId === null) {
          setIsSubmitting(false);
          return;
        }
        activityId = String(createdId);
      }

      const data = {
        campaign_id: assignment.campaign.id,
        mission_site_id: assignment.site.id,
        register_name: newRegisterData.register_name,
        register_date: newRegisterData.register_date,
        register_type: newRegisterData.register_type,
        ...(activityId ? { activity_id: parseInt(activityId) } : {}),
      };

      await attendanceApi.create(data);
      setShowCreateForm(false);
      loadRegisters();
      // A brand-new unplanned activity should appear in the picker next time.
      loadActivities();
    } catch (err: any) {
      setError(err.message || 'Failed to create register');
    } finally {
      setIsSubmitting(false);
    }
  };

  // ── Attendance marking ─────────────────────────────────────────────────────

  const handleToggleAttendance = (entryUuid: string, isPresent: boolean) => {
    setParticipants((rows) =>
      rows.map((r) => (r.entry_uuid === entryUuid ? { ...r, is_present: isPresent } : r))
    );
  };

  const resetAddForm = () => {
    setAddMode('name');
    setPersonSearch('');
    setPersonResults([]);
    setSelectedPerson(null);
    setNewName('');
    setNewPhone('');
    setNewGender('');
    setNewPerson({ first_name: '', last_name: '', phone: '', gender: '' });
    setAnonymousCount(1);
  };

  const searchPeople = async (term: string) => {
    setPersonSearch(term);
    if (term.trim().length < 2) {
      setPersonResults([]);
      return;
    }
    try {
      const response = await peopleApi.list({
        search: term,
        campaign_id: assignment?.campaign?.id,
        per_page: 8,
      });
      setPersonResults(response.data);
    } catch (err) {
      console.error('Failed to search people:', err);
    }
  };

  /**
   * Append one row per answer form chosen in the dialog.
   *
   * The "new person" form is the only one that writes a Person record: it
   * registers the person first (an identity action on its own) and then records
   * them as an ordinary `person_id` attendee. The other three forms never
   * create a Person.
   */
  const handleAddAttendees = async () => {
    const rows: DraftRow[] = [];

    const baseRow = {
      entry_id: null,
      entry_uuid: newUuid(),
      village: null,
      joined_date: null,
      is_on_roster: false,
      is_present: true,
      // Anyone added here arrived without prior registration for this
      // occurrence; the server stores the same determination at write time.
      is_walk_in: true,
      attendee_type: null,
      is_first_time: false,
      decision_made: null,
      notes: null,
      visitor_gender: newGender || null,
    };

    if (addMode === 'existing') {
      if (!selectedPerson) {
        setError('Select a person, or switch to "Name only".');
        return;
      }
      rows.push({
        ...baseRow,
        person_id: selectedPerson.id,
        name: selectedPerson.full_name,
        phone: selectedPerson.phone ?? null,
        is_anonymous: false,
        visitor_name: null,
      });
    } else if (addMode === 'new_person') {
      if (!newPerson.first_name.trim() || !newPerson.last_name.trim()) {
        setError('A new person needs at least a first and last name.');
        return;
      }
      setIsSubmitting(true);
      setError(null);
      try {
        // Register the person, then attach them by id. Registering is a pure
        // identity action — it creates no roster entry and no attendance.
        const created = await peopleApi.create({
          first_name: newPerson.first_name.trim(),
          last_name: newPerson.last_name.trim(),
          phone: newPerson.phone.trim() || undefined,
          gender: newPerson.gender || undefined,
          campaign_id: assignment?.campaign?.id,
          mission_site_id: assignment?.site?.id,
        });
        rows.push({
          ...baseRow,
          person_id: created.id,
          name: created.full_name,
          phone: created.phone ?? (newPerson.phone.trim() || null),
          is_anonymous: false,
          visitor_name: null,
          visitor_gender: newPerson.gender || null,
        });
      } catch (err: any) {
        setError(err.message || 'Failed to register the new person');
        return;
      } finally {
        setIsSubmitting(false);
      }
    } else if (addMode === 'name') {
      if (!newName.trim()) {
        setError('Enter the name the attendee gave, or record them as anonymous.');
        return;
      }
      rows.push({
        ...baseRow,
        person_id: null,
        name: newName.trim(),
        phone: newPhone || null,
        is_anonymous: false,
        visitor_name: newName.trim(),
      });
    } else {
      for (let i = 0; i < anonymousCount; i++) {
        rows.push({
          ...baseRow,
          entry_uuid: newUuid(),
          person_id: null,
          name: 'Anonymous attendee',
          phone: null,
          is_anonymous: true,
          visitor_name: null,
        });
      }
    }

    setParticipants((current) => [...current, ...rows]);
    setShowAddForm(false);
    resetAddForm();
    setError(null);
  };

  const handleRemoveRow = (entryUuid: string) => {
    setParticipants((rows) => rows.filter((r) => r.entry_uuid !== entryUuid));
  };

  const handleSaveAttendance = async () => {
    if (!selectedRegister) return;
    setIsSubmitting(true);
    setError(null);

    try {
      // Rows that were never recorded and remain absent have nothing to save.
      const entries: AttendanceEntryPayload[] = participants
        .filter((p) => p.is_present || p.entry_id !== null)
        .map((p) => ({
          entry_uuid: p.entry_uuid!,
          is_present: p.is_present,
          person_id: p.person_id ?? undefined,
          // For a named-but-unidentified row the participants endpoint hands
          // back only the RESOLVED `name`, not the raw visitor_name. Falling
          // back to `name` keeps the identity intact when a saved register is
          // reloaded and saved again — without it the row would arrive with no
          // person and no name and be rejected as unidentified.
          visitor_name: (p.is_anonymous || p.person_id !== null) ? undefined : (p.visitor_name ?? p.name),
          visitor_phone: p.is_anonymous ? undefined : (p.phone ?? undefined),
          visitor_gender: p.is_anonymous ? undefined : (p.visitor_gender ?? undefined),
          is_anonymous: p.is_anonymous,
          is_first_time: p.is_first_time,
          attendee_type: p.attendee_type ?? undefined,
          decision_made: p.decision_made ?? undefined,
          notes: p.notes ?? undefined,
        }));

      await attendanceApi.updateEntries(selectedRegister.id, { entries });

      // Reload so the checklist reflects exactly what the server stored.
      await loadParticipants(selectedRegister.id);
      loadRegisters();
    } catch (err: any) {
      setError(err.message || 'Failed to save attendance');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleSubmitRegister = async () => {
    if (!selectedRegister) return;
    setIsSubmitting(true);
    try {
      await attendanceApi.submit(selectedRegister.id);
      loadRegisters();
      setSelectedRegister(null);
      setParticipants([]);
      setObjectives([]);
      setReconciliation(null);
    } catch (err: any) {
      setError(err.message || 'Failed to submit register');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleReopenRegister = async () => {
    if (!selectedRegister) return;
    setIsSubmitting(true);
    try {
      await attendanceApi.reopen(selectedRegister.id);
      loadRegisters();
      loadParticipants(selectedRegister.id);
    } catch (err: any) {
      setError(err.message || 'Failed to reopen register');
    } finally {
      setIsSubmitting(false);
    }
  };

  const presentCount = participants.filter((p) => p.is_present).length;
  const rosterCount = participants.filter((p) => p.is_on_roster).length;

  return (
    <div className="p-4 md:p-6 max-w-5xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold text-slate-100">Fieldwork attendance</h1>
          <p className="text-sm text-slate-400 mt-0.5">
            Site presence and activity participation — recorded separately, never added together.
          </p>
        </div>
        <div className="flex items-center gap-2">
          {/* Two views: the occurrence registers, and read-only summaries. */}
          <div className="flex rounded-xl border border-surface-border overflow-hidden">
            <button
              onClick={() => setView('registers')}
              className={`px-3 py-2 text-sm font-medium transition-colors ${
                view === 'registers' ? 'bg-brand-600 text-white' : 'bg-surface-2 text-slate-400 hover:text-slate-200'
              }`}
            >
              Registers
            </button>
            <button
              onClick={() => { setView('summary'); setSelectedRegister(null); }}
              className={`px-3 py-2 text-sm font-medium transition-colors ${
                view === 'summary' ? 'bg-brand-600 text-white' : 'bg-surface-2 text-slate-400 hover:text-slate-200'
              }`}
            >
              Summary
            </button>
          </div>
          <button
            onClick={() => { setView('registers'); openCreateForm(); }}
            className="px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white rounded-xl font-medium transition-colors flex items-center gap-2"
          >
            <Plus className="w-4 h-4" />
            <span className="hidden sm:inline">New Register</span>
          </button>
        </div>
      </div>

      {/* Error */}
      {error && (
        <div className="p-3 bg-red-500/10 border border-red-500/20 rounded-xl text-red-400 text-sm">
          {error}
        </div>
      )}

      {/* Attendance summary (read-only) */}
      {!selectedRegister && view === 'summary' && (
        <SummaryView
          summary={summary}
          loading={summaryLoading}
          range={summaryRange}
          onRangeChange={setSummaryRange}
          onRefresh={loadSummary}
        />
      )}

      {/* Registers List */}
      {!selectedRegister && view === 'registers' && (
        <div className="space-y-4">
          {isLoading ? (
            <div className="flex items-center justify-center py-12">
              <Loader2 className="w-6 h-6 animate-spin text-slate-400" />
            </div>
          ) : registers.length === 0 ? (
            <div className="card p-8 border border-surface-border text-center">
              <Calendar className="w-12 h-12 mx-auto mb-3 text-slate-500" />
              <h2 className="text-xl font-semibold text-slate-100 mb-2">No Attendance Registers</h2>
              <p className="text-slate-400 text-sm mb-4">Create a register to start taking attendance</p>
              <button
                onClick={openCreateForm}
                className="px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white rounded-xl font-medium transition-colors"
              >
                Create Register
              </button>
            </div>
          ) : (
            <div className="space-y-3">
              {registers.map((register) => (
                <div
                  key={register.id}
                  className="card p-4 border border-surface-border hover:border-surface-border/50 transition-colors cursor-pointer"
                  onClick={() => openRegister(register)}
                >
                  <div className="flex items-center justify-between">
                    <div className="flex-1">
                      <div className="flex items-center gap-2 mb-1 flex-wrap">
                        <h3 className="font-medium text-slate-100">{register.register_name}</h3>
                        {/* Which of the two independent records this is. */}
                        <span className="px-2 py-0.5 text-xs font-medium rounded bg-surface-3 text-slate-300">
                          {register.activity_id !== null ? 'Activity participation' : 'Site attendance'}
                        </span>
                        <span className={`px-2 py-0.5 text-xs font-medium rounded ${
                          register.status === 'open' ? 'bg-emerald-500/20 text-emerald-400' :
                          register.status === 'submitted' ? 'bg-blue-500/20 text-blue-400' :
                          'bg-purple-500/20 text-purple-400'
                        }`}>
                          {register.status}
                        </span>
                      </div>
                      <div className="flex items-center gap-4 text-sm text-slate-400">
                        <div className="flex items-center gap-1">
                          <Calendar className="w-3 h-3" />
                          <span>{new Date(register.register_date).toLocaleDateString()}</span>
                        </div>
                        {register.activity && (
                          <div className="flex items-center gap-1">
                            <MapPin className="w-3 h-3" />
                            <span>{register.activity.title}</span>
                          </div>
                        )}
                        <div className="flex items-center gap-1">
                          <User className="w-3 h-3" />
                          <span>{register.entries?.length || 0} entries</span>
                        </div>
                      </div>
                    </div>
                    <ChevronRight className="w-5 h-5 text-slate-400" />
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Attendance Checklist — one occurrence */}
      {selectedRegister && (
        <div className="space-y-4">
          <div className="flex items-center gap-2">
            <button
              onClick={() => {
                setSelectedRegister(null);
                setParticipants([]);
                setObjectives([]);
                setReconciliation(null);
              }}
              className="p-2 rounded-lg hover:bg-surface-3/50 transition-colors text-slate-400"
            >
              <ChevronRight className="w-5 h-5 -rotate-90" />
            </button>
            <div>
              <h2 className="text-lg font-semibold text-slate-100">{selectedRegister.register_name}</h2>
              <p className="text-sm text-slate-400">
                {new Date(selectedRegister.register_date).toLocaleDateString()}
                {selectedRegister.activity
                  ? ` • Activity participation: ${selectedRegister.activity.title}`
                  : ' • Site presence (Global Site Attendance)'}
              </p>
            </div>
          </div>

          <div className="card border border-surface-border overflow-hidden">
            <div className="p-4 border-b border-surface-border flex items-center justify-between bg-surface-2">
              <div className="flex items-center gap-2">
                <span className="text-sm text-slate-400">
                  {presentCount} / {participants.length} present
                </span>
                {rosterCount > 0 && (
                  <span className="text-xs text-slate-500">({rosterCount} expected)</span>
                )}
              </div>
              <div className="flex gap-2">
                {selectedRegister.status === 'open' && (
                  <>
                    <button
                      onClick={() => { resetAddForm(); setShowAddForm(true); }}
                      className="px-3 py-1.5 bg-surface-3 hover:bg-surface-3/80 text-slate-100 rounded-lg text-sm font-medium transition-colors flex items-center gap-1.5"
                    >
                      <UserPlus className="w-4 h-4" />
                      Add attendee
                    </button>
                    <button
                      onClick={handleSaveAttendance}
                      disabled={isSubmitting}
                      className="px-3 py-1.5 bg-brand-600 hover:bg-brand-500 text-white rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                      Save
                    </button>
                    <button
                      onClick={handleSubmitRegister}
                      disabled={isSubmitting || presentCount === 0}
                      className="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                      Submit
                    </button>
                  </>
                )}
                {selectedRegister.status === 'submitted' && (
                  <button
                    onClick={handleReopenRegister}
                    className="px-3 py-1.5 bg-surface-3 hover:bg-surface-3/80 text-slate-100 rounded-lg text-sm font-medium transition-colors"
                  >
                    Reopen
                  </button>
                )}
              </div>
            </div>

            <div className="divide-y divide-surface-border max-h-[60vh] overflow-y-auto">
              {participants.length === 0 ? (
                <div className="p-8 text-center text-slate-400">
                  <p>Nobody is listed yet.</p>
                  <p className="text-xs mt-1">
                    Add each attendee — an existing person, a new person, a name only, or an
                    anonymised count. Nobody is forced into a group when the activity is open to
                    everyone present.
                  </p>
                </div>
              ) : (
                participants.map((participant) => (
                  <div
                    key={participant.entry_uuid}
                    className="p-4 hover:bg-surface-3/30 transition-colors flex items-center gap-4"
                  >
                    <button
                      onClick={() => selectedRegister.status === 'open' && handleToggleAttendance(participant.entry_uuid!, !participant.is_present)}
                      disabled={selectedRegister.status !== 'open'}
                      className={`w-6 h-6 rounded border-2 flex items-center justify-center transition-colors ${
                        participant.is_present
                          ? 'bg-brand-500 border-brand-500 text-white'
                          : 'border-slate-500 hover:border-brand-400'
                      } ${selectedRegister.status !== 'open' ? 'opacity-50 cursor-not-allowed' : ''}`}
                    >
                      {participant.is_present && <Check className="w-4 h-4" />}
                    </button>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 mb-1 flex-wrap">
                        <h4 className={`font-medium truncate ${participant.is_anonymous ? 'text-slate-400 italic' : 'text-slate-100'}`}>
                          {participant.name}
                        </h4>
                        {participant.is_on_roster && (
                          <span className="px-1.5 py-0.5 text-xs bg-slate-500/20 text-slate-300 rounded">Expected</span>
                        )}
                        {participant.is_present && participant.is_walk_in && (
                          <span className="px-1.5 py-0.5 text-xs bg-amber-500/20 text-amber-400 rounded">Walk-in</span>
                        )}
                        {participant.is_present && participant.is_anonymous && (
                          <span className="px-1.5 py-0.5 text-xs bg-slate-500/20 text-slate-400 rounded">Anonymous</span>
                        )}
                        {participant.is_present && !participant.person_id && !participant.is_anonymous && (
                          <span className="px-1.5 py-0.5 text-xs bg-blue-500/20 text-blue-400 rounded">Name only</span>
                        )}
                        {participant.is_first_time && (
                          <span className="px-1.5 py-0.5 text-xs bg-purple-500/20 text-purple-400 rounded">New</span>
                        )}
                      </div>
                      {participant.village && (
                        <div className="flex items-center gap-1 text-sm text-slate-400">
                          <MapPin className="w-3 h-3" />
                          <span className="truncate">{participant.village}</span>
                        </div>
                      )}
                    </div>
                    {selectedRegister.status === 'open' && (
                      <button
                        onClick={() => handleRemoveRow(participant.entry_uuid!)}
                        title="Remove from this register"
                        className="p-1.5 rounded-lg hover:bg-surface-3/60 text-slate-500 hover:text-slate-300 transition-colors"
                      >
                        <X className="w-4 h-4" />
                      </button>
                    )}
                  </div>
                ))
              )}
            </div>
          </div>

          {/* Objectives — belong to the ACTIVITY, never to this register. */}
          {selectedRegister.activity_id !== null && (
            <div className="card border border-surface-border">
              <div className="p-4 border-b border-surface-border flex items-center justify-between bg-surface-2 gap-3 flex-wrap">
                <div className="flex items-center gap-2">
                  <Target className="w-4 h-4 text-brand-400 shrink-0" />
                  <div>
                    <h3 className="text-sm font-semibold text-slate-100">What this activity set out to achieve</h3>
                    <p className="text-xs text-slate-500">
                      The activity&apos;s objectives. They belong to the activity itself — this register only shows them.
                    </p>
                  </div>
                </div>
                {canManageObjectives && !showObjectiveForm && (
                  <button
                    onClick={() => { resetObjectiveForm(); setShowObjectiveForm(true); }}
                    className="px-3 py-1.5 bg-surface-3 hover:bg-surface-3/80 text-slate-100 rounded-lg text-sm font-medium transition-colors flex items-center gap-1.5"
                  >
                    <Plus className="w-4 h-4" />
                    Add objective
                  </button>
                )}
              </div>

              <div className="p-4 space-y-2">
                {objectivesLoading ? (
                  <div className="flex items-center justify-center py-4">
                    <Loader2 className="w-5 h-5 animate-spin text-slate-400" />
                  </div>
                ) : objectives.length === 0 && !showObjectiveForm ? (
                  <p className="text-sm text-slate-500">
                    No objectives have been recorded for this activity yet.
                  </p>
                ) : (
                  objectives.map((objective) => (
                    <div key={objective.id} className="flex items-start gap-3 p-3 rounded-xl bg-surface-2 border border-surface-border">
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium text-slate-100">{objective.title}</p>
                        {objective.description && (
                          <p className="text-xs text-slate-400 mt-0.5">{objective.description}</p>
                        )}
                      </div>
                      {canManageObjectives && (
                        <div className="flex gap-1 shrink-0">
                          <button
                            onClick={() => startEditObjective(objective)}
                            title="Edit objective"
                            className="p-1.5 rounded-lg hover:bg-surface-3/60 text-slate-500 hover:text-slate-300 transition-colors"
                          >
                            <Pencil className="w-3.5 h-3.5" />
                          </button>
                          <button
                            onClick={() => handleRemoveObjective(objective.id)}
                            title="Remove objective"
                            className="p-1.5 rounded-lg hover:bg-surface-3/60 text-slate-500 hover:text-red-400 transition-colors"
                          >
                            <Trash2 className="w-3.5 h-3.5" />
                          </button>
                        </div>
                      )}
                    </div>
                  ))
                )}

                {showObjectiveForm && canManageObjectives && (
                  <div className="p-3 rounded-xl bg-surface-2 border border-surface-border space-y-3">
                    <div>
                      <label className="block text-xs font-medium text-slate-300 mb-1">Title</label>
                      <input
                        type="text"
                        value={objectiveTitle}
                        onChange={(e) => setObjectiveTitle(e.target.value)}
                        placeholder="e.g., Share the gospel with every household"
                        className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                      />
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-slate-300 mb-1">Details (optional)</label>
                      <textarea
                        value={objectiveDescription}
                        onChange={(e) => setObjectiveDescription(e.target.value)}
                        rows={2}
                        className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400 resize-none"
                      />
                    </div>
                    <div className="flex gap-2">
                      <button
                        onClick={resetObjectiveForm}
                        className="flex-1 px-3 py-2 bg-surface-3 hover:bg-surface-3/80 text-slate-100 rounded-lg text-sm font-medium transition-colors"
                      >
                        Cancel
                      </button>
                      <button
                        onClick={handleSaveObjective}
                        disabled={isSubmitting}
                        className="flex-1 px-3 py-2 bg-brand-600 hover:bg-brand-500 text-white rounded-lg text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                      >
                        {editingObjectiveId !== null ? 'Update' : 'Add'}
                      </button>
                    </div>
                  </div>
                )}
              </div>
            </div>
          )}

          {/* Reconciliation — read-only; never mutates a record. */}
          <div className="card border border-surface-border">
            <div className="p-4 border-b border-surface-border flex items-center justify-between bg-surface-2 gap-3 flex-wrap">
              <div className="flex items-center gap-2">
                <GitCompare className="w-4 h-4 text-brand-400 shrink-0" />
                <div>
                  <h3 className="text-sm font-semibold text-slate-100">Site presence and activity participation</h3>
                  <p className="text-xs text-slate-500">
                    Who is accounted for across this site&apos;s registers on this day. A read-only
                    comparison — nothing here is changed.
                  </p>
                </div>
              </div>
              <button
                onClick={loadReconciliation}
                disabled={reconciliationLoading}
                className="px-3 py-1.5 bg-surface-3 hover:bg-surface-3/80 text-slate-100 rounded-lg text-sm font-medium transition-colors flex items-center gap-1.5 disabled:opacity-50"
              >
                {reconciliationLoading && <Loader2 className="w-4 h-4 animate-spin" />}
                {reconciliation ? 'Refresh' : 'Check'}
              </button>
            </div>
            {reconciliation && <ReconciliationPanel data={reconciliation} />}
          </div>
        </div>
      )}

      {/* Create Register Modal */}
      {showCreateForm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="bg-surface-2 w-full max-w-md rounded-2xl max-h-[90vh] overflow-y-auto">
            <div className="sticky top-0 bg-surface-2 border-b border-surface-border p-4 flex items-center justify-between">
              <h3 className="font-semibold text-slate-100">New Attendance Register</h3>
              <button
                onClick={() => setShowCreateForm(false)}
                className="p-2 rounded-lg hover:bg-surface-3/50 transition-colors text-slate-400"
              >
                <X className="w-5 h-5" />
              </button>
            </div>
            <form onSubmit={handleCreateRegister} className="p-4 space-y-4">
              <div>
                <label className="block text-sm font-medium text-slate-100 mb-1">Register for</label>
                <select
                  value={newRegisterData.activity_id}
                  onChange={(e) => handleActivitySelection(e.target.value)}
                  className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                >
                  <option value="">Site presence — standalone roll call…</option>
                  {activities.map((activity) => (
                    <option key={activity.id} value={activity.id}>
                      Activity participation — {activity.title} · {new Date(activity.scheduled_at).toLocaleDateString()}
                    </option>
                  ))}
                </select>
                <p className="text-xs text-slate-500 mt-1">
                  A standalone register records SITE PRESENCE. Choosing an activity records
                  PARTICIPATION in that activity — its real name and date are filled in so the
                  occurrence is not duplicated. The two are reported separately.
                </p>
              </div>

              {/* Unplanned activity: a genuine fallback, shown only to users who
                  may author activities, and only because the activity is not in
                  the planned list above. */}
              {canCreateActivity && (
                <div className="rounded-xl border border-surface-border bg-surface-3/40 p-3">
                  <button
                    type="button"
                    onClick={() => setShowUnplannedForm((v) => !v)}
                    className="w-full flex items-center justify-between text-left"
                  >
                    <span className="flex items-center gap-2 text-sm font-medium text-slate-200">
                      <CalendarPlus className="w-4 h-4 text-brand-400" />
                      The activity is not listed?
                    </span>
                    <ChevronRight className={`w-4 h-4 text-slate-400 transition-transform ${showUnplannedForm ? 'rotate-90' : ''}`} />
                  </button>
                  <p className="text-xs text-slate-500 mt-1">
                    Use this only for an activity that genuinely arose on the day. Check the list
                    above first — recording the same activity twice is refused.
                  </p>

                  {showUnplannedForm && (
                    <div className="mt-3 space-y-3">
                      <div>
                        <label className="block text-xs font-medium text-slate-300 mb-1">Activity title *</label>
                        <input
                          type="text"
                          value={unplannedActivity.title}
                          onChange={(e) => setUnplannedActivity({ ...unplannedActivity, title: e.target.value })}
                          placeholder="e.g., Impromptu street meeting"
                          className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                        />
                      </div>
                      <div className="grid grid-cols-2 gap-3">
                        <div>
                          <label className="block text-xs font-medium text-slate-300 mb-1">Type *</label>
                          <select
                            value={unplannedActivity.activity_type_id}
                            onChange={(e) => setUnplannedActivity({ ...unplannedActivity, activity_type_id: e.target.value })}
                            className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                          >
                            <option value="">Choose a type…</option>
                            {activityTypes.map((type) => (
                              <option key={type.id} value={type.id}>{type.label}</option>
                            ))}
                          </select>
                        </div>
                        <div>
                          <label className="block text-xs font-medium text-slate-300 mb-1">When *</label>
                          <input
                            type="date"
                            value={unplannedActivity.scheduled_at}
                            onChange={(e) => setUnplannedActivity({ ...unplannedActivity, scheduled_at: e.target.value })}
                            className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                          />
                        </div>
                      </div>
                      <p className="text-xs text-slate-500">
                        This activity is recorded as unplanned, so it stays distinguishable from the
                        campaign programme. The register opens against it once created.
                      </p>
                    </div>
                  )}
                </div>
              )}

              <div>
                <label className="block text-sm font-medium text-slate-100 mb-1">Register Name *</label>
                <input
                  type="text"
                  required
                  value={newRegisterData.register_name}
                  onChange={(e) => setNewRegisterData({ ...newRegisterData, register_name: e.target.value })}
                  placeholder="e.g., Morning Meeting"
                  className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-100 mb-1">Date *</label>
                <input
                  type="date"
                  required
                  value={newRegisterData.register_date}
                  onChange={(e) => setNewRegisterData({ ...newRegisterData, register_date: e.target.value })}
                  className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-100 mb-1">Type</label>
                <select
                  value={newRegisterData.register_type}
                  onChange={(e) => setNewRegisterData({ ...newRegisterData, register_type: e.target.value })}
                  className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                >
                  {REGISTER_TYPES.map((type) => (
                    <option key={type.value} value={type.value}>{type.label}</option>
                  ))}
                </select>
              </div>

              <div className="flex gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setShowCreateForm(false)}
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
                  Create
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Add Attendee Modal */}
      {showAddForm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="bg-surface-2 w-full max-w-md rounded-2xl max-h-[90vh] overflow-y-auto">
            <div className="sticky top-0 bg-surface-2 border-b border-surface-border p-4 flex items-center justify-between">
              <h3 className="font-semibold text-slate-100">Add attendee</h3>
              <button
                onClick={() => { setShowAddForm(false); resetAddForm(); }}
                className="p-2 rounded-lg hover:bg-surface-3/50 transition-colors text-slate-400"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="p-4 space-y-4">
              {/* Identity form selector — four explicit forms. */}
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                {([
                  { key: 'existing', label: 'Existing', icon: Search },
                  { key: 'new_person', label: 'New person', icon: UserCog },
                  { key: 'name', label: 'Name only', icon: User },
                  { key: 'anonymous', label: 'Anonymous', icon: Users },
                ] as Array<{ key: AddMode; label: string; icon: typeof User }>).map(({ key, label, icon: Icon }) => (
                  <button
                    key={key}
                    type="button"
                    onClick={() => setAddMode(key)}
                    className={`px-2 py-2 rounded-xl border text-xs font-medium flex flex-col items-center gap-1 transition-colors ${
                      addMode === key
                        ? 'border-brand-400 bg-brand-500/10 text-brand-300'
                        : 'border-surface-border text-slate-400 hover:text-slate-200'
                    }`}
                  >
                    <Icon className="w-4 h-4" />
                    {label}
                  </button>
                ))}
              </div>

              {addMode === 'existing' && (
                <div className="space-y-3">
                  <input
                    type="text"
                    value={personSearch}
                    onChange={(e) => searchPeople(e.target.value)}
                    placeholder="Search by name or phone…"
                    className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                  />
                  <div className="max-h-56 overflow-y-auto space-y-1">
                    {personResults.map((person) => (
                      <button
                        key={person.id}
                        type="button"
                        onClick={() => setSelectedPerson(person)}
                        className={`w-full text-left px-3 py-2 rounded-lg text-sm transition-colors ${
                          selectedPerson?.id === person.id
                            ? 'bg-brand-500/15 text-brand-300'
                            : 'hover:bg-surface-3/60 text-slate-200'
                        }`}
                      >
                        <span className="font-medium">{person.full_name}</span>
                        {person.phone && <span className="text-slate-500"> · {person.phone}</span>}
                      </button>
                    ))}
                    {personSearch.trim().length >= 2 && personResults.length === 0 && (
                      <p className="text-xs text-slate-500 px-1">
                        No matching person. Use “New person” to register them, or “Name only” to
                        record them without creating a profile.
                      </p>
                    )}
                  </div>
                </div>
              )}

              {/* Fourth form: register a NEW identifiable Person, then record
                  them by person_id. This is the only form that creates a Person. */}
              {addMode === 'new_person' && (
                <div className="space-y-3">
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-sm font-medium text-slate-100 mb-1">First name *</label>
                      <input
                        type="text"
                        value={newPerson.first_name}
                        onChange={(e) => setNewPerson({ ...newPerson, first_name: e.target.value })}
                        className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-slate-100 mb-1">Last name *</label>
                      <input
                        type="text"
                        value={newPerson.last_name}
                        onChange={(e) => setNewPerson({ ...newPerson, last_name: e.target.value })}
                        className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                      />
                    </div>
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-sm font-medium text-slate-100 mb-1">Phone (optional)</label>
                      <input
                        type="text"
                        value={newPerson.phone}
                        onChange={(e) => setNewPerson({ ...newPerson, phone: e.target.value })}
                        className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-slate-100 mb-1">Gender (optional)</label>
                      <select
                        value={newPerson.gender}
                        onChange={(e) => setNewPerson({ ...newPerson, gender: e.target.value })}
                        className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                      >
                        <option value="">Not recorded</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="unspecified">Unspecified</option>
                      </select>
                    </div>
                  </div>
                  <p className="text-xs text-slate-500">
                    This registers a permanent person record and records them here. Use “Name only”
                    if you do not want to create a profile.
                  </p>
                </div>
              )}

              {addMode === 'name' && (
                <div className="space-y-3">
                  <div>
                    <label className="block text-sm font-medium text-slate-100 mb-1">Name given</label>
                    <input
                      type="text"
                      value={newName}
                      onChange={(e) => setNewName(e.target.value)}
                      placeholder="e.g., Grace (neighbour of the pastor)"
                      className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                    />
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-sm font-medium text-slate-100 mb-1">Phone (optional)</label>
                      <input
                        type="text"
                        value={newPhone}
                        onChange={(e) => setNewPhone(e.target.value)}
                        className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-slate-100 mb-1">Gender (optional)</label>
                      <select
                        value={newGender}
                        onChange={(e) => setNewGender(e.target.value)}
                        className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                      >
                        <option value="">Not recorded</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="unspecified">Unspecified</option>
                      </select>
                    </div>
                  </div>
                  <p className="text-xs text-slate-500">
                    No person profile is created. This can be linked to a person later.
                  </p>
                </div>
              )}

              {addMode === 'anonymous' && (
                <div className="space-y-3">
                  <div>
                    <label className="block text-sm font-medium text-slate-100 mb-1">How many?</label>
                    <input
                      type="number"
                      min={1}
                      max={100}
                      value={anonymousCount}
                      onChange={(e) => setAnonymousCount(Math.max(1, Math.min(100, parseInt(e.target.value) || 1)))}
                      className="w-28 px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
                    />
                  </div>
                  <p className="text-xs text-slate-500">
                    One anonymous attendee is counted per unit. No name is recorded and none is invented.
                  </p>
                </div>
              )}

              <div className="flex gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => { setShowAddForm(false); resetAddForm(); }}
                  className="flex-1 px-4 py-2 bg-surface-3 hover:bg-surface-3/80 text-slate-100 rounded-xl font-medium transition-colors"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  onClick={handleAddAttendees}
                  className="flex-1 px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white rounded-xl font-medium transition-colors"
                >
                  Add
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

/**
 * A compact grid of entry counts for ONE attendance fact.
 *
 * The same grid is used for site presence and for activity participation so the
 * two are read in exactly the same way — they are simply never added together.
 */
function CountGrid({ counts }: { counts: AttendanceSummaryCounts }) {
  const items: Array<{ label: string; value: number }> = [
    { label: 'Identified', value: counts.identified },
    { label: 'Name only', value: counts.named_unidentified },
    { label: 'Anonymous', value: counts.anonymous },
    { label: 'Walk-in', value: counts.walk_in },
    { label: 'First time', value: counts.first_time },
    { label: 'Decisions recorded', value: counts.decisions_recorded },
  ];
  return (
    <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
      {items.map((item) => (
        <div
          key={item.label}
          className="rounded-xl bg-surface-3/60 border border-surface-border px-3 py-2"
        >
          <p className="text-xs text-slate-500">{item.label}</p>
          <p className="text-lg font-semibold text-slate-100">{item.value}</p>
        </div>
      ))}
    </div>
  );
}

/**
 * Read-only attendance summary.
 *
 * SITE presence and ACTIVITY participation are two independent facts, so they
 * are shown side by side and are NEVER summed. A person counted at the site and
 * again inside an activity appears in both — a single total would double-count
 * them. Nothing here edits a record.
 */
function SummaryView({
  summary,
  loading,
  range,
  onRangeChange,
  onRefresh,
}: {
  summary: AttendanceSummary | null;
  loading: boolean;
  range: { date_from: string; date_to: string };
  onRangeChange: (range: { date_from: string; date_to: string }) => void;
  onRefresh: () => void;
}) {
  return (
    <div className="space-y-4">
      {/* Date range + refresh */}
      <div className="card border border-surface-border p-4 flex flex-wrap items-end gap-3">
        <div>
          <label className="block text-xs font-medium text-slate-400 mb-1">From</label>
          <input
            type="date"
            value={range.date_from}
            onChange={(e) => onRangeChange({ ...range, date_from: e.target.value })}
            className="px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
          />
        </div>
        <div>
          <label className="block text-xs font-medium text-slate-400 mb-1">To</label>
          <input
            type="date"
            value={range.date_to}
            onChange={(e) => onRangeChange({ ...range, date_to: e.target.value })}
            className="px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-400"
          />
        </div>
        <button
          onClick={onRefresh}
          disabled={loading}
          className="px-4 py-2 bg-surface-3 hover:bg-surface-3/80 text-slate-100 rounded-lg text-sm font-medium transition-colors flex items-center gap-2 disabled:opacity-50"
        >
          {loading && <Loader2 className="w-4 h-4 animate-spin" />}
          Refresh
        </button>
      </div>

      {loading && !summary ? (
        <div className="flex items-center justify-center py-12">
          <Loader2 className="w-6 h-6 animate-spin text-slate-400" />
        </div>
      ) : !summary ? (
        <EmptyState
          icon={<ListChecks className="w-6 h-6" />}
          title="No summary yet"
          body="Choose a date range and refresh to see attendance."
        />
      ) : (
        <>
          {/* Site presence — Global Site Attendance */}
          <div className="card border border-surface-border p-4">
            <div className="flex items-center gap-2 mb-1">
              <MapPin className="w-4 h-4 text-brand-400 shrink-0" />
              <h3 className="text-sm font-semibold text-slate-100">Site presence</h3>
            </div>
            <p className="text-xs text-slate-500 mb-3">
              Global Site Attendance — everyone present at the site, across
              {' '}{summary.site_attendance.registers} standalone register
              {summary.site_attendance.registers === 1 ? '' : 's'}.
            </p>
            <CountGrid counts={summary.site_attendance} />
          </div>

          {/* Activity participation */}
          <div className="card border border-surface-border p-4">
            <div className="flex items-center gap-2 mb-1">
              <ListChecks className="w-4 h-4 text-brand-400 shrink-0" />
              <h3 className="text-sm font-semibold text-slate-100">Activity participation</h3>
            </div>
            <p className="text-xs text-slate-500 mb-3">
              People taking part in individual activities, across
              {' '}{summary.activity_attendance.registers} activity register
              {summary.activity_attendance.registers === 1 ? '' : 's'}.
            </p>
            <CountGrid counts={summary.activity_attendance} />

            {summary.by_activity.length > 0 && (
              <div className="mt-4 space-y-2">
                <p className="section-label">By activity</p>
                {summary.by_activity.map((activity) => (
                  <div
                    key={activity.activity_id}
                    className="rounded-xl bg-surface-3/40 border border-surface-border px-3 py-2 flex items-center justify-between gap-3"
                  >
                    <span className="text-sm text-slate-200 truncate">
                      {activity.title ?? 'Untitled activity'}
                    </span>
                    <span className="text-xs text-slate-500 shrink-0">
                      {activity.entries} entr{activity.entries === 1 ? 'y' : 'ies'}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* The server's reminder that the two totals are never combined. */}
          {summary.note && (
            <p className="text-xs text-slate-500 px-1">{summary.note}</p>
          )}
        </>
      )}
    </div>
  );
}

/** Human wording for where a person was seen. Never says "absent". */
function reconciliationStateLabel(state: AttendanceReconciliation['people'][number]['state']): string {
  switch (state) {
    case 'site_only':
      return 'Site only';
    case 'activity_only':
      return 'Activity only';
    case 'site_and_activity':
      return 'Site and activity';
    default:
      return '';
  }
}

/**
 * Read-only reconciliation panel.
 *
 * Compares who was recorded at the SITE with who was recorded inside each
 * ACTIVITY on the same day. It is a pure read: no record is created, changed or
 * removed. A person who is in an activity but not in the site register is shown
 * as "Not recorded" and flagged for Site Secretary review — NEVER as absent.
 */
function ReconciliationPanel({ data }: { data: AttendanceReconciliation }) {
  const hasUnmatched =
    data.unmatched.site.anonymous > 0 ||
    data.unmatched.site.named_unidentified > 0 ||
    data.unmatched.activity.anonymous > 0 ||
    data.unmatched.activity.named_unidentified > 0;

  return (
    <div className="p-4 space-y-4">
      {/* Submitted registers that have since seen attendance changes. */}
      {data.unreconciled.is_unreconciled && (
        <div className="flex items-start gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2">
          <AlertTriangle className="w-4 h-4 text-amber-400 mt-0.5 shrink-0" />
          <p className="text-xs text-amber-300">
            {data.unreconciled.message ??
              `Attendance changed after this register was submitted. ${data.unreconciled.pending_count} change(s) are not yet reconciled.`}
          </p>
        </div>
      )}

      {/* Identifiable people seen across the day's registers. */}
      {data.people.length === 0 ? (
        <p className="text-sm text-slate-500">
          No identifiable people are recorded across this site&apos;s registers on this day.
        </p>
      ) : (
        <div className="space-y-2">
          <p className="section-label">Identifiable people across this day</p>
          {data.people.map((person) => (
            <div
              key={person.person_id}
              className="rounded-xl bg-surface-3/40 border border-surface-border p-3"
            >
              <div className="flex items-center justify-between gap-2">
                <span className="text-sm text-slate-100 truncate">
                  {person.name ?? 'Unnamed person'}
                </span>
                <span className="text-[11px] px-2 py-0.5 rounded-full bg-surface-2 border border-surface-border text-slate-400 shrink-0">
                  {reconciliationStateLabel(person.state)}
                </span>
              </div>
              <p className="text-xs text-slate-500 mt-1">
                Site register: {person.in_site_register ? 'Recorded' : 'Not recorded'}
              </p>
              <p className="text-xs text-slate-500">
                Activity participation:{' '}
                {person.in_activity_registers.length > 0
                  ? person.in_activity_registers
                      .map((r) => r.activity_title ?? 'Untitled activity')
                      .join(', ')
                  : 'Not recorded'}
              </p>
            </div>
          ))}
        </div>
      )}

      {/* People in an activity but not in the site register — for review only. */}
      {data.missing_from_site_register.length > 0 && (
        <div className="rounded-xl border border-amber-500/30 bg-amber-500/5 p-3">
          <div className="flex items-center gap-2 mb-1">
            <AlertTriangle className="w-4 h-4 text-amber-400 shrink-0" />
            <p className="text-sm font-medium text-amber-300">For Site Secretary review</p>
          </div>
          <p className="text-xs text-slate-400 mb-2">
            These people took part in an activity but are not recorded in this day&apos;s site
            register. This is flagged for review — it does not mean they were absent.
          </p>
          <ul className="space-y-1">
            {data.missing_from_site_register.map((person) => (
              <li
                key={person.person_id}
                className="flex items-center justify-between gap-3 text-sm"
              >
                <span className="text-slate-200 truncate">
                  {person.name ?? 'Unnamed person'}
                </span>
                <span className="text-xs text-slate-500 truncate">
                  {person.in_activity_registers
                    .map((r) => r.activity_title ?? 'Untitled activity')
                    .join(', ')}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {/* Attendance that cannot be tied to a person — counted, not ignored. */}
      {hasUnmatched && (
        <div className="rounded-xl border border-surface-border bg-surface-3/30 p-3">
          <p className="section-label">Unmatched attendance</p>
          <p className="text-xs text-slate-500 mb-2">
            Attendance that cannot be tied to a person by identity. It is counted here rather
            than silently dropped.
          </p>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <p className="text-xs text-slate-500">Site</p>
              <p className="text-sm text-slate-200">
                {data.unmatched.site.anonymous} anonymous ·{' '}
                {data.unmatched.site.named_unidentified} name only
              </p>
            </div>
            <div>
              <p className="text-xs text-slate-500">Activities</p>
              <p className="text-sm text-slate-200">
                {data.unmatched.activity.anonymous} anonymous ·{' '}
                {data.unmatched.activity.named_unidentified} name only
              </p>
            </div>
          </div>
        </div>
      )}

      {/* What was compared, in plain words. */}
      <p className="text-xs text-slate-500">
        Compared {data.site_registers.length} site register
        {data.site_registers.length === 1 ? '' : 's'} and {data.activity_registers.length} activity
        register{data.activity_registers.length === 1 ? '' : 's'} on {data.register_date}.
      </p>
    </div>
  );
}
