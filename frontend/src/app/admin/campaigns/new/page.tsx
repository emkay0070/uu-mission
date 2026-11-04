'use client';
import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { useForm } from 'react-hook-form';
import { campaignsApi, refApi } from '@/lib/api';
import type { ApiError } from '@/lib/api';
import { ArrowLeft, Globe } from 'lucide-react';
import { ErrorBanner, Spinner } from '@/components/ui';

type CampaignForm = {
  name: string;
  description: string;
  programme_id: string;
  lead_org_unit_id: string;
  start_date: string;
  end_date: string;
};

export default function NewCampaignPage() {
  const router = useRouter();
  const [programmes, setProgrammes] = useState<{ id: number; name: string }[]>([]);
  const [orgUnits,   setOrgUnits]   = useState<{ id: number; name: string }[]>([]);
  const [busy,       setBusy]       = useState(false);
  const [apiError,   setApiError]   = useState('');

  const { register, handleSubmit, formState: { errors } } = useForm<CampaignForm>();

  useEffect(() => {
    Promise.all([refApi.programmes(), refApi.orgUnits()])
      .then(([p, o]) => { setProgrammes(p); setOrgUnits(o); })
      .catch((e: unknown) => {
        setApiError('Could not load form options: ' + ((e as { message?: string }).message ?? 'unknown error'));
      });
  }, []);

  async function onSubmit(data: CampaignForm) {
    setBusy(true); setApiError('');
    try {
      const campaign = await campaignsApi.create({
        name:             data.name,
        description:      data.description || undefined,
        programme_id:     Number(data.programme_id),
        lead_org_unit_id: Number(data.lead_org_unit_id),
        start_date:       data.start_date || undefined,
        end_date:         data.end_date || undefined,
      });
      router.push(`/admin/campaigns/${campaign.id}`);
    } catch (e: unknown) {
      const err = e as ApiError;
      setApiError(
        err.errors
          ? Object.values(err.errors).flat().join(' ')
          : err.message
      );
      setBusy(false);
    }
  }

  return (
    <div className="p-6 max-w-2xl mx-auto animate-fade-up">
      <Link
        href="/campaigns"
        className="flex items-center gap-1.5 text-xs text-slate-500 hover:text-slate-300 mb-6 transition-colors"
      >
        <ArrowLeft className="w-3.5 h-3.5" /> All campaigns
      </Link>

      <div className="flex items-center gap-3 mb-8">
        <div className="w-10 h-10 rounded-xl bg-brand-600/20 border border-brand-600/30 flex items-center justify-center">
          <Globe className="w-5 h-5 text-brand-400" />
        </div>
        <div>
          <h1 className="text-xl font-bold text-slate-100">New Campaign</h1>
          <p className="text-sm text-slate-400">Set up a new mission campaign</p>
        </div>
      </div>

      <form onSubmit={handleSubmit(onSubmit)} className="card p-6 space-y-5">
        {apiError && <ErrorBanner message={apiError} />}

        <div>
          <label className="block text-xs text-slate-400 mb-1.5">Campaign Name *</label>
          <input
            {...register('name', { required: 'Name is required' })}
            className="input"
            placeholder="e.g. Lira–Kato Global Mission 2027"
          />
          {errors.name && <p className="text-xs text-red-400 mt-1">{errors.name.message}</p>}
        </div>

        <div>
          <label className="block text-xs text-slate-400 mb-1.5">Description</label>
          <textarea
            {...register('description')}
            className="input min-h-[80px] resize-none"
            placeholder="Brief description of this campaign's purpose and scope…"
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-xs text-slate-400 mb-1.5">Programme *</label>
            <select {...register('programme_id', { required: 'Programme is required' })} className="input">
              <option value="">Select programme…</option>
              {programmes.map(p => (
                <option key={p.id} value={p.id}>{p.name}</option>
              ))}
            </select>
            {errors.programme_id && <p className="text-xs text-red-400 mt-1">{errors.programme_id.message}</p>}
          </div>

          <div>
            <label className="block text-xs text-slate-400 mb-1.5">Lead Organisation *</label>
            <select {...register('lead_org_unit_id', { required: 'Organisation is required' })} className="input">
              <option value="">Select org unit…</option>
              {orgUnits.map(o => (
                <option key={o.id} value={o.id}>{o.name}</option>
              ))}
            </select>
            {errors.lead_org_unit_id && <p className="text-xs text-red-400 mt-1">{errors.lead_org_unit_id.message}</p>}
          </div>

          <div>
            <label className="block text-xs text-slate-400 mb-1.5">Start Date</label>
            <input type="date" {...register('start_date')} className="input" />
          </div>

          <div>
            <label className="block text-xs text-slate-400 mb-1.5">End Date</label>
            <input type="date" {...register('end_date')} className="input" />
          </div>
        </div>

        <div className="flex items-center gap-3 pt-2 border-t border-surface-border">
          <button type="submit" disabled={busy} className="btn-primary">
            {busy ? <><Spinner size={4} /> Creating…</> : <><Globe className="w-4 h-4" /> Create Campaign</>}
          </button>
          <Link href="/campaigns" className="btn-ghost">Cancel</Link>
        </div>
      </form>
    </div>
  );
}
