'use client';

import { useMemo } from 'react';
import { AlertCircle, Building2, Globe2, Users } from 'lucide-react';
import type { DistributionTargets } from '@/lib/api';
import { cn } from '@/components/ui';
import {
  AUDIENCE_TYPE_LABELS,
  ORG_UNIT_TYPE_CODE,
  audienceTypeLabel,
  availableAudienceTypes,
  orgUnitsForAudience,
  type AudienceSelection,
} from './audience';

/**
 * Selects the audience proposal that accompanies a distribution submission.
 *
 * This is intentionally limited to the target data returned by
 * `/resources/distribution-targets`; the picker never invents a scope.
 */
export function AudienceScopePicker({
  targets,
  value,
  onChange,
  disabled = false,
  loading = false,
  error = null,
}: {
  targets: DistributionTargets | null;
  value: AudienceSelection;
  onChange: (next: AudienceSelection) => void;
  disabled?: boolean;
  loading?: boolean;
  error?: string | null;
}) {
  const types = useMemo(() => availableAudienceTypes(targets), [targets]);
  const orgUnits = useMemo(
    () => orgUnitsForAudience(targets, value.audience_type),
    [targets, value.audience_type]
  );

  if (loading) {
    return (
      <div className="rounded-xl border border-surface-border bg-surface-3/60 p-4 text-sm text-slate-400">
        Loading distribution scopes…
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex items-start gap-2 rounded-xl border border-red-500/30 bg-red-500/10 p-4 text-sm text-red-300">
        <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
        <span>{error}</span>
      </div>
    );
  }

  if (!targets) {
    return (
      <div className="rounded-xl border border-surface-border bg-surface-3/60 p-4 text-sm text-slate-400">
        Distribution scopes are unavailable right now.
      </div>
    );
  }

  const handleTypeChange = (audienceType: string) => {
    onChange({ audience_type: audienceType });
  };

  const handleOrgUnitChange = (id: number) => {
    onChange({ audience_type: value.audience_type, target_org_unit_id: id });
  };

  const handleCampaignChange = (id: number) => {
    onChange({ audience_type: value.audience_type, target_campaign_id: id });
  };

  const handleSiteChange = (id: number) => {
    onChange({ audience_type: value.audience_type, target_site_id: id });
  };

  const handleTeamChange = (id: number) => {
    onChange({ audience_type: value.audience_type, target_team_id: id });
  };

  return (
    <div className="space-y-4">
      <div>
        <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="audience-type">
          Proposed audience
        </label>
        <select
          id="audience-type"
          value={value.audience_type}
          onChange={(event) => handleTypeChange(event.target.value)}
          disabled={disabled || types.length === 0}
          className="w-full rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
        >
          <option value="">Select an audience type</option>
          {types.map((type) => (
            <option key={type} value={type}>
              {audienceTypeLabel(type)}
            </option>
          ))}
        </select>
      </div>

      {value.audience_type === 'union' && (
        <div className="flex items-start gap-3 rounded-xl border border-brand-500/20 bg-brand-500/10 p-3 text-sm text-brand-200">
          <Globe2 className="mt-0.5 h-4 w-4 flex-shrink-0" />
          <p>
            This proposal reaches the whole Uganda Union Mission. The reviewer can approve it,
            return it for changes, or reject it.
          </p>
        </div>
      )}

      {ORG_UNIT_TYPE_CODE[value.audience_type] && (
        <div>
          <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="audience-org-unit">
            {AUDIENCE_TYPE_LABELS[value.audience_type]}
          </label>
          <div className="relative">
            <Building2 className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
            <select
              id="audience-org-unit"
              value={value.target_org_unit_id ?? ''}
              onChange={(event) => handleOrgUnitChange(Number(event.target.value))}
              disabled={disabled || orgUnits.length === 0}
              className="w-full rounded-lg border border-surface-border bg-surface-3 py-2 pl-9 pr-4 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
            >
              <option value="">Select an organisational unit</option>
              {orgUnits.map((unit) => (
                <option key={unit.id} value={unit.id}>
                  {unit.name}
                  {unit.short_code ? ` (${unit.short_code})` : ''}
                </option>
              ))}
            </select>
          </div>
          {orgUnits.length === 0 && (
            <p className="mt-2 text-xs text-slate-500">
              No {AUDIENCE_TYPE_LABELS[value.audience_type].toLowerCase()} units are available in
              the distribution scope data.
            </p>
          )}
        </div>
      )}

      {value.audience_type === 'campaign' && (
        <div>
          <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="audience-campaign">
            Campaign
          </label>
          <select
            id="audience-campaign"
            value={value.target_campaign_id ?? ''}
            onChange={(event) => handleCampaignChange(Number(event.target.value))}
            disabled={disabled || targets.campaigns.length === 0}
            className="w-full rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
          >
            <option value="">Select a campaign</option>
            {targets.campaigns.map((campaign) => (
              <option key={campaign.id} value={campaign.id}>
                {campaign.name}
                {campaign.org_unit ? ` (${campaign.org_unit})` : ''}
              </option>
            ))}
          </select>
        </div>
      )}

      {value.audience_type === 'site' && (
        <div>
          <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="audience-site">
            Mission site
          </label>
          <select
            id="audience-site"
            value={value.target_site_id ?? ''}
            onChange={(event) => handleSiteChange(Number(event.target.value))}
            disabled={disabled || targets.sites.length === 0}
            className="w-full rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
          >
            <option value="">Select a mission site</option>
            {targets.sites.map((site) => {
              const campaign = targets.campaigns.find((item) => item.id === site.campaign_id);
              return (
                <option key={site.id} value={site.id}>
                  {site.name}
                  {campaign ? ` — ${campaign.name}` : ''}
                </option>
              );
            })}
          </select>
        </div>
      )}

      {value.audience_type === 'team' && (
        <div>
          <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="audience-team">
            Team
          </label>
          <select
            id="audience-team"
            value={value.target_team_id ?? ''}
            onChange={(event) => handleTeamChange(Number(event.target.value))}
            disabled={disabled || targets.teams.length === 0}
            className="w-full rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
          >
            <option value="">Select a team</option>
            {targets.teams.map((team) => {
              const campaign = targets.campaigns.find((item) => item.id === team.campaign_id);
              return (
                <option key={team.id} value={team.id}>
                  {team.name}
                  {campaign ? ` — ${campaign.name}` : ''}
                </option>
              );
            })}
          </select>
        </div>
      )}

      {types.length === 0 && (
        <div className={cn('flex items-start gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-200')}>
          <Users className="mt-0.5 h-4 w-4 flex-shrink-0" />
          <span>
            No distribution scopes are available to propose. An administrator must configure
            organisational units, campaigns, sites, or teams first.
          </span>
        </div>
      )}

      <p className="text-xs text-slate-500">
        This is a proposal for review. Submitting does not publish the resource, and the reviewer
        confirms the distribution decision.
      </p>
    </div>
  );
}
