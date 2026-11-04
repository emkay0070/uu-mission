/**
 * api.ts — typed HTTP client for the UU Mission backend.
 *
 * - All requests go through /api/v1 (proxied by Next.js to localhost:8100).
 * - The Sanctum bearer token is read from the auth store on every request.
 * - 401 responses clear the session and redirect to /login.
 * - Validation errors (422) are returned as ApiError with .errors map.
 */

export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
  status: number;
}

const BASE = '/api/v1';

/** Guard against multiple parallel 401s each triggering a redirect. */
let redirectingTo401 = false;

function getToken(): string | null {
  if (typeof window === 'undefined') return null;
  return localStorage.getItem('uu_token');
}

async function request<T>(
  path: string,
  options: RequestInit = {}
): Promise<T> {
  const token = getToken();
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...(options.headers as Record<string, string> | undefined),
  };

  const res = await fetch(`${BASE}${path}`, { ...options, headers });

  if (res.status === 401) {
    localStorage.removeItem('uu_token');
    if (!redirectingTo401) {
      redirectingTo401 = true;
      window.location.href = '/login';
    }
    throw { message: 'Session expired', status: 401 } as ApiError;
  }

  const body = res.headers.get('content-type')?.includes('application/json')
    ? await res.json()
    : null;

  if (!res.ok) {
    throw {
      message: body?.message ?? `Request failed (${res.status})`,
      errors: body?.errors,
      status: res.status,
    } as ApiError;
  }

  return body as T;
}

export const api = {
  get:    <T>(path: string) => request<T>(path, { method: 'GET' }),
  post:   <T>(path: string, data?: unknown) =>
    request<T>(path, { method: 'POST', body: JSON.stringify(data) }),
  patch:  <T>(path: string, data?: unknown) =>
    request<T>(path, { method: 'PATCH', body: JSON.stringify(data) }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
};

/**
 * Build a URL query string from a params object.
 *
 * Values that are `undefined`, `null` or an empty string are OMITTED entirely.
 * This matters because blindly stringifying them produces literal values like
 * `campaign_id=undefined`, which the backend rejects with a 422 validation
 * error ("The campaign id field must be an integer"). Booleans and numbers are
 * serialized normally; arrays are repeated as multiple key entries.
 *
 * @returns `''` when nothing survives filtering, otherwise `'?a=1&b=2'`.
 */
function buildQuery(
  params?: Record<string, unknown> | null
): string {
  if (!params) return '';

  const search = new URLSearchParams();

  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue;

    if (Array.isArray(value)) {
      for (const item of value) {
        if (item === undefined || item === null || item === '') continue;
        search.append(key, String(item));
      }
      continue;
    }

    search.set(key, String(value));
  }

  const q = search.toString();

  return q ? `?${q}` : '';
}

// ── Auth ────────────────────────────────────────────────────────────────────

/**
 * A single active role assignment, as resolved by the backend's
 * AuthorizationService. Exactly one of the scope id fields is populated
 * for non-global scopes.
 */
export interface AuthScope {
  role: string | null;
  role_label: string | null;
  scope_type: 'global' | 'org_unit' | 'campaign' | 'site' | 'team';
  org_unit_id: number | null;
  campaign_id: number | null;
  site_id: number | null;
  team_id: number | null;
  valid_from: string | null;
  valid_until: string | null;
}

export interface AuthUser {
  id: number;
  email: string;
  status: string;
  timezone: string;
  last_login_at: string;
  primary_role: 'admin' | 'missionary' | null;
  roles: Array<{
    code: string;
    label: string;
    scope_type: string;
  }>;
  /**
   * Effective permission codes — the union across all active scopes.
   * For UI affordances ONLY (navigation, control visibility). The backend
   * always re-authorises access to a specific resource against its real scope.
   */
  permissions: string[];
  /** Active role assignments with their scopes. */
  scopes: AuthScope[];
  person: {
    id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    gender: string;
    phone: string | null;
    photo_path: string | null;
    missionary_profile: any | null;
  } | null;
}

export const authApi = {
  login: (email: string, password: string, device_name?: string) =>
    api.post<{ token: string; expires_at: string; user: AuthUser }>(
      '/auth/login', { email, password, device_name }
    ),
  me:     () => api.get<{ user: AuthUser }>('/auth/me'),
  logout: () => api.post('/auth/logout'),
};

// ── Campaigns ────────────────────────────────────────────────────────────────

export interface CampaignObjective {
  id: number;
  campaign_id: number;
  title: string;
  description: string | null;
  status: 'pending' | 'in_progress' | 'achieved' | 'dropped';
  sort_order: number;
}

export interface Campaign {
  id: number;
  name: string;
  description: string | null;
  status: 'planning' | 'active' | 'completed' | 'archived';
  start_date: string | null;
  end_date: string | null;
  programme: { id: number; name: string; type: { code: string; label: string } };
  lead_org_unit: { id: number; name: string };
  sites_count?: number;
  teams_count?: number;
  assignments_count?: number;
}

export interface CampaignStats {
  campaign_id: number;
  status: string;
  sites_count: number;
  teams_count: number;
  missionaries: { total: number; confirmed: number; arrived: number };
  reports: { total: number; pending: number };
  outcomes: { total: number; by_type: Record<string, number> };
  aggregated_from_reports: {
    meetings_held: number;
    total_attendance: number;
    decisions: number;
    baptisms: number;
    bible_studies: number;
  };
}

export const campaignsApi = {
  list:       (params?: Record<string, unknown>) => {
    const q = buildQuery(params);
    return api.get<{ data: Campaign[]; total: number }>(`/campaigns${q}`);
  },
  get:        (id: number) => api.get<Campaign>(`/campaigns/${id}`),
  stats:      (id: number) => api.get<CampaignStats>(`/campaigns/${id}/stats`),
  create:     (data: Partial<Campaign> & { programme_id: number; lead_org_unit_id: number }) =>
    api.post<Campaign>('/campaigns', data),
  update:     (id: number, data: Partial<Campaign>) =>
    api.patch<Campaign>(`/campaigns/${id}`, data),
  transition: (id: number, to: string) =>
    api.post<{ campaign: Campaign }>(`/campaigns/${id}/transition`, { to }),
  authorisedUsers: (campaignId: number, params?: { site_id?: number }) => {
    const q = buildQuery(params);
    return api.get<Array<{ user_id: number; person_id: number; name: string; email?: string }>>(`/campaigns/${campaignId}/authorised-users${q}`);
  },
  objectives: {
    list:   (campaignId: number) => api.get<CampaignObjective[]>(`/campaigns/${campaignId}/objectives`),
    create: (campaignId: number, data: Partial<CampaignObjective>) =>
      api.post<CampaignObjective>(`/campaigns/${campaignId}/objectives`, data),
    update: (campaignId: number, objectiveId: number, data: Partial<CampaignObjective>) =>
      api.patch<CampaignObjective>(`/campaigns/${campaignId}/objectives/${objectiveId}`, data),
    delete: (campaignId: number, objectiveId: number) =>
      api.delete(`/campaigns/${campaignId}/objectives/${objectiveId}`),
  },
};

// ── Sites ────────────────────────────────────────────────────────────────────

export interface MissionSite {
  id: number;
  campaign_id: number;
  name: string;
  description: string | null;
  status: 'planned' | 'active' | 'closed';
  latitude: string | null;
  longitude: string | null;
  location_description: string | null;
  operational_from: string | null;
  operational_until: string | null;
  teams_count?: number;
  assignments_count?: number;
  activities_count?: number;
  field_reports_count?: number;
  outcomes_count?: number;
}

export const sitesApi = {
  list:   (campaignId: number) =>
    api.get<MissionSite[]>(`/campaigns/${campaignId}/sites`),
  get:    (campaignId: number, siteId: number) =>
    api.get<MissionSite>(`/campaigns/${campaignId}/sites/${siteId}`),
  create: (campaignId: number, data: Partial<MissionSite>) =>
    api.post<MissionSite>(`/campaigns/${campaignId}/sites`, data),
  update: (campaignId: number, siteId: number, data: Partial<MissionSite>) =>
    api.patch<MissionSite>(`/campaigns/${campaignId}/sites/${siteId}`, data),
};

// ── Teams ────────────────────────────────────────────────────────────────────

export interface Team {
  id: number;
  campaign_id: number;
  mission_site_id: number | null;
  name: string;
  description: string | null;
  team_type: { id: number; code: string; label: string } | null;
  members_count?: number;
}

export interface TeamMember {
  id: number;
  person: { id: number; first_name: string; last_name: string; full_name: string };
  role_in_team: 'leader' | 'deputy' | 'member';
  joined_on: string | null;
  ended_on: string | null;
}

export const teamsApi = {
  list:       (campaignId: number, params?: { site_id?: number; campaign_wide?: boolean }) => {
    const q = buildQuery(params);
    return api.get<Team[]>(`/campaigns/${campaignId}/teams${q}`);
  },
  get:        (campaignId: number, teamId: number) =>
    api.get<Team & { members: TeamMember[] }>(`/campaigns/${campaignId}/teams/${teamId}`),
  create:     (campaignId: number, data: { name: string; description?: string; mission_site_id?: number; team_type_id?: number; origin_org_unit_id?: number }) =>
    api.post<Team>(`/campaigns/${campaignId}/teams`, data),
  addMember:  (campaignId: number, teamId: number, data: { person_id: number; role_in_team: string }) =>
    api.post<TeamMember>(`/campaigns/${campaignId}/teams/${teamId}/members`, data),
  removeMember: (campaignId: number, teamId: number, memberId: number) =>
    api.delete(`/campaigns/${campaignId}/teams/${teamId}/members/${memberId}`),
};

// ── Activities ───────────────────────────────────────────────────────────────

export interface Activity {
  id: number;
  campaign_id: number;
  mission_site_id: number;
  activity_type_id: number;
  title: string;
  description: string | null;
  status: 'scheduled' | 'in_progress' | 'completed' | 'cancelled';
  /**
   * true  → agreed in advance (part of the campaign programme);
   * false → arose on the day and was created at attendance time.
   */
  is_planned: boolean;
  scheduled_at: string;
  actual_started_at: string | null;
  actual_ended_at: string | null;
  location_note: string | null;
  led_by_person_id: number | null;
  estimated_attendance: number | null;
  notes: string | null;
  site?: MissionSite;
  activity_type?: { id: number; code: string; label: string; category: string };
  ledBy?: { id: number; first_name: string; last_name: string; full_name: string };
  /** What this activity sets out to achieve, ordered for display. */
  objectives?: ActivityObjective[];
}

/**
 * A single goal an activity sets out to achieve.
 *
 * Deliberately NOT a workflow object: there is no status or approval here.
 * Achievement is recorded as a Mission Outcome; this only records intent.
 */
export interface ActivityObjective {
  id: number;
  activity_id: number;
  title: string;
  description: string | null;
  sort_order: number;
}

/** A member of an activity's PLANNED roster (not attendance). */
export interface ActivityRosterMember {
  id: number;
  person_id: number;
  name: string;
  phone: string | null;
  village: string | null;
  joined_date: string | null;
  status: 'active' | 'inactive' | 'completed';
  notes: string | null;
}

export const activitiesApi = {
  list:   (campaignId: number, params?: { site_id?: number; status?: string }) => {
    const q = buildQuery(params);
    return api.get<Activity[]>(`/campaigns/${campaignId}/activities${q}`);
  },
  get:    (campaignId: number, activityId: number) =>
    api.get<Activity>(`/campaigns/${campaignId}/activities/${activityId}`),
  create: (campaignId: number, data: Partial<Activity>) =>
    api.post<Activity>(`/campaigns/${campaignId}/activities`, data),
  update: (campaignId: number, activityId: number, data: Partial<Activity>) =>
    api.patch<Activity>(`/campaigns/${campaignId}/activities/${activityId}`, data),
  delete: (campaignId: number, activityId: number) =>
    api.delete(`/campaigns/${campaignId}/activities/${activityId}`),

  // ── Planned roster ────────────────────────────────────────────────────────
  // "Who do we intend to take part?" — deliberately separate from attendance.
  roster: (campaignId: number, activityId: number) =>
    api.get<ActivityRosterMember[]>(`/campaigns/${campaignId}/activities/${activityId}/participants`),
  addToRoster: (campaignId: number, activityId: number, data: { person_id: number; joined_date?: string; notes?: string }) =>
    api.post<ActivityRosterMember>(`/campaigns/${campaignId}/activities/${activityId}/participants`, data),
  removeFromRoster: (campaignId: number, activityId: number, participantId: number) =>
    api.delete(`/campaigns/${campaignId}/activities/${activityId}/participants/${participantId}`),

  // ── Objectives ────────────────────────────────────────────────────────────
  // What the activity sets out to achieve. Loaded automatically alongside the
  // activity so opening a register can show the activity's purpose.
  objectives: (campaignId: number, activityId: number) =>
    api.get<ActivityObjective[]>(`/campaigns/${campaignId}/activities/${activityId}/objectives`),
  addObjective: (campaignId: number, activityId: number, data: { title: string; description?: string | null; sort_order?: number }) =>
    api.post<ActivityObjective>(`/campaigns/${campaignId}/activities/${activityId}/objectives`, data),
  updateObjective: (campaignId: number, activityId: number, objectiveId: number, data: Partial<Pick<ActivityObjective, 'title' | 'description' | 'sort_order'>>) =>
    api.patch<ActivityObjective>(`/campaigns/${campaignId}/activities/${activityId}/objectives/${objectiveId}`, data),
  removeObjective: (campaignId: number, activityId: number, objectiveId: number) =>
    api.delete(`/campaigns/${campaignId}/activities/${activityId}/objectives/${objectiveId}`),
};

// ── Assignments ───────────────────────────────────────────────────────────────

export interface Assignment {
  id: number;
  campaign_id: number;
  mission_site_id: number | null;
  assigned_team_id: number | null;
  functional_role_type_id: number | null;
  system_role_id: number | null;
  status: 'confirmed' | 'arrived' | 'completed' | 'withdrew' | 'no_show';
  person: { id: number; first_name: string; last_name: string; full_name: string; user?: { id: number } };
  site: MissionSite | null;
  expected_arrival: string | null;
  actual_arrival: string | null;
  notes: string | null;
}

export const assignmentsApi = {
  list:   (campaignId: number, params?: { site_id?: number; status?: string }) => {
    const q = buildQuery(params);
    return api.get<Assignment[]>(`/campaigns/${campaignId}/assignments${q}`);
  },
  create: (campaignId: number, data: { person_id: number; mission_site_id?: number; assigned_team_id?: number; functional_role_type_id?: number; system_role_id?: number; expected_arrival?: string; notes?: string }) =>
    api.post<Assignment>(`/campaigns/${campaignId}/assignments`, data),
  update: (campaignId: number, assignmentId: number, data: Partial<Assignment>) =>
    api.patch<Assignment>(`/campaigns/${campaignId}/assignments/${assignmentId}`, data),
};

// ── Field Reports ─────────────────────────────────────────────────────────────

/**
 * The rung a report occupies in the reporting hierarchy.
 *
 *   team      filed by a team secretary, owned by a team
 *   site      the site's consolidated report
 *   campaign  campaign leadership's consolidated report
 */
export type ReportLevel = 'team' | 'site' | 'campaign';

/**
 * Where a submitted report is waiting in the review chain. Deliberately
 * separate from `status` (the document lifecycle) so routing never multiplies
 * the workflow statuses — mirroring App\Domain\WorkflowDefinitions.
 */
export type ReportReviewState =
  | 'pending_team_review'
  | 'pending_site_review'
  | 'pending_campaign_review'
  | 'changes_requested'
  | 'complete';

export interface FieldReport {
  id: number;
  campaign_id: number;
  // Null for campaign-level reports, which belong to no single site.
  mission_site_id: number | null;
  report_level: ReportLevel;
  team_id: number | null;
  activity_id: number | null;
  attendance_register_id: number | null;
  parent_report_id: number | null;
  report_period_type: 'daily' | 'weekly' | 'summary';
  period_start: string;
  period_end: string;
  status: 'draft' | 'submitted' | 'reviewed' | 'approved';
  narrative: string | null;
  stat_meetings_held: number;
  stat_total_attendance: number;
  stat_decisions: number;
  stat_baptisms: number;
  stat_bible_studies_requested: number;
  challenges: string | null;
  observations: string | null;
  testimonies: string | null;
  recommendations: string | null;
  prayer_requests: string | null;
  review_state: ReportReviewState | null;
  review_due_at: string | null;
  forwarded_at: string | null;
  escalated_at: string | null;
  // True while a lower-level review is still outstanding (the report was
  // forwarded past it). Combined with review_state, this is how the UI shows
  // "Team Review Pending" without ever claiming the report was approved.
  has_pending_lower_review: boolean;
  // The rung responsible for that outstanding review, when one exists.
  outstanding_review_level: ReportLevel | null;
  // What the live preparation service compiled, and what a human must supply.
  preparation_meta: Record<string, unknown> | null;
  // The user who most recently submitted this report (null while it is still a
  // pure draft). Lets the workspace tell "my" reports apart from the ones it
  // can merely see; the backend serialises the raw column.
  submitted_by_user_id: number | null;
  submitted_at: string | null;
  reviewed_at: string | null;
  site?: MissionSite;
  team?: Team;
}

/**
 * One entry in a report's append-only audit history. Mirrors
 * App\Models\FieldReportEvent. Events are never edited or deleted.
 */
export interface FieldReportEvent {
  id: number;
  field_report_id: number;
  event_type:
    | 'submitted'
    | 'approved'
    | 'returned'
    | 'forwarded'
    | 'escalated'
    | 'consolidated'
    | 'recovered';
  from_state: ReportReviewState | null;
  to_state: ReportReviewState | null;
  level: ReportLevel | null;
  actor_user_id: number | null;
  notes: string | null;
  meta: Record<string, unknown> | null;
  created_at: string;
  actor?: { person?: { full_name?: string } } | null;
}

/**
 * The classification returned when a report is generated from existing records
 * (reporting recovery). "Generating a report is not approval" — each bucket is
 * labelled so a reviewer can see exactly what is verified, what was compiled,
 * what a human added, and what is simply absent.
 */
export interface ReportRecovery {
  generated_at: string;
  basis: string;
  verified: { registers: number };
  auto_generated: {
    activities: number;
    site_attendance_entries: number;
    activity_attendance_entries: number;
    outcomes: number;
  };
  manually_supplemented: string[];
  missing: string[];
  pending_reviews: {
    id: number;
    report_level: ReportLevel;
    team_id: number | null;
    mission_site_id: number | null;
    status: string;
    review_state: ReportReviewState | null;
  }[];
  note: string;
}

/**
 * The result of consolidating lower-level reports into a higher-level one.
 * `delayed` and `refused` are surfaced rather than hidden: consolidation never
 * invents a lower-level report that was not submitted.
 */
export interface ReportConsolidation {
  child_level: ReportLevel;
  attached: number[];
  delayed: {
    id: number;
    report_level: ReportLevel;
    team_id: number | null;
    status: string;
    review_state: ReportReviewState | null;
  }[];
  refused: number[];
}

export const reportsApi = {
  list:   (
    campaignId: number,
    params?: {
      site_id?: number;
      team_id?: number;
      report_level?: ReportLevel;
      status?: string;
      review_state?: ReportReviewState;
      pending_review?: boolean;
      overdue?: boolean;
      period_type?: 'daily' | 'weekly' | 'summary';
    },
  ) => {
    const q = buildQuery(params);
    return api.get<FieldReport[]>(`/campaigns/${campaignId}/reports${q}`);
  },
  create: (campaignId: number, data: Partial<FieldReport>) =>
    api.post<FieldReport>(`/campaigns/${campaignId}/reports`, data),
  show:   (campaignId: number, reportId: number) =>
    api.get<FieldReport>(`/campaigns/${campaignId}/reports/${reportId}`),
  update: (campaignId: number, reportId: number, data: Partial<FieldReport>) =>
    api.patch<FieldReport>(`/campaigns/${campaignId}/reports/${reportId}`, data),
  submit: (campaignId: number, reportId: number) =>
    api.post<{ report: FieldReport }>(`/campaigns/${campaignId}/reports/${reportId}/submit`),
  review: (campaignId: number, reportId: number, action: 'approve' | 'return', notes?: string) =>
    api.post<{ report: FieldReport }>(`/campaigns/${campaignId}/reports/${reportId}/review`, { action, notes }),
  // Advance a report past an outstanding review once the window has expired.
  // Forwarding is NOT approval; the outstanding review stays recorded.
  forward: (campaignId: number, reportId: number, notes?: string) =>
    api.post<{ message: string; report: FieldReport }>(
      `/campaigns/${campaignId}/reports/${reportId}/forward`, { notes },
    ),
  // Attach submitted lower-level reports to this one. A site report consolidates
  // team reports; a campaign report consolidates site reports.
  consolidate: (campaignId: number, reportId: number, childReportIds?: number[]) =>
    api.post<{ message: string; consolidation: ReportConsolidation; report: FieldReport }>(
      `/campaigns/${campaignId}/reports/${reportId}/consolidate`,
      childReportIds ? { child_report_ids: childReportIds } : {},
    ),
  // Generate a draft from the records that exist when a lower-level submission
  // is delayed. Generating is not submitting.
  recover: (campaignId: number, reportId: number, notes?: string) =>
    api.post<{ message: string; recovery: ReportRecovery | null; report: FieldReport }>(
      `/campaigns/${campaignId}/reports/${reportId}/recover`, { notes },
    ),
  // The append-only audit trail for the report.
  events: (campaignId: number, reportId: number) =>
    api.get<FieldReportEvent[]>(`/campaigns/${campaignId}/reports/${reportId}/events`),
  // Live preparation — pure read; safe to poll while a report is being developed.
  preparation: (campaignId: number, reportId: number) =>
    api.get<{ preparation: ReportPreparation; reconciliation: ReportReconciliation }>(
      `/campaigns/${campaignId}/reports/${reportId}/preparation`,
    ),
  // Freeze the current compilation onto a draft.
  prepare: (campaignId: number, reportId: number) =>
    api.post<{ message: string; preparation: ReportPreparation; report: FieldReport }>(
      `/campaigns/${campaignId}/reports/${reportId}/prepare`,
    ),
};

/**
 * The live compilation of fieldwork a report covers. Mirrors
 * App\Services\ReportPreparationService::compile().
 *
 * There is deliberately NO completion percentage — the snapshot lists what is
 * present and what is outstanding instead, because a single number would imply
 * a precision the underlying records do not have.
 */
export interface ReportPreparation {
  format_version: number;
  generated_at: string;
  period: { start: string; end: string };
  level: ReportLevel;
  scoping: {
    attribution: ReportLevel | 'global';
    note: string;
    team_id?: number;
    mission_site_id?: number;
  };
  activities: {
    count: number;
    planned: number;
    unplanned: number;
    completed: number;
    cancelled: number;
    in_progress: number;
    scheduled: number;
    items: {
      id: number;
      title: string;
      status: 'scheduled' | 'in_progress' | 'completed' | 'cancelled';
      is_planned: boolean;
      scheduled_at: string | null;
      objectives: { id: number; title: string }[];
      attendance: { register_count: number; state: 'recorded' | 'not_recorded'; entry_count: number };
    }[];
  };
  attendance: {
    site_attendance: AttendanceSummaryCounts;
    activity_attendance: AttendanceSummaryCounts;
    note: string;
  };
  participation: { planned_roster_total: number; note: string };
  outcomes: {
    total: number;
    by_type: { outcome_type: string; count: number }[];
    awaiting_follow_up: number;
  };
  sources: {
    activity_ids: number[];
    attendance_register_ids: number[];
    outcome_ids: number[];
  };
  fingerprint: string;
  completeness: {
    auto_generated: { activities: number; attendance_registers: number; outcomes: number };
    human_sections: { written: string[]; awaiting: string[] };
    note: string;
  };
  missing: string[];
}

/**
 * Comparison of today's source records against a report's preserved submitted
 * version. A changed report is surfaced here — never silently rewritten.
 */
export interface ReportReconciliation {
  has_preserved_version: boolean;
  preserved_version: {
    version_number: number;
    status: string;
    review_state: string | null;
    created_at: string | null;
  } | null;
  changed: boolean;
  added: { activity_ids: number[]; attendance_register_ids: number[]; outcome_ids: number[] };
  removed: { activity_ids: number[]; attendance_register_ids: number[]; outcome_ids: number[] };
  message: string | null;
}

// ── Outcomes ──────────────────────────────────────────────────────────────────

export interface OutcomeType {
  id: number;
  code: string;
  label: string;
  triggers_follow_up: boolean;
  include_in_stats: boolean;
}

export interface MissionOutcome {
  id: number;
  campaign_id: number;
  mission_site_id: number;
  outcome_type: string;
  outcome_date: string;
  visitor_name: string | null;
  visitor_gender: string | null;
  visitor_age: number | null;
  follow_up_status: 'pending' | 'assigned' | 'completed' | 'lost_contact' | 'not_required';
  follow_up_due_date: string | null;
  notes: string | null;
  site?: MissionSite;
}

export const outcomesApi = {
  list:           (campaignId: number, params?: { site_id?: number; outcome_type?: string }) => {
    const q = buildQuery(params);
    return api.get<MissionOutcome[]>(`/campaigns/${campaignId}/outcomes${q}`);
  },
  create:         (campaignId: number, data: Partial<MissionOutcome> & { visitor_name?: string }) =>
    api.post<MissionOutcome>(`/campaigns/${campaignId}/outcomes`, data),
  assignFollowUp: (campaignId: number, outcomeId: number, data: { follow_up_assigned_to_person_id: number; follow_up_due_date?: string }) =>
    api.post(`/campaigns/${campaignId}/outcomes/${outcomeId}/follow-up`, data),
  completeFollowUp: (campaignId: number, outcomeId: number, result: 'completed' | 'lost_contact', notes?: string) =>
    api.post(`/campaigns/${campaignId}/outcomes/${outcomeId}/complete-follow-up`, { result, notes }),
};

// ── Role Assignments ───────────────────────────────────────────────────────────

export interface RoleAssignment {
  id: number;
  user_id: number;
  system_role_id: number;
  scope_type: 'global' | 'org_unit' | 'campaign' | 'site' | 'team';
  scoped_org_unit_id: number | null;
  scoped_campaign_id: number | null;
  scoped_site_id: number | null;
  scoped_team_id: number | null;
  valid_from: string | null;
  valid_until: string | null;
  granted_by_user_id: number | null;
  created_at: string;
  user: {
    id: number;
    email: string;
    person?: {
      id: number;
      full_name: string;
    };
  };
  role: {
    id: number;
    code: string;
    label: string;
    scope_type: string;
  };
  granted_by?: {
    id: number;
    email: string;
  };
}

export const roleAssignmentsApi = {
  listForCampaign: (campaignId: number) =>
    api.get<RoleAssignment[]>(`/campaigns/${campaignId}/role-assignments`),
  listForSite: (campaignId: number, siteId: number) =>
    api.get<RoleAssignment[]>(`/campaigns/${campaignId}/sites/${siteId}/role-assignments`),
  listForTeam: (campaignId: number, teamId: number) =>
    api.get<RoleAssignment[]>(`/campaigns/${campaignId}/teams/${teamId}/role-assignments`),
  create: (data: {
    user_id: number;
    system_role_id: number;
    scope_type: 'global' | 'org_unit' | 'campaign' | 'site' | 'team';
    scoped_org_unit_id?: number;
    scoped_campaign_id?: number;
    scoped_site_id?: number;
    scoped_team_id?: number;
    valid_from?: string;
    valid_until?: string;
  }) =>
    api.post<RoleAssignment>('/role-assignments', data),
  get: (id: number) =>
    api.get<RoleAssignment>(`/role-assignments/${id}`),
  update: (id: number, data: { valid_from?: string; valid_until?: string }) =>
    api.patch<RoleAssignment>(`/role-assignments/${id}`, data),
  revoke: (id: number) =>
    api.delete(`/role-assignments/${id}`),
};

// ── Departments ───────────────────────────────────────────────────────────────

export interface Department {
  id: number;
  campaign_id: number;
  mission_site_id: number | null;
  name: string;
  leader_person_id: number | null;
  leader?: {
    id: number;
    full_name: string;
  };
  site?: {
    id: number;
    name: string;
  };
  members?: {
    id: number;
    person: { id: number; full_name: string };
    role_in_dept: string | null;
  }[];
}

export const departmentsApi = {
  listForCampaign: (campaignId: number) =>
    api.get<Department[]>(`/campaigns/${campaignId}/departments`),
  listForSite: (campaignId: number, siteId: number) =>
    api.get<Department[]>(`/campaigns/${campaignId}/sites/${siteId}/departments`),
  create: (campaignId: number, data: {
    name: string;
    mission_site_id?: number;
    leader_person_id?: number;
  }) =>
    api.post<Department>(`/campaigns/${campaignId}/departments`, data),
  get: (id: number) =>
    api.get<Department>(`/departments/${id}`),
  update: (id: number, data: { name?: string; leader_person_id?: number }) =>
    api.patch<Department>(`/departments/${id}`, data),
  delete: (id: number) =>
    api.delete(`/departments/${id}`),
  addMember: (departmentId: number, data: { person_id: number; role_in_dept?: string }) =>
    api.post(`/departments/${departmentId}/members`, data),
  removeMember: (departmentId: number, memberId: number) =>
    api.delete(`/departments/${departmentId}/members/${memberId}`),
};

// ── Organisational Offices ─────────────────────────────────────────────────────

export interface OfficeHeld {
  id: number;
  person_id: number;
  org_unit_id: number;
  office_type_id: number;
  held_from: string;
  held_until: string | null;
  person: {
    id: number;
    full_name: string;
  };
  officeType: {
    id: number;
    code: string;
    label: string;
  };
  orgUnit: {
    id: number;
    name: string;
  };
}

export const officesApi = {
  listForOrgUnit: (orgUnitId: number) =>
    api.get<OfficeHeld[]>(`/org-units/${orgUnitId}/offices`),
  listForPerson: (personId: number) =>
    api.get<OfficeHeld[]>(`/people/${personId}/offices`),
  create: (data: {
    person_id: number;
    org_unit_id: number;
    office_type_id: number;
    held_from: string;
    held_until?: string;
  }) =>
    api.post<OfficeHeld>('/offices', data),
  get: (id: number) =>
    api.get<OfficeHeld>(`/offices/${id}`),
  update: (id: number, data: { held_from?: string; held_until?: string }) =>
    api.patch<OfficeHeld>(`/offices/${id}`, data),
  delete: (id: number) =>
    api.delete(`/offices/${id}`),
};

// ── Reference data ────────────────────────────────────────────────────────────

export const refApi = {
  outcomeTypes:         () => api.get<OutcomeType[]>('/ref/outcome-types'),
  programmeTypes:       () => api.get<{ code: string; label: string }[]>('/ref/programme-types'),
  programmes:           () => api.get<{ id: number; name: string }[]>('/ref/programmes'),
  teamTypes:            () => api.get<{ id: number; code: string; label: string }[]>('/ref/team-types'),
  // Activity types are needed to author an activity — including one that
  // genuinely arose on the day at attendance time.
  activityTypes:        () => api.get<{ id: number; code: string; label: string; category: string }[]>('/ref/activity-types'),
  functionalRoleTypes:  () => api.get<{ id: number; code: string; label: string }[]>('/ref/functional-role-types'),
  systemRoles:          () => api.get<{ id: number; code: string; label: string; scope_type: string }[]>('/ref/system-roles'),
  officeTypes:           () => api.get<{ id: number; code: string; label: string; org_unit_type_id: number | null }[]>('/ref/office-types'),
  orgUnits:             () => api.get<{ id: number; name: string; type: { label: string } }[]>('/ref/org-units'),
  persons:              (search?: string) => {
    const q = search ? `?search=${encodeURIComponent(search)}` : '';
    return api.get<{ id: number; full_name: string; engagement_stage?: string }[]>(`/ref/persons${q}`);
  },
  encounterMethods:     () => api.get<EncounterMethod[]>('/ref/encounter-methods'),
  engagementStages:     () => api.get<{ id: number; code: string; label: string; color_key: string; sort_order: number; is_active: boolean }[]>('/ref/engagement-stages'),
};

// ── Mission Translator ─────────────────────────────────────────────────────────

export interface TranslationRequest {
  source_language?: string;
  target_language: string;
  text: string;
}

export interface TranslationResponse {
  translated_text: string;
  source_language: string;
  target_language: string;
}

export interface LanguagesResponse {
  languages: Record<string, string>;
}

export const translationApi = {
  translate: (data: TranslationRequest) => {
    const token = getToken();
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    };

    return fetch(`${BASE}/translate`, {
      method: 'POST',
      headers,
      body: JSON.stringify(data),
    }).then(async (res) => {
      let body;
      try {
        body = await res.json();
      } catch (e) {
        body = null;
      }

      if (!res.ok) {
        if (res.status === 401) {
          localStorage.removeItem('uu_token');
          throw {
            message: 'Session expired. Please log in again.',
            status: 401,
            errors: body,
          } as ApiError;
        }

        throw {
          message: body?.error ?? body?.message ?? `Request failed (${res.status})`,
          status: res.status,
          errors: body,
        } as ApiError;
      }

      return body as TranslationResponse;
    });
  },
  languages: () =>
    api.get<LanguagesResponse>('/translate/languages'),
};

// ── Speech Recognition ───────────────────────────────────────────────────────

export interface TranscriptionRequest {
  audio: File;
  language: string;
}

export interface TranscriptionResponse {
  transcription: string;
  language: string;
  duration_seconds: number | null;
}

export const transcribeApi = {
  transcribe: (data: TranscriptionRequest) => {
    const formData = new FormData();
    formData.append('audio', data.audio);
    formData.append('language', data.language);

    const token = getToken();
    const headers: Record<string, string> = {
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    };

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 180000); // 3 minute timeout

    // Call backend directly to bypass Next.js proxy timeout on long-running requests
    return fetch('http://localhost:8000/api/v1/transcribe', {
      method: 'POST',
      headers,
      body: formData,
      signal: controller.signal,
    }).then(async (res) => {
      clearTimeout(timeoutId);

      let body;
      try {
        body = await res.json();
      } catch (e) {
        body = null;
      }

      if (!res.ok) {
        if (res.status === 401) {
          localStorage.removeItem('uu_token');
          throw {
            message: 'Session expired. Please log in again.',
            status: 401,
            errors: body,
          } as ApiError;
        }

        throw {
          message: body?.error ?? body?.message ?? `Request failed (${res.status})`,
          status: res.status,
          errors: body,
        } as ApiError;
      }

      return body as TranscriptionResponse;
    }).catch((error) => {
      clearTimeout(timeoutId);
      if (error.name === 'AbortError') {
        throw {
          message: 'Speech recognition timed out. The service may be slow or unavailable. Please try again.',
          status: 504,
        } as ApiError;
      }
      throw error;
    });
  },
};

// ── Speech Synthesis ───────────────────────────────────────────────────────

export interface SynthesisRequest {
  text: string;
  language: string;
  voice?: string;
}

export interface SynthesisResponse {
  audio_url: string;
  duration_seconds: number | null;
  language: string;
  voice: string | null;
}

export const synthesisApi = {
  synthesize: (data: SynthesisRequest) => {
    const token = getToken();
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    };

    // Call backend directly to bypass Next.js proxy timeout on long-running requests
    return fetch('http://localhost:8000/api/v1/synthesize', {
      method: 'POST',
      headers,
      body: JSON.stringify(data),
    }).then(async (res) => {
      if (res.status === 401) {
        localStorage.removeItem('uu_token');
        throw {
          message: 'Session expired. Please log in again.',
          status: 401,
        } as ApiError;
      }

      let body;
      try {
        body = await res.json();
      } catch (e) {
        body = null;
      }

      if (!res.ok) {
        throw {
          message: body?.error ?? body?.message ?? `Request failed (${res.status})`,
          status: res.status,
          errors: body,
        } as ApiError;
      }

      return body as SynthesisResponse;
    });
  },
};

// ── Workspace (Missionary's personal data) ───────────────────────────────────

export interface AssignmentSite {
  id: number;
  name: string;
  description: string | null;
  status: string;
  location_description: string | null;
  latitude: string | null;
  longitude: string | null;
  operational_from: string | null;
  operational_until: string | null;
}

export interface AssignmentTeam {
  id: number;
  name: string;
  description: string | null;
  team_type: {
    id: number;
    code: string;
    label: string;
  } | null;
}

export interface AssignmentCampaign {
  id: number;
  name: string;
  description: string | null;
  status: string;
  start_date: string | null;
  end_date: string | null;
}

export interface FunctionalRole {
  id: number;
  code: string;
  label: string;
}

export interface MyAssignment {
  id: number;
  /**
   * Where this context came from:
   *   'campaign_assignment' — a campaign deployment (carries workflow dates)
   *   'role_assignment'     — derived from the user's authoritative role
   *                           assignment (context only, no workflow data)
   */
  source?: 'campaign_assignment' | 'role_assignment';
  /** Which scope the context was resolved from, when source is a role assignment. */
  scope_type?: 'team' | 'site' | 'campaign' | 'org_unit';
  status: string;
  expected_arrival: string | null;
  actual_arrival: string | null;
  departure: string | null;
  notes: string | null;
  campaign: AssignmentCampaign;
  site: AssignmentSite | null;
  team: AssignmentTeam | null;
  functional_role: FunctionalRole | null;
}

export interface MyAssignmentResponse {
  assignment: MyAssignment | null;
}

/**
 * A mission site the authenticated user is authorised to record outcomes at,
 * as resolved by the backend from their active role assignments.
 *
 * The campaign context travels with the site so the workspace never has to
 * derive an authorised (campaign, site) pair from raw scopes — it simply
 * renders what the server already authorised.
 */
export interface MySite {
  id: number;
  name: string;
  status: string;
  campaign: { id: number; name: string; status: string } | null;
}

export interface MySitesResponse {
  sites: MySite[];
}

// ── Workspace Profile (for missionary profile page) ─────────────────────────────

export interface WorkspaceMissionaryProfile {
  person_id: number;
  home_org_unit_id: number | null;
  deployment_status: string | null;
  bio: string | null;
  training_notes: string | null;
  home_org_unit?: {
    id: number;
    name: string;
    type: { code: string; label: string };
  };
  skills?: Array<{ id: number; skill_name: string; proficiency_level: string }>;
  languages?: Array<{ id: number; language_name: string; proficiency_level: string; is_native: boolean }>;
}

export interface WorkspaceOrgMembership {
  id: number;
  org_unit_id: number;
  membership_type: string;
  joined_on: string | null;
  ended_on: string | null;
  org_unit?: {
    id: number;
    name: string;
    type: { code: string; label: string };
  };
}

export interface WorkspaceOffice {
  id: number;
  org_unit_id: number;
  office_type_id: number;
  held_from: string;
  held_until: string | null;
  office_type?: {
    id: number;
    code: string;
    label: string;
  };
  org_unit?: {
    id: number;
    name: string;
  };
}

export interface WorkspaceProfile {
  person: {
    id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    preferred_name: string | null;
    full_name: string;
    gender: string | null;
    date_of_birth: string | null;
    nationality: string | null;
    phone: string | null;
    whatsapp_phone: string | null;
    alternative_phone: string | null;
    email: string | null;
    preferred_contact_method: string | null;
    emergency_contact_name: string | null;
    emergency_contact_relationship: string | null;
    emergency_contact_phone: string | null;
    occupation: string | null;
    photo_path: string | null;
    cover_photo_url: string | null;
  };
  missionary_profile: WorkspaceMissionaryProfile | null;
  addresses: Array<{
    id: number;
    country: string | null;
    region: string | null;
    district: string | null;
    county_municipality: string | null;
    parish_town: string | null;
    village_area: string | null;
    physical_address: string | null;
    is_primary: boolean;
  }>;
  skills: Array<{
    id: number;
    skill_category: string;
    skill_name: string;
    proficiency_level: string;
    years_experience: number | null;
  }>;
  languages: Array<{
    id: number;
    language_code: string;
    language_name: string;
    is_native: boolean;
    proficiency_level: string;
  }>;
  mission_experience: Array<{
    id: number;
    mission_name: string;
    location: string | null;
    started_on: string | null;
    ended_on: string | null;
    role: string | null;
    description: string | null;
  }>;
  training: Array<{
    id: number;
    training_type: string;
    training_name: string;
    training_provider: string | null;
    completed_on: string | null;
    expires_on: string | null;
    certificate_number: string | null;
    notes: string | null;
  }>;
  org_memberships: WorkspaceOrgMembership[];
  current_office: WorkspaceOffice | null;
  assignment: {
    id: number;
    status: string;
    expected_arrival: string | null;
    actual_arrival: string | null;
    departure: string | null;
    campaign: {
      id: number;
      name: string;
      status: string;
      start_date: string | null;
      end_date: string | null;
    };
    site: {
      id: number;
      name: string;
      status: string;
    } | null;
    functional_role: {
      id: number;
      code: string;
      label: string;
    } | null;
  } | null;
  assignment_history: Array<{
    id: number;
    status: string;
    campaign: { id: number; name: string };
    site: { id: number; name: string } | null;
    functional_role: { id: number; label: string } | null;
    expected_arrival: string | null;
    departure: string | null;
  }>;
}

export const workspaceApi = {
  myAssignment: () => api.get<MyAssignmentResponse>('/workspace/my-assignment'),
  myTeam: () => api.get<MyTeamResponse>('/workspace/my-team'),
  /**
   * The mission sites the user may record outcomes at, resolved server-side
   * from their active assignments. Read-only context: it grants no permissions.
   */
  mySites: () => api.get<MySitesResponse>('/workspace/my-sites'),
  myProfile: () => api.get<WorkspaceProfile>('/workspace/my-profile'),
  updateMyProfile: (data: { bio?: string; training_notes?: string; home_org_unit_id?: number }) =>
    api.patch('/workspace/my-profile', data),
  updateMyPerson: (data: { first_name?: string; last_name?: string; phone?: string; date_of_birth?: string; gender?: string }) =>
    api.patch('/workspace/my-person', data),
  addMyLanguage: (data: { language_name: string; proficiency_level: string; is_native?: boolean; notes?: string }) =>
    api.post('/workspace/my-languages', data),
  removeMyLanguage: (languageCode: string) =>
    api.delete(`/workspace/my-languages/${languageCode}`),
  addMySkill: (data: { skill_category: string; skill_name: string; proficiency_level: string; years_experience?: number; notes?: string }) =>
    api.post('/workspace/my-skills', data),
  removeMySkill: (skillId: number) =>
    api.delete(`/workspace/my-skills/${skillId}`),
  addMyMissionExperience: (data: { mission_name: string; location?: string; started_on?: string; ended_on?: string; role?: string; description?: string }) =>
    api.post('/workspace/my-mission-experience', data),
  removeMyMissionExperience: (experienceId: number) =>
    api.delete(`/workspace/my-mission-experience/${experienceId}`),
  addMyTraining: (data: { training_type: string; training_name: string; training_provider?: string; completed_on?: string; expires_on?: string; certificate_number?: string; notes?: string }) =>
    api.post('/workspace/my-training', data),
  removeMyTraining: (trainingId: number) =>
    api.delete(`/workspace/my-training/${trainingId}`),
  uploadProfilePhoto: (file: File) => {
    const formData = new FormData();
    formData.append('photo', file);
    const token = getToken();
    const headers: Record<string, string> = {
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    };
    return fetch(`${BASE}/workspace/my-profile-photo`, {
      method: 'POST',
      headers,
      body: formData,
    }).then(async (res) => {
      let body;
      try {
        body = await res.json();
      } catch (e) {
        body = null;
      }
      if (!res.ok) {
        throw {
          message: body?.message ?? `Upload failed (${res.status})`,
          status: res.status,
          errors: body?.errors,
        } as ApiError;
      }
      return body;
    });
  },
  uploadCoverPhoto: (file: File) => {
    const formData = new FormData();
    formData.append('photo', file);
    const token = getToken();
    const headers: Record<string, string> = {
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    };
    return fetch(`${BASE}/workspace/my-cover-photo`, {
      method: 'POST',
      headers,
      body: formData,
    }).then(async (res) => {
      let body;
      try {
        body = await res.json();
      } catch (e) {
        body = null;
      }
      if (!res.ok) {
        throw {
          message: body?.message ?? `Upload failed (${res.status})`,
          status: res.status,
          errors: body?.errors,
        } as ApiError;
      }
      return body;
    });
  },
};

export interface WorkspaceTeamMemberPerson {
  id: number;
  first_name: string;
  last_name: string;
  full_name: string;
  gender: string;
}

export interface WorkspaceTeamMember {
  id: number;
  role_in_team: 'leader' | 'deputy' | 'member';
  joined_on: string | null;
  person: WorkspaceTeamMemberPerson;
}

export interface MyTeam {
  id: number;
  name: string;
  description: string | null;
  team_type: {
    id: number;
    code: string;
    label: string;
  } | null;
  members: WorkspaceTeamMember[];
}

export interface MyTeamResponse {
  team: MyTeam | null;
}

export const workspaceTeamApi = {
  myTeam: () => api.get<MyTeamResponse>('/workspace/my-team'),
};

// ── Outreach Encounters ───────────────────────────────────────────────────────

export interface EncounterMethod {
  id: number;
  code: string;
  label: string;
  description: string | null;
  is_active: boolean;
  sort_order: number;
}

export interface OutreachEncounter {
  id: number;
  person_id: number;
  campaign_id: number;
  mission_site_id: number | null;
  encounter_method_id: number | null;
  recorded_by_user_id: number | null;
  encounter_date: string;
  notes: string | null;
  created_at: string;
  updated_at: string;
  stage_before_encounter_id: number | null;
  stage_after_encounter_id: number | null;
  next_action: string | null;
  follow_up_date: string | null;
  assigned_follow_up_user_id: number | null;
  completed_at: string | null;
  completed_by_user_id: number | null;
  completion_notes: string | null;
  campaign?: { id: number; name: string };
  site?: { id: number; name: string };
  method?: EncounterMethod;
  // snake_case — Laravel serialises camelCase method names as snake_case
  recorded_by?: { id: number; email: string; person?: { id: number; full_name: string } };
  stage_before?: EngagementStage;
  stage_after?: EngagementStage;
  assigned_follow_up_user?: { id: number; email: string; person?: { id: number; full_name: string } };
  completed_by?: { id: number; email: string; person?: { id: number; full_name: string } };
}

export interface EngagementStage {
  id: number;
  code: string;
  label: string;
  color_key: string;
  sort_order: number;
  is_active: boolean;
}

// ── Baptism Records ───────────────────────────────────────────────────────────

export interface BaptismRecord {
  id: number;
  person_id: number;
  campaign_id: number | null;
  mission_site_id: number | null;
  baptism_date: string;
  location: string | null;
  officiated_by_person_id: number | null;
  verification_status: 'pending' | 'verified' | 'rejected';
  verified_by_user_id: number | null;
  verified_at: string | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
  campaign?: { id: number; name: string };
  site?: { id: number; name: string };
  // snake_case relation keys
  officiated_by?: { id: number; full_name: string };
  verified_by?: { id: number; email: string };
}

export interface EngagementStageHistory {
  id: number;
  outreach_contact_id: number;
  person_id: number | null;
  from_stage_id: number | null;
  to_stage_id: number;
  changed_by_user_id: number | null;
  notes: string | null;
  changed_at: string;
  outreach_encounter_id: number | null;
  correction_reason: string | null;
  is_correction: boolean;
  // snake_case relation keys
  changed_by?: { id: number; email: string; person?: { id: number; full_name: string } };
  from_stage?: EngagementStage;
  to_stage?: EngagementStage;
  outreach_encounter?: OutreachEncounter;
}

// ── Org Memberships ───────────────────────────────────────────────────────────

export interface OrgMembership {
  id: number;
  person_id: number;
  org_unit_id: number;
  membership_type: string;
  joined_on: string;
  ended_on: string | null;
  orgUnit?: { id: number; name: string; type: { label: string } };
}

// ── Outreach Contacts ─────────────────────────────────────────────────────────

export interface OutreachContact {
  id: number;
  person_id: number;
  engagement_stage_id: number;
  village: string | null;
  date_registered: string | null;
  notes: string | null;
  campaign_id: number | null;
  first_encountered_campaign_id: number | null;
  first_encountered_site_id: number | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
  person?: Person;
  engagement_stage?: EngagementStage;
  campaign?: { id: number; name: string };
  outreach_encounters?: OutreachEncounter[];
  engagement_stage_history?: EngagementStageHistory[];
  baptism_records?: BaptismRecord[];
}

// ── People Register (Fieldwork) ────────────────────────────────────────────────

export interface Person {
  id: number;
  user_id: number | null;
  registered_by_user_id: number | null;
  first_name: string;
  last_name: string;
  full_name: string;
  gender: string | null;
  date_of_birth: string | null;
  nationality: string | null;
  phone: string | null;
  photo_path: string | null;
  emergency_contact_encrypted: string | null;
  village: string | null;
  date_registered: string | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
  // snake_case relation keys — Laravel serialises method names to snake_case in toArray()
  activity_participations?: ActivityParticipation[];
  outreach_encounters?: OutreachEncounter[];
  baptism_records?: BaptismRecord[];
  org_memberships?: OrgMembership[];
  outreach_contact?: OutreachContact;
  engagement_stage_history?: EngagementStageHistory[];
  engagement_stage?: EngagementStage;
}

export interface ActivityParticipation {
  id: number;
  activity_id: number;
  person_id: number;
  joined_date: string | null;
  status: 'active' | 'inactive' | 'completed';
  activity?: {
    id: number;
    title: string;
    campaign_id: number;
    mission_site_id: number;
  };
}

/**
 * One attendance row.
 *
 * IDENTITY: exactly one of `person_id`, `visitor_name`, or `is_anonymous`.
 * An anonymous row carries neither a person nor a name — no placeholder name is
 * ever invented for it.
 */
export interface AttendanceEntry {
  id: number;
  attendance_register_id: number;
  entry_uuid: string;
  person_id: number | null;
  attendee_type: 'missionary' | 'visitor' | 'local_member' | null;
  is_walk_in: boolean;
  is_anonymous: boolean;
  visitor_name: string | null;
  visitor_phone: string | null;
  visitor_gender: string | null;
  is_first_time: boolean;
  decision_made: string | null;
  notes: string | null;
  created_at: string;
  register?: AttendanceRegister;
}

export const peopleApi = {
  list: (params?: { search?: string; campaign_id?: number; mission_site_id?: number; per_page?: number }) => {
    const q = buildQuery(params);
    return api.get<{ data: Person[]; total: number }>(`/people${q}`);
  },
  get: (id: number) => api.get<Person>(`/people/${id}`),
  checkDuplicates: (params: { first_name: string; last_name: string; phone?: string }) => {
    const q = buildQuery(params);
    return api.get<{ duplicates: Person[]; count: number }>(`/people/check-duplicates${q}`);
  },
  // Registering a person is an IDENTITY action only: no roster entry, no
  // attendance, no outreach contact or encounter is created as a side effect.
  create: (data: {
    first_name: string;
    last_name: string;
    gender?: string;
    date_of_birth?: string;
    phone?: string;
    campaign_id?: number;
    mission_site_id?: number;
  }) => api.post<Person>('/people', data),
  update: (id: number, data: Partial<Person>) => api.patch<Person>(`/people/${id}`, data),
};

// ── Attendance Registers (Fieldwork) ───────────────────────────────────────────

export interface AttendanceRegister {
  id: number;
  campaign_id: number;
  mission_site_id: number;
  activity_id: number | null;
  register_name: string;
  register_date: string;
  register_type: 'meeting' | 'roll_call' | 'service' | 'training' | 'community_event';
  status: 'open' | 'submitted' | 'verified';
  submitted_by_user_id: number | null;
  submitted_at: string | null;
  verified_by_user_id: number | null;
  verified_at: string | null;
  created_at: string;
  updated_at: string;
  activity?: Activity;
  site?: MissionSite;
  entries?: AttendanceEntry[];
}

/**
 * A row in the register's participants view.
 *
 * This MERGES the planned roster with the attendance actually recorded, so
 * `is_on_roster` and `is_present` are independent facts:
 *   - on roster, not present → expected but did not come
 *   - not on roster, present → walk-in / added on the day
 *
 * `entry_uuid` is the idempotency key for saving. Rows with no entry yet have
 * none, and the client mints one and keeps it for the life of the row.
 */
export interface AttendanceParticipant {
  entry_id: number | null;
  entry_uuid: string | null;
  person_id: number | null;
  name: string;
  village: string | null;
  phone: string | null;
  /** Optional metadata captured on the day; survives a save → reload cycle. */
  visitor_gender: string | null;
  joined_date: string | null;
  is_on_roster: boolean;
  is_present: boolean;
  is_walk_in: boolean;
  is_anonymous: boolean;
  attendee_type: 'missionary' | 'visitor' | 'local_member' | null;
  is_first_time: boolean;
  decision_made: string | null;
  notes: string | null;
}

/**
 * One attendance row as submitted to the API.
 *
 * Exactly one identity form must be supplied per row (validated server-side and
 * by database CHECK constraints):
 *   identified             → person_id
 *   named but unidentified → visitor_name (optional phone/gender)
 *   anonymous              → is_anonymous: true, with neither of the above
 */
export interface AttendanceEntryPayload {
  entry_uuid: string;
  is_present: boolean;
  person_id?: number | null;
  visitor_name?: string | null;
  visitor_phone?: string | null;
  visitor_gender?: string | null;
  is_anonymous?: boolean;
  is_first_time?: boolean;
  attendee_type?: 'missionary' | 'visitor' | 'local_member' | null;
  decision_made?: string | null;
  notes?: string | null;
}

export const attendanceApi = {
  list: (params?: { campaign_id?: number; mission_site_id?: number; activity_id?: number; date?: string; status?: string; per_page?: number }) => {
    const q = buildQuery(params);
    return api.get<{ data: AttendanceRegister[]; total: number }>(`/attendance-registers${q}`);
  },
  get: (id: number) => api.get<AttendanceRegister>(`/attendance-registers/${id}`),
  create: (data: {
    campaign_id: number;
    mission_site_id: number;
    activity_id?: number;
    register_name: string;
    register_date: string;
    register_type?: string;
  }) => api.post<AttendanceRegister>('/attendance-registers', data),
  participants: (id: number) => api.get<AttendanceParticipant[]>(`/attendance-registers/${id}/participants`),
  updateEntries: (id: number, data: { entries: AttendanceEntryPayload[] }) =>
    api.post<{ message: string; register: AttendanceRegister }>(`/attendance-registers/${id}/entries`, data),
  // Later linking: recognise an unnamed/anonymous attendee as a permanent Person.
  linkEntry: (id: number, entryId: number, data: { person_id: number }) =>
    api.post<AttendanceEntry>(`/attendance-registers/${id}/entries/${entryId}/link`, data),
  submit: (id: number) => api.post<{ message: string; register: AttendanceRegister }>(`/attendance-registers/${id}/submit`),
  verify: (id: number) => api.post<{ message: string; register: AttendanceRegister }>(`/attendance-registers/${id}/verify`),
  reopen: (id: number) => api.post<{ message: string; register: AttendanceRegister }>(`/attendance-registers/${id}/reopen`),

  // Read-only reconciliation of site attendance against activity attendance for
  // this register's site and day. Pure read — it never mutates source records.
  reconciliation: (id: number) =>
    api.get<AttendanceReconciliation>(`/attendance-registers/${id}/reconciliation`),

  // Aggregated, read-only summaries. Site and activity attendance are reported
  // separately and are never added together.
  summary: (params?: {
    campaign_id?: number;
    mission_site_id?: number;
    activity_id?: number;
    date_from?: string;
    date_to?: string;
    status?: string;
  }) => {
    const q = buildQuery(params);
    return api.get<AttendanceSummary>(`/attendance-registers/summary${q}`);
  },
};

/** One register's entry counts, broken down by identity form and arrival. */
export interface AttendanceSummaryCounts {
  registers: number;
  entries: number;
  identified: number;
  named_unidentified: number;
  anonymous: number;
  walk_in: number;
  first_time: number;
  decisions_recorded: number;
}

export interface AttendanceSummary {
  filters: Record<string, unknown>;
  site_attendance: AttendanceSummaryCounts;
  activity_attendance: AttendanceSummaryCounts;
  by_activity: Array<AttendanceSummaryCounts & { activity_id: number; title: string | null }>;
  /** Reminder that the two totals above must never be summed together. */
  note: string;
}

/** One identifiable person seen across a site's registers on a given day. */
export interface AttendanceReconciliationPerson {
  person_id: number;
  name: string | null;
  in_site_register: boolean;
  in_activity_registers: Array<{
    register_id: number;
    activity_id: number | null;
    activity_title: string | null;
    register_status: 'open' | 'submitted' | 'verified';
  }>;
  /** 'recorded' or 'not_recorded' — never 'absent'. */
  activity_attendance: 'recorded' | 'not_recorded';
  state: 'site_only' | 'site_and_activity' | 'activity_only';
}

export interface AttendanceReconciliation {
  register: {
    id: number;
    activity_id: number | null;
    status: 'open' | 'submitted' | 'verified';
    register_date: string;
    mission_site_id: number;
  };
  register_date: string;
  site_registers: Array<{ id: number; name: string; status: string }>;
  activity_registers: Array<{ id: number; activity_id: number | null; activity_title: string | null; status: string }>;
  people: AttendanceReconciliationPerson[];
  missing_from_site_register: AttendanceReconciliationPerson[];
  unmatched: {
    site: { anonymous: number; named_unidentified: number };
    activity: { anonymous: number; named_unidentified: number };
  };
  unreconciled: {
    is_unreconciled: boolean;
    pending_count: number;
    message: string | null;
  };
}

// ── Outreach Contacts API ─────────────────────────────────────────────────────

export const outreachContactsApi = {
  list: (params?: { engagement_stage?: string; campaign_id?: number; is_active?: boolean; search?: string; per_page?: number }) => {
    const q = buildQuery(params);
    return api.get<{ data: OutreachContact[]; total: number }>(`/outreach-contacts${q}`);
  },
  get: (id: number) => api.get<OutreachContact>(`/outreach-contacts/${id}`),
  create: (data: {
    person_id?: number;
    first_name?: string;
    last_name?: string;
    middle_name?: string;
    preferred_name?: string;
    gender?: string;
    date_of_birth?: string;
    nationality?: string;
    phone?: string;
    email?: string;
    engagement_stage: string;
    village?: string;
    date_registered?: string;
    notes?: string;
    campaign_id?: number;
    mission_site_id?: number;
  }) => api.post<OutreachContact>('/outreach-contacts', data),
  update: (id: number, data: Partial<OutreachContact>) => api.patch<OutreachContact>(`/outreach-contacts/${id}`, data),
  delete: (id: number) => api.delete(`/outreach-contacts/${id}`),
};

// ── Outreach Encounters API ───────────────────────────────────────────────────
// Note: OutreachEncounter interface is declared above (lines ~846-869)

export const outreachEncountersApi = {
  listForPerson: (personId: number) => api.get<OutreachEncounter[]>(`/people/${personId}/encounters`),
  create: (data: {
    person_id: number;
    campaign_id: number;
    mission_site_id?: number;
    encounter_method_id?: number;
    encounter_date: string;
    notes?: string;
    stage_after_encounter_id?: number;
    next_action?: string;
    follow_up_date?: string;
    assigned_follow_up_user_id?: number;
  }) => api.post<OutreachEncounter>('/outreach-encounters', data),
  get: (id: number) => api.get<OutreachEncounter>(`/outreach-encounters/${id}`),
  complete: (id: number, data: { completion_notes?: string }) =>
    api.post<{ message: string; encounter: OutreachEncounter }>(`/outreach-encounters/${id}/complete`, data),
  reschedule: (id: number, data: { follow_up_date: string; next_action?: string }) =>
    api.post<{ message: string; encounter: OutreachEncounter }>(`/outreach-encounters/${id}/reschedule`, data),
};

// ── Baptism Records API ───────────────────────────────────────────────────────
// Note: BaptismRecord interface is declared above (lines ~882-900)

export const baptismRecordsApi = {
  listForPerson: (personId: number) => api.get<BaptismRecord[]>(`/people/${personId}/baptisms`),
  create: (data: {
    person_id: number;
    campaign_id?: number;
    mission_site_id?: number;
    baptism_date: string;
    location?: string;
    officiated_by_person_id?: number;
    notes?: string;
  }) => api.post<BaptismRecord>('/baptism-records', data),
  verify: (id: number, data: { status: 'verified' | 'rejected'; notes?: string }) =>
    api.post<BaptismRecord>(`/baptism-records/${id}/verify`, data),
};
