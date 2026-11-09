'use client';

import { useEffect, useState } from 'react';
import {
  AlertCircle,
  FileText,
  Loader2,
  Pencil,
  RefreshCw,
  Save,
  Upload,
  X,
} from 'lucide-react';
import type { Resource, ResourceCategory } from '@/lib/api';
import { resourcesApi } from '@/lib/api';
import { formatFileSize } from './audience';

interface EditFormState {
  title: string;
  description: string;
  category_id: string;
  language: string;
  intended_audience: string;
  publisher: string;
  publication_date: string;
  tags: string;
}

const EMPTY_FORM: EditFormState = {
  title: '',
  description: '',
  category_id: '',
  language: 'en',
  intended_audience: '',
  publisher: '',
  publication_date: '',
  tags: '',
};

function formFromResource(resource: Resource): EditFormState {
  return {
    title: resource.title ?? '',
    description: resource.description ?? '',
    category_id: resource.category_id ? String(resource.category_id) : '',
    language: resource.language || 'en',
    intended_audience: resource.intended_audience ?? '',
    publisher: resource.publisher ?? '',
    publication_date: resource.publication_date ?? '',
    tags: (resource.tags ?? []).map((tag) => tag.tag).join(', '),
  };
}

/**
 * Edit an existing draft, including an optional replacement file.
 *
 * The dialog always reloads the resource first so the form is based on the
 * canonical record rather than a list row that may have been joined from a
 * distribution request response.
 */
export function EditResourceDialog({
  open,
  resourceId,
  categories,
  onClose,
  onSaved,
}: {
  open: boolean;
  resourceId: number | null;
  categories: ResourceCategory[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [resource, setResource] = useState<Resource | null>(null);
  const [form, setForm] = useState<EditFormState>(EMPTY_FORM);
  const [loading, setLoading] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [replacementFile, setReplacementFile] = useState<File | null>(null);
  const [changeReason, setChangeReason] = useState('');

  useEffect(() => {
    if (!open || !resourceId) return;

    let cancelled = false;
    setLoading(true);
    setLoadError(null);
    setError(null);
    setReplacementFile(null);
    setChangeReason('');

    resourcesApi
      .get(resourceId)
      .then((data) => {
        if (cancelled) return;
        setResource(data);
        setForm(formFromResource(data));
      })
      .catch((err: any) => {
        if (cancelled) return;
        setLoadError(err?.message || 'Unable to load this draft.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [open, resourceId]);

  if (!open || !resourceId) return null;

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!resource) return;

    if (!form.title.trim()) {
      setError('A title is required.');
      return;
    }

    if (replacementFile && !changeReason.trim()) {
      setError('Describe why the file is being replaced.');
      return;
    }

    setSaving(true);
    setError(null);

    try {
      const body = new FormData();
      body.append('title', form.title.trim());
      body.append('description', form.description);
      body.append('category_id', form.category_id);
      body.append('language', form.language);
      body.append('intended_audience', form.intended_audience);
      body.append('publisher', form.publisher);
      body.append('publication_date', form.publication_date);

      const tags = form.tags
        .split(',')
        .map((tag) => tag.trim())
        .filter(Boolean);
      if (tags.length > 0) {
        tags.forEach((tag) => body.append('tags[]', tag));
      } else {
        // The API treats an empty value as null and replaces the tag list, so
        // clearing every tag in the editor actually removes them.
        body.append('tags', '');
      }

      await resourcesApi.update(resource.id, body);

      if (replacementFile) {
        await resourcesApi.replaceFile(resource.id, {
          file: replacementFile,
          change_reason: changeReason.trim(),
        });
      }

      onSaved();
      onClose();
    } catch (err: any) {
      setError(err?.message || 'The draft could not be saved.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-[9999] flex items-center justify-center bg-black/65 p-3 backdrop-blur-sm sm:p-6">
      <div className="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-2xl border border-surface-border bg-surface-2 p-5 shadow-2xl sm:p-6">
        <div className="mb-5 flex items-start justify-between gap-4">
          <div>
            <div className="mb-2 flex items-center gap-2 text-brand-400">
              <Pencil className="h-5 w-5" />
              <span className="text-xs font-semibold uppercase tracking-wider">Draft editor</span>
            </div>
            <h2 className="text-xl font-bold text-slate-100">Edit draft</h2>
            <p className="mt-1 text-sm text-slate-400">
              Update the resource details. Audience selection happens when you submit for review.
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-surface-3 hover:text-slate-200"
            aria-label="Close"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {loading ? (
          <div className="flex items-center justify-center gap-3 py-16 text-slate-400">
            <Loader2 className="h-5 w-5 animate-spin text-brand-400" />
            Loading draft…
          </div>
        ) : loadError ? (
          <div className="flex items-start gap-2 rounded-lg border border-red-500/30 bg-red-500/10 p-4 text-sm text-red-300">
            <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
            <span>{loadError}</span>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="rounded-xl border border-surface-border bg-surface-3/60 p-4">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                  <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-brand-500/15 text-brand-400">
                    <FileText className="h-5 w-5" />
                  </div>
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-slate-200">
                      {resource?.currentVersion?.original_filename ?? 'No file attached'}
                    </p>
                    <p className="text-xs text-slate-500">
                      {resource?.currentVersion?.file_size
                        ? formatFileSize(resource.currentVersion.file_size)
                        : 'Current version'}
                    </p>
                  </div>
                </div>
                <span className="rounded-full bg-slate-700/40 px-3 py-1 text-xs text-slate-400">
                  {resource?.currentVersion?.media_type?.toUpperCase() ?? 'FILE'}
                </span>
              </div>
            </div>

            <div>
              <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="edit-title">
                Title <span className="text-red-400">*</span>
              </label>
              <input
                id="edit-title"
                type="text"
                value={form.title}
                onChange={(event) => setForm({ ...form, title: event.target.value })}
                disabled={saving}
                required
                maxLength={255}
                className="w-full rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
              />
            </div>

            <div>
              <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="edit-description">
                Description
              </label>
              <textarea
                id="edit-description"
                value={form.description}
                onChange={(event) => setForm({ ...form, description: event.target.value })}
                disabled={saving}
                rows={4}
                className="w-full resize-none rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
              />
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="edit-category">
                  Category
                </label>
                <select
                  id="edit-category"
                  value={form.category_id}
                  onChange={(event) => setForm({ ...form, category_id: event.target.value })}
                  disabled={saving}
                  className="w-full rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
                >
                  <option value="">No category</option>
                  {categories.map((category) => (
                    <option key={category.id} value={category.id}>
                      {category.label}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="edit-language">
                  Language
                </label>
                <select
                  id="edit-language"
                  value={form.language}
                  onChange={(event) => setForm({ ...form, language: event.target.value })}
                  disabled={saving}
                  className="w-full rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
                >
                  <option value="en">English</option>
                  <option value="es">Spanish</option>
                  <option value="fr">French</option>
                  <option value="pt">Portuguese</option>
                  <option value="sw">Swahili</option>
                  <option value="lg">Luganda</option>
                  <option value="ach">Acholi</option>
                </select>
              </div>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="edit-publisher">
                  Publisher
                </label>
                <input
                  id="edit-publisher"
                  type="text"
                  value={form.publisher}
                  onChange={(event) => setForm({ ...form, publisher: event.target.value })}
                  disabled={saving}
                  className="w-full rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
                />
              </div>

              <div>
                <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="edit-publication-date">
                  Publication date
                </label>
                <input
                  id="edit-publication-date"
                  type="date"
                  value={form.publication_date}
                  onChange={(event) => setForm({ ...form, publication_date: event.target.value })}
                  disabled={saving}
                  className="w-full rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
                />
              </div>
            </div>

            <div>
              <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="edit-audience">
                Intended audience description
              </label>
              <input
                id="edit-audience"
                type="text"
                value={form.intended_audience}
                onChange={(event) => setForm({ ...form, intended_audience: event.target.value })}
                disabled={saving}
                placeholder="e.g. Youth leaders, new believers"
                className="w-full rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
              />
              <p className="mt-1 text-xs text-slate-500">
                This is descriptive only. The distribution audience is chosen when you submit.
              </p>
            </div>

            <div>
              <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="edit-tags">
                Tags
              </label>
              <input
                id="edit-tags"
                type="text"
                value={form.tags}
                onChange={(event) => setForm({ ...form, tags: event.target.value })}
                disabled={saving}
                placeholder="Comma-separated tags"
                className="w-full rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
              />
            </div>

            <div className="rounded-xl border border-dashed border-surface-border p-4">
              <div className="mb-3 flex items-center gap-2">
                <RefreshCw className="h-4 w-4 text-slate-400" />
                <p className="text-sm font-medium text-slate-300">Replace file (optional)</p>
              </div>
              <input
                id="edit-replacement-file"
                type="file"
                onChange={(event) => setReplacementFile(event.target.files?.[0] ?? null)}
                disabled={saving}
                className="hidden"
              />
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                <label
                  htmlFor="edit-replacement-file"
                  className="inline-flex cursor-pointer items-center justify-center gap-2 rounded-lg bg-surface-3 px-4 py-2 text-sm text-slate-300 transition-colors hover:bg-slate-700/40"
                >
                  <Upload className="h-4 w-4" />
                  {replacementFile ? replacementFile.name : 'Choose replacement file'}
                </label>
                {replacementFile && (
                  <button
                    type="button"
                    onClick={() => setReplacementFile(null)}
                    className="text-xs text-slate-400 hover:text-red-300"
                  >
                    Clear
                  </button>
                )}
              </div>
              {replacementFile && (
                <div className="mt-3">
                  <label className="mb-1 block text-xs font-medium text-slate-400" htmlFor="edit-change-reason">
                    Reason for replacing the file <span className="text-red-400">*</span>
                  </label>
                  <input
                    id="edit-change-reason"
                    type="text"
                    value={changeReason}
                    onChange={(event) => setChangeReason(event.target.value)}
                    disabled={saving}
                    maxLength={500}
                    className="w-full rounded-lg border border-surface-border bg-surface-3 px-3 py-2 text-sm text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
                  />
                </div>
              )}
            </div>

            {error && (
              <div className="flex items-start gap-2 rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-300">
                <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                <span>{error}</span>
              </div>
            )}

            <div className="flex flex-col-reverse gap-3 pt-2 sm:flex-row sm:justify-end">
              <button
                type="button"
                onClick={onClose}
                disabled={saving}
                className="rounded-lg bg-surface-3 px-4 py-2 text-sm text-slate-300 transition-colors hover:bg-slate-700/40 disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={saving}
                className="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-600 disabled:opacity-50"
              >
                {saving ? (
                  <>
                    <Loader2 className="h-4 w-4 animate-spin" />
                    Saving…
                  </>
                ) : (
                  <>
                    <Save className="h-4 w-4" />
                    Save draft
                  </>
                )}
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
}
