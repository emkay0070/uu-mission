'use client';

import { useEffect, useState } from 'react';
import { Modal } from '@/components/ui';
import { campaignsApi, sitesApi, refApi, outreachContactsApi } from '@/lib/api';
import { cn } from '@/components/ui';

interface Props {
  open: boolean;
  onClose: () => void;
  onCreated: () => void;
}

export default function AddOutreachContactModal({ open, onClose, onCreated }: Props) {
  const [campaigns, setCampaigns] = useState<any[]>([]);
  const [sites, setSites] = useState<any[]>([]);
  const [stages, setStages] = useState<any[]>([]);
  const [campaignId, setCampaignId] = useState<number | ''>('');
  const [siteId, setSiteId] = useState<number | ''>('');
  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [phone, setPhone] = useState('');
  const [stage, setStage] = useState('');
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return;
    (async () => {
      try {
        const c = await campaignsApi.list();
        setCampaigns((c as any)?.data ?? []);
        const s = await refApi.engagementStages();
        setStages(Array.isArray(s) ? s : (s as any)?.data ?? []);
      } catch (e: any) {
        setError(e?.message || 'Failed to load form data');
      }
    })();
  }, [open]);

  useEffect(() => {
    if (!campaignId) { setSites([]); setSiteId(''); return; }
    (async () => {
      try {
        const s = await sitesApi.list(Number(campaignId));
        setSites(Array.isArray(s) ? s : []);
      } catch (e: any) {
        setError(e?.message || 'Failed to load sites');
      }
    })();
  }, [campaignId]);

  const reset = () => {
    setCampaignId(''); setSiteId(''); setFirstName(''); setLastName('');
    setPhone(''); setStage(''); setNotes(''); setError('');
  };

  const handleClose = () => { reset(); onClose(); };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!firstName.trim() || !lastName.trim() || !campaignId || !siteId || !stage) {
      setError('First name, last name, campaign, site and engagement stage are required.');
      return;
    }
    setSubmitting(true);
    setError('');
    try {
      await outreachContactsApi.create({
        first_name: firstName.trim(),
        last_name: lastName.trim(),
        phone: phone.trim() || undefined,
        notes: notes.trim() || undefined,
        engagement_stage: stage,
        campaign_id: Number(campaignId),
        mission_site_id: Number(siteId),
      });
      reset();
      onCreated();
      onClose();
    } catch (err: any) {
      setError(err?.message || 'Failed to create outreach contact');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Modal open={open} onClose={handleClose} title="Add Outreach Contact">
      <form onSubmit={handleSubmit} className="space-y-4">
        {error && (
          <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">{error}</div>
        )}
        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="block text-sm font-medium text-slate-300 mb-1.5">First Name</label>
            <input value={firstName} onChange={e => setFirstName(e.target.value)} required
              className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-300 mb-1.5">Last Name</label>
            <input value={lastName} onChange={e => setLastName(e.target.value)} required
              className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
          </div>
        </div>
        <div>
          <label className="block text-sm font-medium text-slate-300 mb-1.5">Campaign</label>
          <select value={campaignId} onChange={e => setCampaignId(e.target.value ? Number(e.target.value) : '')}
            className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" required>
            <option value="">Select campaign</option>
            {campaigns.map((c: any) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </div>
        <div>
          <label className="block text-sm font-medium text-slate-300 mb-1.5">Mission Site</label>
          <select value={siteId} onChange={e => setSiteId(e.target.value ? Number(e.target.value) : '')} disabled={!campaignId}
            className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 disabled:opacity-50" required>
            <option value="">Select site</option>
            {sites.map((s: any) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
        </div>
        <div>
          <label className="block text-sm font-medium text-slate-300 mb-1.5">Engagement Stage</label>
          <select value={stage} onChange={e => setStage(e.target.value)}
            className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" required>
            <option value="">Select stage</option>
            {stages.map((st: any) => <option key={st.id ?? st.code} value={st.code}>{st.label ?? st.code}</option>)}
          </select>
        </div>
        <div>
          <label className="block text-sm font-medium text-slate-300 mb-1.5">Phone (Optional)</label>
          <input value={phone} onChange={e => setPhone(e.target.value)}
            className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
        </div>
        <div>
          <label className="block text-sm font-medium text-slate-300 mb-1.5">Notes (Optional)</label>
          <textarea value={notes} onChange={e => setNotes(e.target.value)} rows={3}
            className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
        </div>
        <div className="flex justify-end gap-3 pt-2">
          <button type="button" onClick={handleClose} className="btn-ghost">Cancel</button>
          <button type="submit" disabled={submitting} className="btn-primary">
            {submitting ? 'Creating...' : 'Create Contact'}
          </button>
        </div>
      </form>
    </Modal>
  );
}
