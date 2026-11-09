import type { DistributionTargets, ResourceDistributionRequest } from '@/lib/api';

/**
 * Audience helpers for the resource distribution workflow.
 *
 * The backend owns the list of supported audience types and the target
 * validation rules. These helpers only turn the API contract into labels and
 * a predictable form shape — they never invent an audience type.
 */

export const AUDIENCE_TYPE_LABELS: Record<string, string> = {
  union: 'Uganda Union Mission (mission-wide)',
  conference: 'Conference',
  mission_field: 'Mission field',
  zone: 'Zone',
  church_district: 'Church district',
  local_church: 'Local church',
  campaign: 'Campaign',
  site: 'Mission site',
  team: 'Team',
  private: 'Private recipient',
};

/** Backend `org_unit_type.code` expected for each organisational audience. */
export const ORG_UNIT_TYPE_CODE: Record<string, string> = {
  conference: 'conference',
  mission_field: 'mission_field',
  zone: 'zone',
  church_district: 'district',
  local_church: 'local_church',
};

export const AUDIENCE_ORDER = [
  'union',
  'conference',
  'mission_field',
  'zone',
  'church_district',
  'local_church',
  'campaign',
  'site',
  'team',
  'private',
];

export type AudienceTargetField =
  | 'target_org_unit_id'
  | 'target_campaign_id'
  | 'target_site_id'
  | 'target_team_id'
  | 'recipient_user_id';

const AUDIENCE_TARGET_FIELD: Record<string, AudienceTargetField> = {
  conference: 'target_org_unit_id',
  mission_field: 'target_org_unit_id',
  zone: 'target_org_unit_id',
  church_district: 'target_org_unit_id',
  local_church: 'target_org_unit_id',
  campaign: 'target_campaign_id',
  site: 'target_site_id',
  team: 'target_team_id',
  private: 'recipient_user_id',
};

export interface AudienceSelection {
  audience_type: string;
  target_org_unit_id?: number;
  target_campaign_id?: number;
  target_site_id?: number;
  target_team_id?: number;
  recipient_user_id?: number;
}

export interface DistributionSubmission {
  audience_type: string;
  target_org_unit_id?: number;
  target_campaign_id?: number;
  target_site_id?: number;
  target_team_id?: number;
  recipient_user_id?: number;
  supersedes_request_id?: number;
}

export function audienceTypeLabel(type: string | null | undefined): string {
  if (!type) return 'No audience';
  return AUDIENCE_TYPE_LABELS[type] ?? type.replaceAll('_', ' ');
}

export function emptyAudienceSelection(type = ''): AudienceSelection {
  return { audience_type: type };
}

/** Whether the selected audience has every target the backend requires. */
export function isAudienceSelectionComplete(selection: AudienceSelection | null | undefined): boolean {
  if (!selection?.audience_type) return false;

  switch (selection.audience_type) {
    case 'union':
      return true;
    case 'conference':
    case 'mission_field':
    case 'zone':
    case 'church_district':
    case 'local_church':
      return Boolean(selection.target_org_unit_id);
    case 'campaign':
      return Boolean(selection.target_campaign_id);
    case 'site':
      return Boolean(selection.target_site_id);
    case 'team':
      return Boolean(selection.target_team_id);
    case 'private':
      return Boolean(selection.recipient_user_id);
    default:
      return false;
  }
}

/**
 * Build the exact JSON body the distribution endpoint expects.
 *
 * Only the target that belongs to the selected audience type is sent, so the
 * backend's cross-target validation can never reject an accidental stale id.
 */
export function toDistributionSubmission(selection: AudienceSelection): DistributionSubmission {
  const payload: DistributionSubmission = { audience_type: selection.audience_type };

  switch (selection.audience_type) {
    case 'conference':
    case 'mission_field':
    case 'zone':
    case 'church_district':
    case 'local_church':
      if (selection.target_org_unit_id) payload.target_org_unit_id = selection.target_org_unit_id;
      break;
    case 'campaign':
      if (selection.target_campaign_id) payload.target_campaign_id = selection.target_campaign_id;
      break;
    case 'site':
      if (selection.target_site_id) payload.target_site_id = selection.target_site_id;
      break;
    case 'team':
      if (selection.target_team_id) payload.target_team_id = selection.target_team_id;
      break;
    case 'private':
      if (selection.recipient_user_id) payload.recipient_user_id = selection.recipient_user_id;
      break;
  }

  return payload;
}

export function selectionFromRequest(request: ResourceDistributionRequest): AudienceSelection {
  return {
    audience_type: request.audience_type,
    target_org_unit_id: request.target_org_unit_id ?? undefined,
    target_campaign_id: request.target_campaign_id ?? undefined,
    target_site_id: request.target_site_id ?? undefined,
    target_team_id: request.target_team_id ?? undefined,
    recipient_user_id: request.recipient_user_id ?? undefined,
  };
}

/** Whether the targets payload contains at least one selectable option. */
export function hasTargetsForAudience(
  targets: DistributionTargets | null | undefined,
  type: string
): boolean {
  if (!targets) return false;
  if (type === 'union') return true;
  if (ORG_UNIT_TYPE_CODE[type]) {
    const expected = ORG_UNIT_TYPE_CODE[type];
    return targets.organizational_units.some((unit) => unit.type?.code === expected);
  }
  if (type === 'campaign') return targets.campaigns.length > 0;
  if (type === 'site') return targets.sites.length > 0;
  if (type === 'team') return targets.teams.length > 0;
  return false;
}

/**
 * Audience types that can actually be selected from the data the API returns.
 *
 * `private` is omitted deliberately: the distribution contract requires a
 * `recipient_user_id`, but `/distribution-targets` does not expose a list of
 * recipient accounts. Showing a private option the UI cannot complete would be
 * worse than documenting the gap.
 */
export function availableAudienceTypes(targets: DistributionTargets | null | undefined): string[] {
  if (!targets) return [];
  const declared = targets.audience_types?.length
    ? targets.audience_types
    : Object.keys(AUDIENCE_TYPE_LABELS);

  return AUDIENCE_ORDER.filter(
    (type) =>
      type !== 'private' &&
      declared.includes(type) &&
      hasTargetsForAudience(targets, type)
  );
}

export function orgUnitsForAudience(
  targets: DistributionTargets | null | undefined,
  type: string
) {
  if (!targets) return [];
  const expected = ORG_UNIT_TYPE_CODE[type];
  if (!expected) return [];
  return targets.organizational_units.filter((unit) => unit.type?.code === expected);
}

/** Human-readable description of the audience attached to a request. */
export function formatAudience(
  request: ResourceDistributionRequest | null | undefined,
  targets?: DistributionTargets | null
): string {
  if (!request) return 'No distribution request';

  const label = audienceTypeLabel(request.audience_type);

  if (request.audience_type === 'private') {
    return `${label}${request.recipient_user_id ? ` · user #${request.recipient_user_id}` : ''}`;
  }

  if (request.audience_type === 'union') {
    return label;
  }

  if (ORG_UNIT_TYPE_CODE[request.audience_type]) {
    const unit = targets?.organizational_units.find((item) => item.id === request.target_org_unit_id);
    return unit ? `${label} · ${unit.name}` : `${label} · org unit #${request.target_org_unit_id ?? '—'}`;
  }

  if (request.audience_type === 'campaign') {
    const campaign = targets?.campaigns.find((item) => item.id === request.target_campaign_id);
    return campaign ? `${label} · ${campaign.name}` : `${label} · campaign #${request.target_campaign_id ?? '—'}`;
  }

  if (request.audience_type === 'site') {
    const site = targets?.sites.find((item) => item.id === request.target_site_id);
    const campaign = targets?.campaigns.find((item) => item.id === site?.campaign_id);
    if (!site) return `${label} · site #${request.target_site_id ?? '—'}`;
    return `${label} · ${site.name}${campaign ? ` (${campaign.name})` : ''}`;
  }

  if (request.audience_type === 'team') {
    const team = targets?.teams.find((item) => item.id === request.target_team_id);
    const campaign = targets?.campaigns.find((item) => item.id === team?.campaign_id);
    if (!team) return `${label} · team #${request.target_team_id ?? '—'}`;
    return `${label} · ${team.name}${campaign ? ` (${campaign.name})` : ''}`;
  }

  return label;
}

export function formatFileSize(bytes: number | null | undefined): string {
  if (!bytes || bytes <= 0) return 'Size unavailable';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function targetFieldForAudience(type: string): AudienceTargetField | null {
  return AUDIENCE_TARGET_FIELD[type] ?? null;
}
