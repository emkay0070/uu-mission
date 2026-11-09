'use client';

import { useState, useEffect } from 'react';
import Link from 'next/link';
import { Search, Filter, BookOpen, Download, Bookmark, X, ChevronDown, Loader2 } from 'lucide-react';
import { resourcesApi, resourceCategoriesApi, saveBlobAsFile, Resource, ResourceCategory } from '@/lib/api';
import { offlineStorage } from '@/lib/offlineStorage';
import { AuthenticatedThumbnail } from '@/components/resources/AuthenticatedThumbnail';

export default function StorePage() {
  const [resources, setResources] = useState<Resource[]>([]);
  const [categories, setCategories] = useState<ResourceCategory[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCategory, setSelectedCategory] = useState<number | null>(null);
  const [selectedLanguage, setSelectedLanguage] = useState<string>('');
  const [selectedTag, setSelectedTag] = useState<string>('');
  const [showFilters, setShowFilters] = useState(false);
  const [savedStatus, setSavedStatus] = useState<Record<number, boolean>>({});
  const [downloading, setDownloading] = useState<Record<number, boolean>>({});
  const [downloadStatus, setDownloadStatus] = useState<Record<number, boolean>>({});

  useEffect(() => {
    loadCategories();
    offlineStorage.init();
    loadResources();
  }, []);

  useEffect(() => {
    loadResources();
  }, [searchQuery, selectedCategory, selectedLanguage, selectedTag]);

  const loadCategories = async () => {
    try {
      const response = await resourceCategoriesApi.list();
      setCategories(response);
    } catch (error) {
      console.error('Failed to load categories:', error);
    }
  };

  const loadResources = async () => {
    setLoading(true);
    try {
      const params: any = {
        search: searchQuery || undefined,
        category_id: selectedCategory || undefined,
        language: selectedLanguage || undefined,
        tag: selectedTag || undefined,
        per_page: 20,
      };
      const response = await resourcesApi.list(params);
      setResources(response.data);
      
      // Load saved and download status for all resources
      const savedPromises = response.data.map(r => 
        resourcesApi.saved.status(r.id).then((res: any) => ({ id: r.id, isSaved: res.is_saved }))
      );
      const savedResults = await Promise.all(savedPromises);
      const savedMap = Object.fromEntries(savedResults.map(r => [r.id, r.isSaved]));
      setSavedStatus(savedMap);

      const downloadPromises = response.data.map(r => 
        resourcesApi.downloads.status(r.id).then((res: any) => ({ id: r.id, isDownloaded: res.is_downloaded }))
      );
      const downloadResults = await Promise.all(downloadPromises);
      const downloadMap = Object.fromEntries(downloadResults.map(r => [r.id, r.isDownloaded]));
      setDownloadStatus(downloadMap);
    } catch (error) {
      console.error('Failed to load resources:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSave = async (resourceId: number) => {
    try {
      if (savedStatus[resourceId]) {
        await resourcesApi.saved.remove(resourceId);
        setSavedStatus(prev => ({ ...prev, [resourceId]: false }));
      } else {
        await resourcesApi.saved.save(resourceId);
        setSavedStatus(prev => ({ ...prev, [resourceId]: true }));
      }
    } catch (error) {
      console.error('Failed to save resource:', error);
    }
  };

  const handleDownload = async (resourceId: number) => {
    try {
      const resource = resources.find(r => r.id === resourceId);
      const blob = await resourcesApi.downloadBlob(resourceId);
      saveBlobAsFile(
        blob,
        resource?.currentVersion?.original_filename || resource?.title || `resource-${resourceId}`
      );

      // Record the download
      if (resource?.currentVersion) {
        await resourcesApi.downloads.record(resourceId, {
          file_size: resource.currentVersion.file_size,
        });
        setDownloadStatus(prev => ({ ...prev, [resourceId]: true }));
      }
    } catch (error) {
      console.error('Failed to download resource:', error);
    }
  };

  const clearFilters = () => {
    setSearchQuery('');
    setSelectedCategory(null);
    setSelectedLanguage('');
    setSelectedTag('');
  };

  const activeFiltersCount = [
    selectedCategory,
    selectedLanguage,
    selectedTag,
  ].filter(Boolean).length;

  return (
    <div className="min-h-screen bg-surface p-6">
      <div className="max-w-7xl mx-auto">
        <div className="mb-8 flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold text-slate-100 mb-2">Resource Store</h1>
            <p className="text-slate-400">Discover and access missionary learning materials</p>
          </div>
        </div>

        {/* Search Bar */}
        <div className="mb-6">
          <div className="relative">
            <Search className="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400" />
            <input
              type="text"
              placeholder="Search resources by title or description..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full pl-12 pr-4 py-3 bg-surface-2 border border-surface-border rounded-xl text-slate-100 placeholder-slate-500 focus:outline-none focus:border-brand-500"
            />
            <button
              onClick={() => setShowFilters(!showFilters)}
              className="absolute right-4 top-1/2 -translate-y-1/2 p-2 hover:bg-surface-3 rounded-lg transition-colors"
            >
              <Filter className="w-5 h-5 text-slate-400" />
            </button>
          </div>
        </div>

        {/* Filters Panel */}
        {showFilters && (
          <div className="mb-6 bg-surface-2 border border-surface-border rounded-xl p-4">
            <div className="flex items-center justify-between mb-4">
              <h3 className="font-semibold text-slate-100">Filters</h3>
              {activeFiltersCount > 0 && (
                <button
                  onClick={clearFilters}
                  className="text-sm text-brand-400 hover:text-brand-300 flex items-center gap-1"
                >
                  <X className="w-4 h-4" />
                  Clear all
                </button>
              )}
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              {/* Category Filter */}
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Category</label>
                <select
                  value={selectedCategory || ''}
                  onChange={(e) => setSelectedCategory(e.target.value ? Number(e.target.value) : null)}
                  className="w-full px-4 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-500"
                >
                  <option value="">All Categories</option>
                  {categories.map((cat) => (
                    <option key={cat.id} value={cat.id}>
                      {cat.label}
                    </option>
                  ))}
                </select>
              </div>

              {/* Language Filter */}
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Language</label>
                <select
                  value={selectedLanguage}
                  onChange={(e) => setSelectedLanguage(e.target.value)}
                  className="w-full px-4 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-500"
                >
                  <option value="">All Languages</option>
                  <option value="en">English</option>
                  <option value="es">Spanish</option>
                  <option value="fr">French</option>
                  <option value="pt">Portuguese</option>
                  <option value="sw">Swahili</option>
                </select>
              </div>

              {/* Tag Filter */}
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">Tag</label>
                <input
                  type="text"
                  placeholder="Filter by tag..."
                  value={selectedTag}
                  onChange={(e) => setSelectedTag(e.target.value)}
                  className="w-full px-4 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 placeholder-slate-500 focus:outline-none focus:border-brand-500"
                />
              </div>
            </div>
          </div>
        )}

        {/* Active Filters Tags */}
        {activeFiltersCount > 0 && (
          <div className="mb-4 flex flex-wrap gap-2">
            {selectedCategory && (
              <span className="inline-flex items-center gap-1 px-3 py-1 bg-brand-500/20 text-brand-400 rounded-full text-sm">
                {categories.find(c => c.id === selectedCategory)?.label}
                <button onClick={() => setSelectedCategory(null)} className="hover:text-brand-300">
                  <X className="w-3 h-3" />
                </button>
              </span>
            )}
            {selectedLanguage && (
              <span className="inline-flex items-center gap-1 px-3 py-1 bg-brand-500/20 text-brand-400 rounded-full text-sm">
                {selectedLanguage}
                <button onClick={() => setSelectedLanguage('')} className="hover:text-brand-300">
                  <X className="w-3 h-3" />
                </button>
              </span>
            )}
            {selectedTag && (
              <span className="inline-flex items-center gap-1 px-3 py-1 bg-brand-500/20 text-brand-400 rounded-full text-sm">
                {selectedTag}
                <button onClick={() => setSelectedTag('')} className="hover:text-brand-300">
                  <X className="w-3 h-3" />
                </button>
              </span>
            )}
          </div>
        )}

        {/* Results Count */}
        <div className="mb-4 text-sm text-slate-400">
          {loading ? 'Loading...' : `${resources.length} resources found`}
        </div>

        {/* Resources Grid */}
        {loading ? (
          <div className="flex items-center justify-center py-12">
            <div className="text-slate-400">Loading resources...</div>
          </div>
        ) : resources.length === 0 ? (
          <div className="bg-surface-2 border border-surface-border rounded-2xl p-8 text-center">
            <BookOpen className="w-12 h-12 text-slate-500 mx-auto mb-4" />
            <p className="text-slate-400 mb-2">No resources found</p>
            <p className="text-slate-500 text-sm">Try adjusting your search or filters</p>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {resources.map((resource) => (
              <div key={resource.id} className="bg-surface-2 border border-surface-border rounded-xl overflow-hidden hover:border-surface-border-hover transition-colors">
                {/* Thumbnail or placeholder */}
                <div className="aspect-video bg-surface-3 flex items-center justify-center relative">
                  {resource.thumbnail_storage_path ? (
                    <AuthenticatedThumbnail
                      resourceId={resource.id}
                      alt={resource.title}
                      className="w-full h-full object-cover"
                    />
                  ) : (
                    <BookOpen className="w-16 h-16 text-slate-600" />
                  )}
                </div>

                <div className="p-4">
                  <h3 className="font-semibold text-slate-100 mb-2 line-clamp-2">{resource.title}</h3>
                  {resource.description && (
                    <p className="text-slate-400 text-sm mb-3 line-clamp-2">{resource.description}</p>
                  )}

                  <div className="flex flex-wrap gap-2 mb-3">
                    {resource.category && (
                      <span className="text-xs px-2 py-1 bg-surface-3 text-slate-400 rounded">
                        {resource.category.label}
                      </span>
                    )}
                    {resource.language && (
                      <span className="text-xs px-2 py-1 bg-surface-3 text-slate-400 rounded">
                        {resource.language.toUpperCase()}
                      </span>
                    )}
                  </div>

                  <div className="flex gap-2">
                    <Link
                      href={`/resources/reader/${resource.id}`}
                      className="flex-1 flex items-center justify-center gap-2 px-3 py-2 bg-brand-500 hover:bg-brand-600 text-white rounded-lg transition-colors text-sm"
                      title="Open in reader"
                    >
                      <BookOpen className="w-4 h-4" />
                      Read
                    </Link>
                    <button
                      onClick={() => handleSave(resource.id)}
                      className="flex-1 flex items-center justify-center gap-2 px-3 py-2 bg-surface-3 hover:bg-surface-4 text-slate-300 rounded-lg transition-colors text-sm"
                      title={savedStatus[resource.id] ? 'Remove from library' : 'Save to library'}
                    >
                      <Bookmark className={`w-4 h-4 ${savedStatus[resource.id] ? 'fill-brand-400 text-brand-400' : ''}`} />
                      {savedStatus[resource.id] ? 'Saved' : 'Save'}
                    </button>
                    <button
                      onClick={() => handleDownload(resource.id)}
                      disabled={downloading[resource.id]}
                      className="flex-1 flex items-center justify-center gap-2 px-3 py-2 bg-surface-3 hover:bg-surface-4 text-slate-300 rounded-lg transition-colors text-sm disabled:opacity-50"
                      title="Download for offline reading"
                    >
                      {downloading[resource.id] ? (
                        <Loader2 className="w-4 h-4 animate-spin" />
                      ) : (
                        <Download className="w-4 h-4" />
                      )}
                      {downloading[resource.id] ? 'Downloading...' : downloadStatus[resource.id] ? 'Downloaded' : 'Download'}
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
