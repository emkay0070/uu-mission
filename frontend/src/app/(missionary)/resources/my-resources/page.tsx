'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import {
  AlertCircle,
  BookOpen,
  Bookmark,
  CheckCircle2,
  ChevronRight,
  Clock,
  Download,
  FolderOpen,
  History,
  Hourglass,
  Loader2,
  Pencil,
  Play,
  Plus,
  RefreshCw,
  Send,
  ShieldCheck,
  Upload,
  XCircle,
} from 'lucide-react';
import {
  resourcesApi,
  resourceCategoriesApi,
  saveBlobAsFile,
  type DistributionTargets,
  type ProcessingStatus,
  type Resource,
  type ResourceCategory,
} from '@/lib/api';
import { useAuthStore } from '@/store/auth';
import { hasPermission } from '@/lib/permissions';
import { cn } from '@/components/ui';
import {
  fetchAllDistributionRequests,
  fetchAllMyDrafts,
} from '@/components/resources/distributionData';
import {
  accessForState,
  buildContributionItems,
  reviewerFeedback,
  type ContributionItem,
  type ContributionState,
} from '@/components/resources/resourceLifecycle';
import { formatAudience, formatFileSize } from '@/components/resources/audience';
import { ContributionStatusBadge } from '@/components/resources/ResourceStatusBadge';
import { AuthenticatedThumbnail } from '@/components/resources/AuthenticatedThumbnail';
import { EditResourceDialog } from '@/components/resources/EditResourceDialog';
import {
  SubmitDistributionDialog,
  type SubmitDistributionMode,
} from '@/components/resources/SubmitDistributionDialog';

type Collection = 'continue-reading' | 'saved' | 'downloaded' | 'contributions' | 'history';

type LibraryItem = Record<string, any>;

interface SubmitTarget {
  item: ContributionItem;
  mode: SubmitDistributionMode;
}

const CONTRIBUTION_FILTERS: Array<{ value: 'all' | ContributionState; label: string }> = [
  { value: 'all', label: 'All contributions' },
  { value: 'draft', label: 'Drafts' },
  { value: 'pending_review', label: 'Pending review' },
  { value: 'changes_requested', label: 'Changes requested' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'approved', label: 'Approved & published' },
  { value: 'revoked', label: 'Distribution revoked' },
];

// Processing Status Indicator Component
function ProcessingStatusIndicator({
  processingStatus,
  mediaType,
}: {
  processingStatus: ProcessingStatus | null | undefined;
  mediaType: string;
}) {
  // Only show for PDF files
  if (mediaType !== 'pdf' || !processingStatus) {
    return null;
  }

  const { status, error_message, total_pages, extracted_pages } = processingStatus;

  // Pending - queued for processing
  if (status === 'pending') {
    return (
      <div className="flex items-center gap-1.5 rounded bg-slate-500/20 px-2 py-1 text-xs text-slate-400">
        <Hourglass className="h-3 w-3" />
        <span>Queued for processing</span>
      </div>
    );
  }

  // Processing - in progress
  if (status === 'processing') {
    const progress =
      total_pages && extracted_pages ? Math.round((extracted_pages / total_pages) * 100) : null;

    return (
      <div className="flex items-center gap-1.5 rounded bg-brand-500/20 px-2 py-1 text-xs text-brand-400">
        <Loader2 className="h-3 w-3 animate-spin" />
        <span>Processing</span>
        {progress !== null && <span className="opacity-70">({progress}%)</span>}
      </div>
    );
  }

  // Completed - ready for AI
  if (status === 'completed') {
    return (
      <div className="flex items-center gap-1.5 rounded bg-emerald-500/20 px-2 py-1 text-xs text-emerald-400">
        <CheckCircle2 className="h-3 w-3" />
        <span>Ready for AI</span>
      </div>
    );
  }

  // Failed
  if (status === 'failed') {
    return (
      <div className="flex flex-col gap-1">
        <div className="flex items-center gap-1.5 rounded bg-red-500/20 px-2 py-1 text-xs text-red-400">
          <AlertCircle className="h-3 w-3" />
          <span>Processing failed</span>
        </div>
        {error_message && <span className="px-2 text-xs text-red-400/70">{error_message}</span>}
      </div>
    );
  }

  return null;
}

function formatDate(value: string | null | undefined): string {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? '—'
    : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

export default function MyResourcesPage() {
  const { user, initialized } = useAuthStore();
  const userId = user?.id;

  const canContribute = hasPermission(user, 'resources.create');
  const canDistribute = hasPermission(user, 'resources.distribute');
  const canReview = hasPermission(user, 'resources.approve_distribute');

  const [activeCollection, setActiveCollection] = useState<Collection>('continue-reading');
  const [libraryItems, setLibraryItems] = useState<LibraryItem[]>([]);
  const [contributions, setContributions] = useState<ContributionItem[]>([]);
  const [targets, setTargets] = useState<DistributionTargets | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const [categories, setCategories] = useState<ResourceCategory[]>([]);
  const [showUploadModal, setShowUploadModal] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);

  const [editResourceId, setEditResourceId] = useState<number | null>(null);
  const [submitTarget, setSubmitTarget] = useState<SubmitTarget | null>(null);

  const [contributionSearch, setContributionSearch] = useState('');
  const [contributionFilter, setContributionFilter] = useState<'all' | ContributionState>('all');

  useEffect(() => {
    if (!initialized || !userId) return;
    resourceCategoriesApi
      .list()
      .then((response) => setCategories(response))
      .catch((categoryError) => {
        console.error('Failed to load categories:', categoryError);
      });
  }, [initialized, userId]);

  const loadCollection = useCallback(
    async (collection: Collection) => {
      setLoading(true);
      setError(null);

      try {
        switch (collection) {
          case 'continue-reading': {
            const continueData = (await resourcesApi.progress.history({
              recent: true,
              per_page: 20,
            })) as any;
            setLibraryItems(continueData?.data ?? []);
            break;
          }
          case 'saved': {
            const savedData = (await resourcesApi.saved.list({ per_page: 20 })) as any;
            setLibraryItems(savedData?.data ?? []);
            break;
          }
          case 'downloaded': {
            const downloadData = (await resourcesApi.downloads.list({ per_page: 20 })) as any;
            setLibraryItems(downloadData?.data ?? []);
            break;
          }
          case 'history': {
            const historyData = (await resourcesApi.progress.history({ per_page: 20 })) as any;
            setLibraryItems(historyData?.data ?? []);
            break;
          }
          case 'contributions': {
            if (!userId) return;

            const [drafts, requests, targetData] = await Promise.all([
              fetchAllMyDrafts(),
              fetchAllDistributionRequests(),
              resourcesApi.distributionTargets().catch(() => null),
            ]);

            setContributions(buildContributionItems(drafts, requests, userId));
            setTargets(targetData);
            break;
          }
        }
      } catch (loadError: any) {
        setError(loadError?.message || 'This collection could not be loaded. Please try again.');
      } finally {
        setLoading(false);
      }
    },
    [userId]
  );

  useEffect(() => {
    if (!initialized || !userId) return;
    loadCollection(activeCollection);
  }, [initialized, userId, activeCollection, loadCollection]);

  const handleUpload = async (formData: FormData) => {
    setUploading(true);
    setUploadError(null);

    try {
      await resourcesApi.create(formData);
      setShowUploadModal(false);
      setSuccessMessage('Draft saved privately. Submit it for distribution when you are ready.');
      setContributionFilter('all');

      if (activeCollection === 'contributions') {
        await loadCollection('contributions');
      } else {
        setActiveCollection('contributions');
      }
    } catch (uploadFailure: any) {
      setUploadError(uploadFailure?.message || 'The draft could not be created.');
    } finally {
      setUploading(false);
    }
  };

  const handleSubmitted = async (message: string) => {
    setSubmitTarget(null);
    setSuccessMessage(message);
    setContributionFilter('all');
    await loadCollection('contributions');
  };

  const collections = [
    {
      id: 'continue-reading' as Collection,
      name: 'Continue Reading',
      icon: Play,
      description: 'Pick up where you left off',
    },
    {
      id: 'saved' as Collection,
      name: 'Saved',
      icon: Bookmark,
      description: 'Resources in your library',
    },
    {
      id: 'downloaded' as Collection,
      name: 'Downloaded',
      icon: Download,
      description: 'Available offline',
    },
    {
      id: 'contributions' as Collection,
      name: 'My Contributions',
      icon: FolderOpen,
      description: 'Drafts and submissions',
    },
    {
      id: 'history' as Collection,
      name: 'Reading History',
      icon: History,
      description: "All resources you've read",
    },
  ];

  const contributionCounts = useMemo(() => {
    return contributions.reduce<Record<string, number>>((counts, item) => {
      counts[item.state] = (counts[item.state] ?? 0) + 1;
      return counts;
    }, {});
  }, [contributions]);

  const filteredContributions = useMemo(() => {
    const needle = contributionSearch.trim().toLowerCase();

    return contributions.filter((item) => {
      if (contributionFilter !== 'all' && item.state !== contributionFilter) return false;
      if (!needle) return true;

      return [item.resource.title, item.resource.description, item.request?.version?.original_filename]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
        .includes(needle);
    });
  }, [contributions, contributionFilter, contributionSearch]);

  const getProgressPercentage = (item: LibraryItem) => {
    if (activeCollection === 'continue-reading' || activeCollection === 'history') {
      return item.completion_percentage || 0;
    }
    return null;
  };

  const getLastRead = (item: LibraryItem) => {
    if (activeCollection === 'continue-reading' || activeCollection === 'history') {
      return item.last_read_at ? new Date(item.last_read_at).toLocaleDateString() : null;
    }
    if (activeCollection === 'saved') {
      return item.saved_at ? new Date(item.saved_at).toLocaleDateString() : null;
    }
    if (activeCollection === 'downloaded') {
      return item.downloaded_at ? new Date(item.downloaded_at).toLocaleDateString() : null;
    }
    return null;
  };

  const getResource = (item: LibraryItem): Resource | undefined => {
    if (activeCollection === 'continue-reading' || activeCollection === 'history') {
      return item.resource;
    }
    if (activeCollection === 'saved' || activeCollection === 'downloaded') {
      return item.resource;
    }
    return item as Resource;
  };

  const isOutdated = (item: LibraryItem) => {
    if (activeCollection === 'downloaded') {
      return item.is_outdated;
    }
    return false;
  };

  const isContributions = activeCollection === 'contributions';

  return (
    <div className="min-h-screen bg-surface p-4 sm:p-6">
      <div className="mx-auto max-w-7xl">
        <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="mb-2 text-2xl font-bold text-slate-100 sm:text-3xl">My Resources</h1>
            <p className="text-slate-400">Your personal missionary library and contributions</p>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {canReview && (
              <Link
                href="/resources/reviews"
                className="inline-flex items-center gap-2 rounded-lg border border-surface-border bg-surface-2 px-4 py-2 text-sm text-slate-300 transition-colors hover:border-brand-500 hover:text-brand-300"
              >
                <ShieldCheck className="h-4 w-4" />
                Review distribution
              </Link>
            )}
            {canContribute && (
              <button
                type="button"
                onClick={() => setShowUploadModal(true)}
                className="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2 text-white transition-colors hover:bg-brand-600"
              >
                <Plus className="h-4 w-4" />
                Upload Resource
              </button>
            )}
          </div>
        </div>

        {successMessage && (
          <div className="mb-5 flex items-start justify-between gap-3 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-300">
            <div className="flex items-start gap-2">
              <CheckCircle2 className="mt-0.5 h-4 w-4 flex-shrink-0" />
              <span>{successMessage}</span>
            </div>
            <button
              type="button"
              onClick={() => setSuccessMessage(null)}
              className="text-xs font-medium underline"
            >
              Dismiss
            </button>
          </div>
        )}

        {error && (
          <div className="mb-5 flex items-start justify-between gap-3 rounded-xl border border-red-500/30 bg-red-500/10 p-4 text-sm text-red-300">
            <div className="flex items-start gap-2">
              <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
              <span>{error}</span>
            </div>
            <button
              type="button"
              onClick={() => loadCollection(activeCollection)}
              className="inline-flex items-center gap-1 text-xs font-medium underline"
            >
              <RefreshCw className="h-3 w-3" />
              Try again
            </button>
          </div>
        )}

        {/* Collections Grid */}
        <div className="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
          {collections.map((collection) => {
            const Icon = collection.icon;
            const isActive = activeCollection === collection.id;
            const count =
              collection.id === 'contributions' ? contributions.length : undefined;

            return (
              <button
                key={collection.id}
                type="button"
                onClick={() => setActiveCollection(collection.id)}
                className={cn(
                  'rounded-xl border p-4 text-left transition-all',
                  isActive
                    ? 'border-brand-500 bg-brand-500/20 text-brand-400'
                    : 'border-surface-border bg-surface-2 text-slate-400 hover:border-brand-500/50 hover:text-slate-300'
                )}
              >
                <div className="mb-2 flex items-start justify-between gap-2">
                  <Icon className={cn('h-6 w-6', isActive ? 'text-brand-400' : '')} />
                  {count !== undefined && count > 0 && (
                    <span className="rounded-full bg-brand-500/20 px-2 py-0.5 text-[11px] font-semibold text-brand-300">
                      {count}
                    </span>
                  )}
                </div>
                <div className="mb-1 font-semibold">{collection.name}</div>
                <div className="text-xs opacity-70">{collection.description}</div>
              </button>
            );
          })}
        </div>

        {/* Collection Header */}
        <div className="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <h2 className="mb-2 text-2xl font-bold text-slate-100">
              {collections.find((collection) => collection.id === activeCollection)?.name}
            </h2>
            <p className="text-slate-400">
              {collections.find((collection) => collection.id === activeCollection)?.description}
            </p>
          </div>

          {isContributions && (
            <div className="flex flex-col gap-2 sm:flex-row">
              <input
                type="search"
                value={contributionSearch}
                onChange={(event) => setContributionSearch(event.target.value)}
                placeholder="Search your contributions…"
                className="w-full rounded-lg border border-surface-border bg-surface-2 px-3 py-2 text-sm text-slate-100 placeholder-slate-500 focus:border-brand-500 focus:outline-none sm:w-64"
              />
              <select
                value={contributionFilter}
                onChange={(event) =>
                  setContributionFilter(event.target.value as 'all' | ContributionState)
                }
                className="rounded-lg border border-surface-border bg-surface-2 px-3 py-2 text-sm text-slate-200 focus:border-brand-500 focus:outline-none"
              >
                {CONTRIBUTION_FILTERS.map((filter) => (
                  <option key={filter.value} value={filter.value}>
                    {filter.label}
                  </option>
                ))}
              </select>
            </div>
          )}
        </div>

        {isContributions ? (
          <>
            <div className="mb-5 grid grid-cols-2 gap-2 sm:grid-cols-4">
              {[
                { key: 'draft', label: 'Drafts', tone: 'border-slate-500/25 bg-slate-500/5 text-slate-300' },
                {
                  key: 'pending_review',
                  label: 'Pending review',
                  tone: 'border-amber-500/25 bg-amber-500/5 text-amber-300',
                },
                {
                  key: 'changes_requested',
                  label: 'Changes requested',
                  tone: 'border-orange-500/25 bg-orange-500/5 text-orange-300',
                },
                {
                  key: 'approved',
                  label: 'Approved',
                  tone: 'border-emerald-500/25 bg-emerald-500/5 text-emerald-300',
                },
              ].map((summary) => (
                <div key={summary.key} className={cn('rounded-xl border p-3', summary.tone)}>
                  <p className="text-[11px] font-semibold uppercase tracking-wider opacity-80">
                    {summary.label}
                  </p>
                  <p className="mt-1 text-xl font-bold">{contributionCounts[summary.key] ?? 0}</p>
                </div>
              ))}
            </div>

            {loading ? (
              <div className="space-y-3">
                {[0, 1, 2].map((index) => (
                  <div
                    key={index}
                    className="h-40 animate-pulse rounded-xl border border-surface-border bg-surface-2"
                  />
                ))}
              </div>
            ) : contributions.length === 0 ? (
              <div className="rounded-2xl border border-surface-border bg-surface-2 p-8 text-center">
                <FolderOpen className="mx-auto mb-4 h-12 w-12 text-slate-500" />
                <p className="mb-2 font-semibold text-slate-300">No contributions yet</p>
                <p className="mx-auto max-w-md text-sm text-slate-500">
                  {canContribute
                    ? 'Upload a resource to create a private draft. You choose an audience when you submit it for review.'
                    : 'Your account does not currently hold the resources.create capability, so you cannot create resource drafts.'}
                </p>
                {canContribute && (
                  <button
                    type="button"
                    onClick={() => setShowUploadModal(true)}
                    className="mt-5 inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600"
                  >
                    <Plus className="h-4 w-4" />
                    Create a draft
                  </button>
                )}
              </div>
            ) : filteredContributions.length === 0 ? (
              <div className="rounded-2xl border border-surface-border bg-surface-2 p-8 text-center">
                <BookOpen className="mx-auto mb-4 h-12 w-12 text-slate-500" />
                <p className="mb-2 font-semibold text-slate-300">No matching contributions</p>
                <p className="text-sm text-slate-500">
                  Try clearing the search or choosing a different status.
                </p>
              </div>
            ) : (
              <div className="space-y-3">
                {filteredContributions.map((item) => (
                  <ContributionCard
                    key={`${item.resource.id}-${item.state}`}
                    item={item}
                    targets={targets}
                    canContribute={canContribute}
                    canDistribute={canDistribute}
                    onEdit={() => setEditResourceId(item.resource.id)}
                    onSubmit={(mode) => setSubmitTarget({ item, mode })}
                  />
                ))}
              </div>
            )}
          </>
        ) : loading ? (
          <div className="space-y-3">
            {[0, 1, 2].map((index) => (
              <div
                key={index}
                className="h-32 animate-pulse rounded-xl border border-surface-border bg-surface-2"
              />
            ))}
          </div>
        ) : libraryItems.length === 0 ? (
          <div className="rounded-2xl border border-surface-border bg-surface-2 p-8 text-center">
            <BookOpen className="mx-auto mb-4 h-12 w-12 text-slate-500" />
            <p className="mb-2 text-slate-400">No resources in this collection yet</p>
            <p className="text-sm text-slate-500">
              {activeCollection === 'saved' && 'Save resources from the Store to build your library'}
              {activeCollection === 'downloaded' && 'Download resources for offline reading'}
              {activeCollection === 'continue-reading' &&
                'Start reading resources to track your progress'}
              {activeCollection === 'history' && 'Your reading history will appear here'}
            </p>
          </div>
        ) : (
          <div className="space-y-4">
            {libraryItems.map((item) => {
              const resource = getResource(item);
              if (!resource) return null;

              const progress = getProgressPercentage(item);
              const lastRead = getLastRead(item);
              const outdated = isOutdated(item);

              return (
                <div
                  key={resource.id}
                  className="rounded-xl border border-surface-border bg-surface-2 p-4 transition-colors hover:border-brand-500/40"
                >
                  <div className="flex items-start gap-4">
                    {/* Thumbnail */}
                    <div className="flex h-32 w-24 flex-shrink-0 items-center justify-center overflow-hidden rounded-lg bg-surface-3">
                      {resource.thumbnail_storage_path ? (
                        <AuthenticatedThumbnail
                          resourceId={resource.id}
                          alt={resource.title}
                          className="h-full w-full object-cover"
                        />
                      ) : (
                        <BookOpen className="h-8 w-8 text-slate-600" />
                      )}
                    </div>

                    {/* Content */}
                    <div className="min-w-0 flex-1">
                      <div className="mb-2 flex items-start justify-between gap-3">
                        <h3 className="line-clamp-1 font-semibold text-slate-100">
                          {resource.title}
                        </h3>
                        {outdated && (
                          <span className="rounded bg-amber-500/20 px-2 py-1 text-xs text-amber-400">
                            Update Available
                          </span>
                        )}
                      </div>

                      {resource.description && (
                        <p className="mb-2 line-clamp-2 text-sm text-slate-400">
                          {resource.description}
                        </p>
                      )}

                      <div className="mb-2 flex flex-wrap gap-2">
                        {resource.category && (
                          <span className="rounded bg-surface-3 px-2 py-1 text-xs text-slate-400">
                            {resource.category.label}
                          </span>
                        )}
                        {resource.language && (
                          <span className="rounded bg-surface-3 px-2 py-1 text-xs text-slate-400">
                            {resource.language.toUpperCase()}
                          </span>
                        )}
                      </div>

                      {/* Progress Bar for Continue Reading */}
                      {progress !== null && (
                        <div className="mb-2">
                          <div className="mb-1 flex items-center justify-between text-xs text-slate-400">
                            <span>Progress</span>
                            <span>{Math.round(progress)}%</span>
                          </div>
                          <div className="h-1.5 w-full overflow-hidden rounded-full bg-surface-3">
                            <div
                              className="h-full rounded-full bg-brand-500 transition-all"
                              style={{ width: `${progress}%` }}
                            />
                          </div>
                        </div>
                      )}

                      {/* Last Read / Saved / Downloaded */}
                      {lastRead && (
                        <div className="flex items-center gap-1 text-xs text-slate-500">
                          <Clock className="h-3 w-3" />
                          {activeCollection === 'continue-reading' || activeCollection === 'history'
                            ? `Last read ${lastRead}`
                            : activeCollection === 'saved'
                            ? `Saved ${lastRead}`
                            : `Downloaded ${lastRead}`}
                        </div>
                      )}
                    </div>

                    {/* Actions */}
                    <div className="flex flex-col gap-2">
                      <Link
                        href={`/resources/reader/${resource.id}`}
                        className="flex items-center justify-center rounded-lg bg-brand-500 p-2 text-white transition-colors hover:bg-brand-600"
                        title="Open in reader"
                      >
                        <BookOpen className="h-4 w-4" />
                      </Link>
                      <button
                        type="button"
                        onClick={async () => {
                          try {
                            const blob = await resourcesApi.downloadBlob(resource.id);
                            saveBlobAsFile(
                              blob,
                              resource.currentVersion?.original_filename ||
                                resource.title ||
                                `resource-${resource.id}`
                            );
                          } catch (error) {
                            console.error('Failed to download resource:', error);
                          }
                        }}
                        className="flex items-center justify-center rounded-lg bg-surface-3 p-2 text-slate-300 transition-colors hover:bg-slate-600/30"
                        title="Download"
                      >
                        <Download className="h-4 w-4" />
                      </button>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        )}

        {/* Upload Modal */}
        {showUploadModal && canContribute && (
          <div className="fixed inset-0 z-[9999] flex items-center justify-center bg-black/65 p-3 backdrop-blur-sm sm:p-6">
            <div className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-surface-border bg-surface-2 p-5 shadow-2xl sm:p-6">
              <div className="mb-4 flex items-start justify-between gap-4">
                <div>
                  <h2 className="text-2xl font-bold text-slate-100">Upload Resource</h2>
                  <p className="mt-1 text-sm text-slate-400">
                    This creates a private draft. No audience is selected or published until you
                    submit it for distribution and a reviewer approves it.
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => {
                    setShowUploadModal(false);
                    setUploadError(null);
                  }}
                  className="rounded-lg p-2 transition-colors hover:bg-surface-3"
                  aria-label="Close upload dialog"
                >
                  <XCircle className="h-5 w-5 text-slate-400" />
                </button>
              </div>

              <UploadForm
                categories={categories}
                uploading={uploading}
                uploadError={uploadError}
                onSubmit={handleUpload}
                onCancel={() => {
                  setShowUploadModal(false);
                  setUploadError(null);
                }}
              />
            </div>
          </div>
        )}

        <EditResourceDialog
          open={editResourceId !== null}
          resourceId={editResourceId}
          categories={categories}
          onClose={() => setEditResourceId(null)}
          onSaved={() => {
            setSuccessMessage('Draft updated.');
            loadCollection('contributions');
          }}
        />

        {submitTarget && (
          <SubmitDistributionDialog
            open
            resource={submitTarget.item.resource}
            request={submitTarget.item.request}
            mode={submitTarget.mode}
            onClose={() => setSubmitTarget(null)}
            onSubmitted={() =>
              handleSubmitted(
                submitTarget.mode === 'resubmit'
                  ? 'Resubmitted for review. You will see the reviewer feedback here.'
                  : 'Submitted for distribution review. The resource has not been published yet.'
              )
            }
          />
        )}
      </div>
    </div>
  );
}

function ContributionCard({
  item,
  targets,
  canContribute,
  canDistribute,
  onEdit,
  onSubmit,
}: {
  item: ContributionItem;
  targets: DistributionTargets | null;
  canContribute: boolean;
  canDistribute: boolean;
  onEdit: () => void;
  onSubmit: (mode: SubmitDistributionMode) => void;
}) {
  const { resource, request, state } = item;
  const access = accessForState(state);
  const version = resource.currentVersion ?? request?.version;
  const feedback = reviewerFeedback(item);

  const canEdit = access.canEdit && canContribute;
  const canResubmit = access.canResubmit && canDistribute;
  const canSubmit = access.canSubmit && canDistribute && Boolean(resource.current_version_id);
  // The backend lets a creator read their own resource while it is a draft.
  // Pending submissions are intentionally not readable by the contributor.
  const canReadDraft = resource.status === 'draft' && Boolean(resource.current_version_id);

  return (
    <article className="rounded-xl border border-surface-border bg-surface-2 p-4 transition-colors hover:border-brand-500/40">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0 flex-1">
          <div className="mb-2 flex flex-wrap items-center gap-2">
            <ContributionStatusBadge state={state} />
            {request && (
              <span className="text-xs text-slate-500">Request #{request.id}</span>
            )}
            {request && (
              <span className="text-xs text-slate-500">
                Updated {formatDate(request.submitted_at)}
              </span>
            )}
          </div>

          <h3 className="text-lg font-semibold text-slate-100">{resource.title}</h3>
          {resource.description && (
            <p className="mt-1 line-clamp-2 text-sm text-slate-400">{resource.description}</p>
          )}

          <div className="mt-2 flex flex-wrap gap-2">
            {resource.category && (
              <span className="rounded bg-surface-3 px-2 py-1 text-xs text-slate-400">
                {resource.category.label}
              </span>
            )}
            {resource.language && (
              <span className="rounded bg-surface-3 px-2 py-1 text-xs text-slate-400">
                {resource.language.toUpperCase()}
              </span>
            )}
            {resource.intended_audience && (
              <span className="rounded bg-surface-3 px-2 py-1 text-xs text-slate-400">
                Intended: {resource.intended_audience}
              </span>
            )}
          </div>

          {version && (
            <div className="mt-3 flex flex-wrap items-center gap-3 rounded-lg bg-surface-3/60 px-3 py-2">
              <div className="flex min-w-0 items-center gap-2">
                <BookOpen className="h-4 w-4 flex-shrink-0 text-slate-500" />
                <div className="min-w-0">
                  <p className="truncate text-sm text-slate-300">{version.original_filename}</p>
                  <p className="text-xs text-slate-500">
                    {formatFileSize(version.file_size)}
                    {version.media_type ? ` · ${version.media_type.toUpperCase()}` : ''}
                  </p>
                </div>
              </div>
              {version.processing_status && (
                <ProcessingStatusIndicator
                  processingStatus={version.processing_status}
                  mediaType={version.media_type}
                />
              )}
            </div>
          )}

          {request && (
            <div className="mt-3 rounded-lg border border-surface-border bg-surface-3/40 p-3">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                {state === 'approved' ? 'Published audience' : 'Proposed audience'}
              </p>
              <p className="mt-1 text-sm font-medium text-slate-200">
                {formatAudience(request, targets)}
              </p>
              {state === 'pending_review' && (
                <p className="mt-1 text-xs text-slate-500">
                  A reviewer with approval authority for this audience will decide the request.
                </p>
              )}
              {state === 'changes_requested' && (
                <p className="mt-1 text-xs text-slate-500">
                  Edit this draft, then resubmit the existing request for review.
                </p>
              )}
              {state === 'approved' && (
                <p className="mt-1 text-xs text-slate-500">
                  Approved {formatDate(request.decided_at)}. Your contributor ownership does not
                  automatically grant reader access; the resource appears in discovery only for
                  members of the released audience.
                </p>
              )}
              {state === 'revoked' && (
                <p className="mt-1 text-xs text-slate-500">
                  This audience release was withdrawn. You may propose a new distribution request.
                </p>
              )}
            </div>
          )}

          {feedback && (
            <div
              className={cn(
                'mt-3 rounded-lg border p-3',
                state === 'rejected'
                  ? 'border-red-500/30 bg-red-500/10'
                  : state === 'changes_requested'
                  ? 'border-orange-500/30 bg-orange-500/10'
                  : 'border-surface-border bg-surface-3/60'
              )}
            >
              <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                Reviewer feedback
              </p>
              <p className="mt-1 text-sm text-slate-300">{feedback}</p>
            </div>
          )}

          {!canDistribute &&
            (state === 'draft' || state === 'changes_requested' || state === 'rejected') && (
              <p className="mt-3 rounded-lg border border-amber-500/25 bg-amber-500/10 p-3 text-xs text-amber-200">
                Your account can create and edit drafts but does not hold{' '}
                <code className="rounded bg-black/20 px-1 py-0.5">resources.distribute</code>.
                Ask an administrator to grant it before submitting this resource for review.
              </p>
            )}
        </div>

        <div className="flex flex-wrap gap-2 lg:w-52 lg:flex-col">
          {canReadDraft && (
            <Link
              href={`/resources/reader/${resource.id}`}
              className="inline-flex items-center justify-center gap-2 rounded-lg bg-surface-3 px-3 py-2 text-sm text-slate-300 transition-colors hover:bg-slate-600/30"
            >
              <BookOpen className="h-4 w-4" />
              Read draft
            </Link>
          )}

          {canEdit && (
            <button
              type="button"
              onClick={onEdit}
              className="inline-flex items-center justify-center gap-2 rounded-lg bg-surface-3 px-3 py-2 text-sm text-slate-300 transition-colors hover:bg-slate-600/30"
            >
              <Pencil className="h-4 w-4" />
              Edit draft
            </button>
          )}

          {canResubmit && (
            <button
              type="button"
              onClick={() => onSubmit('resubmit')}
              className="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-600"
            >
              <Send className="h-4 w-4" />
              Resubmit for review
            </button>
          )}

          {canSubmit && (
            <button
              type="button"
              onClick={() => onSubmit('submit')}
              className="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-600"
            >
              <Send className="h-4 w-4" />
              {state === 'draft' ? 'Submit for distribution' : 'Submit with a new audience'}
            </button>
          )}

          {state === 'pending_review' && (
            <p className="rounded-lg border border-surface-border bg-surface-3/60 p-3 text-xs text-slate-400">
              Awaiting reviewer decision. You cannot edit, resubmit, or open the reader while this
              request is pending.
            </p>
          )}

          {state === 'approved' && (
            <Link
              href="/resources/store"
              className="inline-flex items-center justify-center gap-2 rounded-lg bg-surface-3 px-3 py-2 text-sm text-slate-300 transition-colors hover:bg-slate-600/30"
            >
              <BookOpen className="h-4 w-4" />
              Open discovery
              <ChevronRight className="h-3.5 w-3.5" />
            </Link>
          )}

          {state === 'superseded' && (
            <p className="rounded-lg border border-surface-border bg-surface-3/60 p-3 text-xs text-slate-400">
              A newer distribution request replaced this one. Check the latest contribution state.
            </p>
          )}
        </div>
      </div>
    </article>
  );
}

// Upload Form Component
function UploadForm({
  categories,
  uploading,
  uploadError,
  onSubmit,
  onCancel,
}: {
  categories: ResourceCategory[];
  uploading: boolean;
  uploadError: string | null;
  onSubmit: (formData: FormData) => void;
  onCancel: () => void;
}) {
  const [formData, setFormData] = useState({
    title: '',
    description: '',
    category_id: '',
    language: 'en',
    intended_audience: '',
    publisher: '',
    publication_date: '',
    tags: '',
  });
  const [file, setFile] = useState<File | null>(null);
  const [thumbnail, setThumbnail] = useState<File | null>(null);
  const [validationError, setValidationError] = useState<string | null>(null);

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();
    setValidationError(null);

    if (!file) {
      setValidationError('Please select a file to upload.');
      return;
    }

    // Client-side validation matching backend
    const maxFileSize = 512 * 1024 * 1024; // 500MB
    if (file.size > maxFileSize) {
      setValidationError('File size exceeds the 500MB limit.');
      return;
    }

    if (thumbnail) {
      const maxThumbnailSize = 5 * 1024 * 1024; // 5MB
      if (thumbnail.size > maxThumbnailSize) {
        setValidationError('Thumbnail size exceeds the 5MB limit.');
        return;
      }
    }

    if (formData.title.length > 255) {
      setValidationError('Title must be 255 characters or less.');
      return;
    }

    if (formData.intended_audience.length > 100) {
      setValidationError('Intended audience must be 100 characters or less.');
      return;
    }

    if (formData.publisher.length > 255) {
      setValidationError('Publisher must be 255 characters or less.');
      return;
    }

    const uploadFormData = new FormData();
    uploadFormData.append('file', file);
    if (thumbnail) {
      uploadFormData.append('thumbnail', thumbnail);
    }
    uploadFormData.append('title', formData.title);
    if (formData.description) {
      uploadFormData.append('description', formData.description);
    }
    if (formData.category_id) {
      uploadFormData.append('category_id', formData.category_id);
    }
    if (formData.language) {
      uploadFormData.append('language', formData.language);
    }
    if (formData.intended_audience) {
      uploadFormData.append('intended_audience', formData.intended_audience);
    }
    if (formData.publisher) {
      uploadFormData.append('publisher', formData.publisher);
    }
    if (formData.publication_date) {
      uploadFormData.append('publication_date', formData.publication_date);
    }
    if (formData.tags) {
      const tagsArray = formData.tags
        .split(',')
        .map((tag) => tag.trim())
        .filter((tag) => tag);
      tagsArray.forEach((tag) => {
        uploadFormData.append('tags[]', tag);
      });
    }

    onSubmit(uploadFormData);
  };

  const errorMessage = validationError || uploadError;

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      {/* File Upload */}
      <div>
        <label className="mb-2 block text-sm font-medium text-slate-300">
          Resource File <span className="text-red-400">*</span>
        </label>
        <div className="rounded-lg border-2 border-dashed border-surface-border p-6 text-center transition-colors hover:border-brand-500/50">
          <input
            type="file"
            onChange={(event) => setFile(event.target.files?.[0] || null)}
            disabled={uploading}
            className="hidden"
            id="file-upload"
          />
          <label
            htmlFor="file-upload"
            className="flex cursor-pointer flex-col items-center gap-2"
          >
            <Upload className="h-8 w-8 text-slate-400" />
            <span className="text-slate-300">
              {file ? file.name : 'Click to select a file'}
            </span>
            <span className="text-xs text-slate-500">
              PDF, DOC, DOCX, PPT, PPTX · PDF up to 50MB, office files up to 512MB
            </span>
          </label>
        </div>
      </div>

      {/* Thumbnail Upload */}
      <div>
        <label className="mb-2 block text-sm font-medium text-slate-300">
          Thumbnail (optional)
        </label>
        <div className="rounded-lg border-2 border-dashed border-surface-border p-4 text-center transition-colors hover:border-brand-500/50">
          <input
            type="file"
            onChange={(event) => setThumbnail(event.target.files?.[0] || null)}
            accept="image/*"
            disabled={uploading}
            className="hidden"
            id="thumbnail-upload"
          />
          <label
            htmlFor="thumbnail-upload"
            className="flex cursor-pointer flex-col items-center gap-2"
          >
            <span className="text-slate-300">
              {thumbnail ? thumbnail.name : 'Click to select a thumbnail'}
            </span>
            <span className="text-xs text-slate-500">JPG, PNG, WEBP (max 5MB)</span>
          </label>
        </div>
      </div>

      {/* Title */}
      <div>
        <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="upload-title">
          Title <span className="text-red-400">*</span>
        </label>
        <input
          id="upload-title"
          type="text"
          value={formData.title}
          onChange={(event) => setFormData({ ...formData, title: event.target.value })}
          disabled={uploading}
          required
          className="w-full rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
          placeholder="Resource title"
        />
      </div>

      {/* Description */}
      <div>
        <label
          className="mb-2 block text-sm font-medium text-slate-300"
          htmlFor="upload-description"
        >
          Description
        </label>
        <textarea
          id="upload-description"
          value={formData.description}
          onChange={(event) => setFormData({ ...formData, description: event.target.value })}
          disabled={uploading}
          rows={3}
          className="w-full resize-none rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
          placeholder="Brief description of the resource"
        />
      </div>

      {/* Category */}
      <div>
        <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="upload-category">
          Category
        </label>
        <select
          id="upload-category"
          value={formData.category_id}
          onChange={(event) => setFormData({ ...formData, category_id: event.target.value })}
          disabled={uploading}
          className="w-full rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
        >
          <option value="">Select a category</option>
          {categories.map((category) => (
            <option key={category.id} value={category.id}>
              {category.label}
            </option>
          ))}
        </select>
      </div>

      {/* Language */}
      <div>
        <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="upload-language">
          Language
        </label>
        <select
          id="upload-language"
          value={formData.language}
          onChange={(event) => setFormData({ ...formData, language: event.target.value })}
          disabled={uploading}
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

      {/* Intended Audience */}
      <div>
        <label
          className="mb-2 block text-sm font-medium text-slate-300"
          htmlFor="upload-intended-audience"
        >
          Intended Audience
        </label>
        <input
          id="upload-intended-audience"
          type="text"
          value={formData.intended_audience}
          onChange={(event) =>
            setFormData({ ...formData, intended_audience: event.target.value })
          }
          disabled={uploading}
          className="w-full rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
          placeholder="e.g., Missionaries, Youth, Children"
        />
        <p className="mt-1 text-xs text-slate-500">
          Descriptive only. You propose the distribution audience when you submit.
        </p>
      </div>

      {/* Publisher */}
      <div>
        <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="upload-publisher">
          Publisher
        </label>
        <input
          id="upload-publisher"
          type="text"
          value={formData.publisher}
          onChange={(event) => setFormData({ ...formData, publisher: event.target.value })}
          disabled={uploading}
          className="w-full rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
          placeholder="Publisher name"
        />
      </div>

      {/* Publication Date */}
      <div>
        <label
          className="mb-2 block text-sm font-medium text-slate-300"
          htmlFor="upload-publication-date"
        >
          Publication Date
        </label>
        <input
          id="upload-publication-date"
          type="date"
          value={formData.publication_date}
          onChange={(event) =>
            setFormData({ ...formData, publication_date: event.target.value })
          }
          disabled={uploading}
          className="w-full rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
        />
      </div>

      {/* Tags */}
      <div>
        <label className="mb-2 block text-sm font-medium text-slate-300" htmlFor="upload-tags">
          Tags
        </label>
        <input
          id="upload-tags"
          type="text"
          value={formData.tags}
          onChange={(event) => setFormData({ ...formData, tags: event.target.value })}
          disabled={uploading}
          className="w-full rounded-lg border border-surface-border bg-surface-3 px-4 py-2 text-slate-100 focus:border-brand-500 focus:outline-none disabled:opacity-50"
          placeholder="Comma-separated tags (e.g., bible, evangelism, training)"
        />
      </div>

      {/* Upload Progress */}
      {uploading && (
        <div className="flex items-center gap-3 rounded-lg bg-surface-3 p-4">
          <Loader2 className="h-5 w-5 animate-spin text-brand-400" />
          <span className="text-sm text-slate-300">Saving draft…</span>
        </div>
      )}

      {/* Error Message */}
      {errorMessage && (
        <div className="flex items-start gap-3 rounded-lg border border-red-500/50 bg-red-500/20 p-4">
          <XCircle className="mt-0.5 h-5 w-5 flex-shrink-0 text-red-400" />
          <div className="flex-1">
            <p className="text-sm text-red-400">{errorMessage}</p>
          </div>
        </div>
      )}

      {/* Actions */}
      <div className="flex flex-col-reverse gap-3 pt-4 sm:flex-row">
        <button
          type="button"
          onClick={onCancel}
          disabled={uploading}
          className="flex-1 rounded-lg bg-surface-3 px-4 py-2 text-slate-300 transition-colors hover:bg-slate-600/30 disabled:opacity-50"
        >
          Cancel
        </button>
        <button
          type="submit"
          disabled={uploading || !file || !formData.title}
          className="inline-flex flex-1 items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2 text-white transition-colors hover:bg-brand-600 disabled:opacity-50"
        >
          {uploading ? (
            <>
              <Loader2 className="h-4 w-4 animate-spin" />
              Saving…
            </>
          ) : (
            <>
              <Upload className="h-4 w-4" />
              Save as Draft
            </>
          )}
        </button>
      </div>
    </form>
  );
}
