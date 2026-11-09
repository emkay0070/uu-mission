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

/**
 * Absolute origin used only by the two long-running requests (speech-to-text
 * and text-to-speech) that call the backend directly to bypass the Next.js
 * proxy timeout.
 *
 * It is empty by default, so those calls stay same-origin and proxied, which is
 * correct for local development and for deployments where the frontend and
 * backend share a host. Set NEXT_PUBLIC_API_URL to the backend origin when the
 * two are deployed separately. A hardcoded host here would force the user's
 * browser to call their own machine and fail in production.
 */
const API_ORIGIN = (process.env.NEXT_PUBLIC_API_URL ?? '').replace(/\/+$/, '');

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
  const isFormData = options.body instanceof FormData;

  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...(options.headers as Record<string, string> | undefined),
  };

  // Only set Content-Type for non-FormData requests
  if (!isFormData) {
    headers['Content-Type'] = 'application/json';
  }

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
      message: body?.message ?? body?.error ?? `Request failed (${res.status})`,
      errors: body?.errors,
      status: res.status,
    } as ApiError;
  }

  return body as T;
}

/**
 * Fetch a protected file with the bearer token and return its bytes.
 *
 * A file URL used as an `<img>` or PDF-viewer `src` cannot carry an
 * `Authorization` header, and Sanctum does not accept `?token=`, so callers
 * must fetch the file themselves and hand the UI an object URL.
 */
async function requestBlob(path: string): Promise<Blob> {
  const token = getToken();
  const res = await fetch(`${BASE}${path}`, {
    headers: {
      Accept: '*/*',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  if (res.status === 401) {
    localStorage.removeItem('uu_token');
    if (!redirectingTo401) {
      redirectingTo401 = true;
      window.location.href = '/login';
    }
    throw { message: 'Session expired', status: 401 } as ApiError;
  }

  if (!res.ok) {
    let body: { message?: string; errors?: Record<string, string[]> } | null = null;
    try {
      body = await res.json();
    } catch {
      body = null;
    }
    throw {
      message: body?.message ?? `File request failed (${res.status})`,
      errors: body?.errors,
      status: res.status,
    } as ApiError;
  }

  return res.blob();
}

/**
 * Save an authenticated file blob by clicking a temporary download link.
 *
 * File URLs cannot carry the bearer token, so protected downloads are fetched
 * with `requestBlob()` and handed to the browser as an object URL.
 */
export function saveBlobAsFile(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 0);
}

/**
 * Serialise a request body.
 *
 * FormData must be forwarded untouched: `JSON.stringify(formData)` produces
 * `{}` and silently drops the file/metadata, which the API reports as missing
 * required fields. `undefined` stays undefined so body-less requests work.
 */
function encodeBody(data: unknown): BodyInit | undefined {
  if (data === undefined) return undefined;
  if (data instanceof FormData) return data;
  return JSON.stringify(data);
}

export const api = {
  get:    <T>(path: string) => request<T>(path, { method: 'GET' }),
  post:   <T>(path: string, data?: unknown) =>
    request<T>(path, { method: 'POST', body: encodeBody(data) }),
  patch:  <T>(path: string, data?: unknown) => {
    // PHP does not parse multipart bodies for PATCH, so a FormData PATCH must
    // be sent as POST with Laravel's method spoofing.
    if (data instanceof FormData) {
      if (!data.has('_method')) {
        data.append('_method', 'PATCH');
      }
      return request<T>(path, { method: 'POST', body: data });
    }
    return request<T>(path, { method: 'PATCH', body: encodeBody(data) });
  },
  put:    <T>(path: string, data?: unknown) =>
    request<T>(path, { method: 'PUT', body: encodeBody(data) }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
};

/**
 * Build a URL query string from a params object.
 *
 * Values that are `undefined`, `null` or an empty string are OMITTED entirely.
 * This matters because blindly stringifying them produces literal values like
 * `campaign_id=undefined`, which the backend rejects with a 422 validation
 * error ("The campaign id field must be an integer"). Numbers are serialized
 * normally; arrays are repeated as multiple key entries.
 *
 * Booleans are emitted as `1`/`0`, NEVER as the literal strings "true"/"false".
 * Laravel's `boolean` validation rule accepts only `true, false, 1, 0, "1",
 * "0"`, so a query like `?pending_review=true` is rejected with a 422 ("The
 * pending review field must be true or false"). `1`/`0` satisfy both that rule
 * and `$request->boolean()`, which is how the controllers read the flag.
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

    if (typeof value === 'boolean') {
      search.set(key, value ? '1' : '0');
      continue;
    }

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
  /**
   * The permission codes THIS assignment carries (overrides applied).
   *
   * Optional for backwards compatibility with older payloads. When present it
   * lets the client ask a scope-aware question ("may I do X in this site?")
   * instead of relying on the flattened union. Still a UI affordance only —
   * the backend re-authorises every request against the real resource scope.
   */
  permissions?: string[];
}

/** The four operational workspaces a person may qualify for. */
export type WorkspaceKey =
  | 'missionary'
  | 'site_operations'
  | 'campaign_management'
  | 'platform_administration';

/**
 * One authorised context inside a workspace.
 *
 * The campaign/site/team identity is resolved by the backend so the interface
 * can render — and switch between — every authorised context, rather than
 * silently keeping only the first assignment.
 */
export interface WorkspaceContext {
  scope_type: 'global' | 'org_unit' | 'campaign' | 'site' | 'team';
  scope_id: number | null;
  label: string;
  campaign_id: number | null;
  campaign_name: string | null;
  site_id: number | null;
  site_name: string | null;
  team_id: number | null;
  team_name: string | null;
  roles: string[];
}

/**
 * A workspace descriptor, derived on the backend from effective role
 * assignments and the authority each one carries.
 */
export interface WorkspaceDescriptor {
  key: WorkspaceKey;
  label: string;
  description: string;
  /** The route the workspace opens at. */
  home: string;
  role_codes: string[];
  contexts: WorkspaceContext[];
}

export interface AuthUser {
  id: number;
  email: string;
  status: string;
  timezone: string;
  last_login_at: string;
  /**
   * @deprecated Binary summary retained for compatibility while consumers
   * migrate. Use `workspaces` — it expresses everything `primary_role` cannot
   * (site-operational authority, multiple authorised contexts).
   */
  primary_role: 'admin' | 'missionary' | null;
  roles: Array<{
    code: string;
    label: string;
    scope_type: string;
  }>;
  /**
   * Operational workspaces this person may enter, derived on the backend from
   * their effective role assignments. An empty array means none is available
   * yet and the client must show a neutral state.
   */
  workspaces: WorkspaceDescriptor[];
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
  /** The site the team belongs to. Present on the team-detail response. */
  site?: MissionSite | null;
  /** Where the members come from. Present on the team-detail response. */
  origin_org_unit?: { id: number; name: string } | null;
}

/** A team together with its CURRENT roster, as returned by the detail endpoint. */
export interface TeamDetail extends Team {
  members: TeamMember[];
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
    api.get<TeamDetail>(`/campaigns/${campaignId}/teams/${teamId}`),
  create:     (campaignId: number, data: { name: string; description?: string; mission_site_id?: number; team_type_id?: number; origin_org_unit_id?: number }) =>
    api.post<Team>(`/campaigns/${campaignId}/teams`, data),
  update:     (campaignId: number, teamId: number, data: { name?: string; description?: string | null; team_type_id?: number | null; origin_org_unit_id?: number | null }) =>
    api.patch<Team>(`/campaigns/${campaignId}/teams/${teamId}`, data),
  addMember:  (campaignId: number, teamId: number, data: { person_id: number; role_in_team: string }) =>
    api.post<TeamMember>(`/campaigns/${campaignId}/teams/${teamId}/members`, data),
  // Changing a member's role is how leadership is transferred: setting a
  // member to 'leader' demotes the sitting leader in the same operation.
  updateMember: (campaignId: number, teamId: number, memberId: number, data: { role_in_team: string }) =>
    api.patch<TeamMember>(`/campaigns/${campaignId}/teams/${teamId}/members/${memberId}`, data),
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

    // Call the backend directly (when API_ORIGIN is set) to bypass the Next.js
    // proxy timeout on long-running requests
    return fetch(`${API_ORIGIN}/api/v1/transcribe`, {
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

    // Call the backend directly (when API_ORIGIN is set) to bypass the Next.js
    // proxy timeout on long-running requests
    return fetch(`${API_ORIGIN}/api/v1/synthesize`, {
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

/**
 * Laravel's `LengthAwarePaginator` envelope, returned by every paginated list
 * endpoint. `last_page` is what makes real paging possible; the endpoints cap
 * `per_page` at 100 (`between:5,100`), so a site larger than one page must be
 * walked through `page` rather than asked for in a single oversized request.
 */
export interface Paginated<T> {
  data: T[];
  current_page: number;
  per_page: number;
  last_page: number;
  total: number;
}

export const peopleApi = {
  list: (params?: { search?: string; campaign_id?: number; mission_site_id?: number; per_page?: number; page?: number; include?: string }) => {
    const q = buildQuery(params);
    return api.get<Paginated<Person>>(`/people${q}`);
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
  list: (params?: { campaign_id?: number; mission_site_id?: number; activity_id?: number; date?: string; status?: string; per_page?: number; page?: number }) => {
    const q = buildQuery(params);
    return api.get<Paginated<AttendanceRegister>>(`/attendance-registers${q}`);
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
  list: (params?: { engagement_stage?: string; campaign_id?: number; mission_site_id?: number; is_active?: boolean; search?: string; per_page?: number; page?: number }) => {
    const q = buildQuery(params);
    return api.get<Paginated<OutreachContact>>(`/outreach-contacts${q}`);
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

// ── Resources API ─────────────────────────────────────────────────────────────

export interface Resource {
  id: number;
  uuid: string;
  title: string;
  description: string | null;
  category_id: number | null;
  category?: { id: number; label: string };
  language: string;
  intended_audience: string | null;
  publisher: string | null;
  publication_date: string | null;
  version_number: string;
  status: 'draft' | 'pending_review' | 'published' | 'archived';
  current_version_id: number | null;
  currentVersion?: ResourceVersion;
  thumbnail_storage_disk: string | null;
  thumbnail_storage_path: string | null;
  published_at: string | null;
  archived_at: string | null;
  published_by_user_id: number | null;
  created_by_user_id: number;
  updated_by_user_id: number;
  origin_assignment_id: number | null;
  created_at: string;
  updated_at: string;
  tags?: ResourceTag[];
  createdBy?: { id: number; first_name: string; last_name: string };
  publishedBy?: { id: number; first_name: string; last_name: string };
}

export interface ProcessingStatus {
  status: 'pending' | 'processing' | 'completed' | 'failed';
  resource_version_id: number;
  started_at: string | null;
  completed_at: string | null;
  total_pages: number | null;
  extracted_pages: number | null;
  chunk_count: number | null;
  error_message: string | null;
}

export interface ResourceVersion {
  id: number;
  resource_id: number;
  version_number: string;
  storage_disk: string;
  storage_path: string;
  original_filename: string;
  mime_type: string;
  media_type: string;
  file_size: number;
  change_reason: string | null;
  replaced_by_user_id: number | null;
  created_at: string;
  processing_status?: ProcessingStatus | null;
}

export interface ResourceTag {
  id: number;
  resource_id: number;
  tag: string;
}

export interface ResourceDistributionRequest {
  id: number;
  resource_id: number;
  requested_by_user_id: number;
  audience_type: string;
  audience_key: string;
  target_org_unit_id: number | null;
  target_campaign_id: number | null;
  target_site_id: number | null;
  target_team_id: number | null;
  recipient_user_id: number | null;
  resource_version_id: number;
  supersedes_request_id: number | null;
  status: 'pending_review' | 'returned' | 'rejected' | 'approved' | 'revoked' | 'superseded';
  submitted_at: string;
  decided_at: string | null;
  decided_by_user_id: number | null;
  decision_reason: string | null;
  created_at: string;
  updated_at: string;
  resource?: Resource;
  version?: ResourceVersion;
  requester?: { id: number; first_name: string; last_name: string };
  decisions?: Array<{
    id: number;
    action: string;
    reason: string | null;
    actor_user_id: number;
    actor?: { id: number; first_name: string; last_name: string };
    created_at: string;
  }>;
}

export interface DistributionTargets {
  organizational_units: Array<{ id: number; name: string; parent_id: number | null; org_unit_type_id: number; short_code: string; type?: { id: number; code: string; name: string } }>;
  campaigns: Array<{ id: number; name: string; org_unit: string | null }>;
  sites: Array<{ id: number; name: string; campaign_id: number }>;
  teams: Array<{ id: number; name: string; campaign_id: number; site_id: number | null }>;
  audience_types: string[];
}

export const resourcesApi = {
  // List published resources (library view)
  list: (params?: {
    search?: string;
    category_id?: number;
    language?: string;
    intended_audience?: string;
    tag?: string;
    per_page?: number;
  }) => {
    const q = buildQuery(params);
    return api.get<{ data: Resource[]; total: number; current_page: number; last_page: number }>(`/resources${q}`);
  },

  // List user's own drafts
  myDrafts: (params?: { per_page?: number; page?: number }) => {
    const q = buildQuery(params);
    return api.get<{ data: Resource[]; total: number; current_page: number; last_page: number }>(`/resources/my-drafts${q}`);
  },

  get: (id: number) => api.get<Resource>(`/resources/${id}`),

  create: (data: FormData) => api.post<Resource>('/resources', data),

  update: (id: number, data: FormData) => api.patch<Resource>(`/resources/${id}`, data),

  replaceFile: (id: number, data: { file: File; change_reason: string }) => {
    const formData = new FormData();
    formData.append('file', data.file);
    formData.append('change_reason', data.change_reason);
    return api.post<Resource>(`/resources/${id}/replace-file`, formData);
  },

  archive: (id: number) => api.post<Resource>(`/resources/${id}/archive`, {}),

  versions: (id: number) => api.get<ResourceVersion[]>(`/resources/${id}/versions`),

  previewBlob: (id: number) => requestBlob(`/resources/${id}/preview`),

  downloadBlob: (id: number) => requestBlob(`/resources/${id}/download`),

  thumbnailBlob: (id: number) => requestBlob(`/resources/${id}/thumbnail`),

  // Distribution requests
  distributionTargets: () => api.get<DistributionTargets>('/resources/distribution-targets'),

  distributionRequests: (params?: { status?: string; per_page?: number; page?: number }) => {
    const q = buildQuery(params);
    return api.get<{ data: ResourceDistributionRequest[]; total: number; current_page: number; last_page: number }>(`/resources/distribution-requests${q}`);
  },

  submitDistribution: (resourceId: number, data: {
    audience_type: string;
    target_org_unit_id?: number;
    target_campaign_id?: number;
    target_site_id?: number;
    target_team_id?: number;
    recipient_user_id?: number;
    supersedes_request_id?: number;
  }) => api.post<ResourceDistributionRequest>(`/resources/${resourceId}/distribution-requests`, data),

  resubmitDistribution: (requestId: number) => api.post<ResourceDistributionRequest>(`/resources/distribution-requests/${requestId}/resubmit`, {}),

  decideDistribution: (requestId: number, data: { action: 'approved' | 'rejected' | 'returned'; reason?: string }) =>
    api.post<ResourceDistributionRequest>(`/resources/distribution-requests/${requestId}/decision`, data),

  revokeDistribution: (requestId: number, data: { reason: string }) =>
    api.post<ResourceDistributionRequest>(`/resources/distribution-requests/${requestId}/revoke`, data),

  // Reading progress
  progress: {
    get: (resourceId: number, versionId?: number) => {
      const q = versionId ? `?version_id=${versionId}` : '';
      return api.get(`/resources/${resourceId}/progress${q}`);
    },
    save: (resourceId: number, data: {
      version_id?: number;
      last_page: number;
      total_pages?: number;
      completion_percentage: number;
      reading_position?: any;
    }) => api.post(`/resources/${resourceId}/progress`, data),
    history: (params?: { per_page?: number; recent?: boolean }) => {
      const q = buildQuery(params);
      return api.get(`/resources/reading-resources${q}`);
    },
  },

  // Bookmarks
  bookmarks: {
    list: (resourceId: number, versionId?: number) => {
      const q = versionId ? `?version_id=${versionId}` : '';
      return api.get(`/resources/${resourceId}/bookmarks${q}`);
    },
    create: (resourceId: number, data: {
      version_id?: number;
      page_number: number;
      position_data?: any;
      title: string;
    }) => api.post(`/resources/${resourceId}/bookmarks`, data),
    delete: (resourceId: number, bookmarkId: number) =>
      api.delete(`/resources/${resourceId}/bookmarks/${bookmarkId}`),
  },

  // Highlights
  highlights: {
    list: (resourceId: number, params?: { version_id?: number; page_number?: number }) => {
      const q = buildQuery(params);
      return api.get(`/resources/${resourceId}/highlights${q}`);
    },
    create: (resourceId: number, data: {
      version_id?: number;
      selected_text: string;
      page_number: number;
      bounding_box: any;
      color?: string;
      zoom_level?: number;
    }) => api.post(`/resources/${resourceId}/highlights`, data),
    update: (resourceId: number, highlightId: number, data: { color?: string }) =>
      api.patch(`/resources/${resourceId}/highlights/${highlightId}`, data),
    delete: (resourceId: number, highlightId: number) =>
      api.delete(`/resources/${resourceId}/highlights/${highlightId}`),
  },

  // Notes
  notes: {
    list: (resourceId: number, params?: { version_id?: number; page_number?: number }) => {
      const q = buildQuery(params);
      return api.get(`/resources/${resourceId}/notes${q}`);
    },
    create: (resourceId: number, data: {
      version_id?: number;
      content: string;
      page_number: number;
      position_data?: any;
    }) => api.post(`/resources/${resourceId}/notes`, data),
    update: (resourceId: number, noteId: number, data: { content: string }) =>
      api.patch(`/resources/${resourceId}/notes/${noteId}`, data),
    delete: (resourceId: number, noteId: number) =>
      api.delete(`/resources/${resourceId}/notes/${noteId}`),
  },

  // Saved resources
  saved: {
    list: (params?: { per_page?: number }) => {
      const q = buildQuery(params);
      return api.get(`/saved-resources${q}`);
    },
    save: (resourceId: number, versionId?: number) => {
      const data = versionId ? { version_id: versionId } : {};
      return api.post(`/resources/${resourceId}/save`, data);
    },
    remove: (resourceId: number) => api.delete(`/resources/${resourceId}/save`),
    status: (resourceId: number) => api.get(`/resources/${resourceId}/saved-status`),
  },

  // Downloads
  downloads: {
    list: (params?: { per_page?: number }) => {
      const q = buildQuery(params);
      return api.get(`/downloaded-resources${q}`);
    },
    record: (resourceId: number, data: {
      version_id?: number;
      file_size: number;
    }) => api.post(`/resources/${resourceId}/download-record`, data),
    updateAccess: (resourceId: number) => api.patch(`/resources/${resourceId}/download-record`, {}),
    remove: (resourceId: number) => api.delete(`/resources/${resourceId}/download-record`),
    status: (resourceId: number) => api.get(`/resources/${resourceId}/download-status`),
  },
};

// AI Learning Companion
export const ai = {
  chat: (resourceId: number, data: {
    message: string;
    conversation_id?: number;
    page_number?: number;
    selected_text?: string;
    context_type?: 'selected_text' | 'current_page' | 'whole_book' | 'general' | 'general_explanation';
  }) => api.post(`/resources/${resourceId}/ai/chat`, data),
  conversations: (resourceId: number) => api.get(`/resources/${resourceId}/ai/conversations`),
  allConversations: () => api.get('/ai/conversations'),
  getConversation: (conversationId: number) => api.get(`/ai/conversations/${conversationId}`),
  deleteConversation: (conversationId: number) => api.delete(`/ai/conversations/${conversationId}`),
  retryMessage: (messageId: number) => api.post(`/ai/messages/${messageId}/retry`),
  saveExplanation: (data: {
    conversation_message_id: number;
    title: string;
    content: string;
    page_number?: number;
    selected_text?: string;
  }) => api.post('/ai/saved-explanations', data),
  savedExplanations: (params?: { resource_id?: number }) => {
    const q = buildQuery(params);
    return api.get(`/ai/saved-explanations${q}`);
  },
};

// Learning Notes
export const learningNotesApi = {
  list: (resourceId: number, params?: { note_type?: string; chapter?: string }) => {
    const q = buildQuery(params);
    return api.get(`/resources/${resourceId}/learning-notes${q}`);
  },
  create: (resourceId: number, data: {
    note_type: 'understanding' | 'reflection' | 'chapter_summary' | 'book_summary' | 'important_passage' | 'question';
    title: string;
    content: string;
    chapter?: string;
    page_number?: number;
    passage_text?: string;
    passage_position?: any;
    saved_explanation_id?: number;
    conversation_message_id?: number;
  }) => api.post(`/resources/${resourceId}/learning-notes`, data),
  update: (resourceId: number, noteId: number, data: {
    title?: string;
    content?: string;
  }) => api.put(`/resources/${resourceId}/learning-notes/${noteId}`, data),
  delete: (resourceId: number, noteId: number) => api.delete(`/resources/${resourceId}/learning-notes/${noteId}`),
};

// ── Resource Categories ───────────────────────────────────────────────────────

export interface ResourceCategory {
  id: number;
  code: string;
  label: string;
  description: string | null;
  sort_order: number;
  is_active: boolean;
}

export const resourceCategoriesApi = {
  list: () => api.get<ResourceCategory[]>('/resource-categories'),
  get: (id: number) => api.get<ResourceCategory>(`/resource-categories/${id}`),
};

// ── Communication Centre ───────────────────────────────────────────────────────

export type CommunicationType = 'announcement' | 'organisational_update' | 'instruction';
export type CommunicationScope = 'global' | 'org_unit' | 'campaign' | 'site' | 'team';
export type CommunicationStatus = 'draft' | 'published' | 'revoked';

export interface CommunicationAuthor {
  id: number;
  name: string;
  email: string;
}

export interface Communication {
  id: number;
  title: string;
  body: string;
  communication_type: CommunicationType;
  scope_type: CommunicationScope;
  scope_id: number | null;
  scope_label: string;
  author: CommunicationAuthor | null;
  requires_acknowledgement: boolean;
  status: CommunicationStatus;
  published_at: string | null;
  expires_at: string | null;
  created_at: string | null;
  read_at: string | null;
  acknowledged_at: string | null;
  is_unread: boolean;
  is_acknowledged: boolean;
}

export interface CommunicationsListResponse {
  data: Communication[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    unread_count: number;
  };
}

export interface UnreadCountResponse {
  unread_count: number;
}

export const communicationsApi = {
  list: (params?: { per_page?: number }) => {
    const q = buildQuery(params);
    return api.get<CommunicationsListResponse>(`/communications${q}`);
  },
  unreadCount: () => api.get<UnreadCountResponse>('/communications/unread-count'),
  requiringAcknowledgement: () =>
    api.get<{ data: Communication[] }>('/communications/requiring-acknowledgement'),
  get: (id: number) => api.get<Communication>(`/communications/${id}`),
  create: (data: {
    title: string;
    body: string;
    communication_type: CommunicationType;
    scope_type: CommunicationScope;
    scoped_org_unit_id?: number;
    scoped_campaign_id?: number;
    scoped_site_id?: number;
    scoped_team_id?: number;
    requires_acknowledgement?: boolean;
    expires_at?: string;
    status?: 'draft' | 'published';
  }) => api.post<Communication>('/communications', data),
  update: (id: number, data: Partial<Communication>) =>
    api.patch<Communication>(`/communications/${id}`, data),
  revoke: (id: number) => api.delete<{ message: string }>(`/communications/${id}`),
  read: (id: number) => api.post<{ read_at: string | null }>(`/communications/${id}/read`),
  acknowledge: (id: number) =>
    api.post<{ acknowledged_at: string | null }>(`/communications/${id}/acknowledge`),
};
