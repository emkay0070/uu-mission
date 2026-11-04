'use client';
import { useState } from 'react';
import { MediaCard } from './MediaCard';
import { cn } from './ui';
import { Search, Filter, Upload, Grid, List, FileText } from 'lucide-react';

interface MediaGalleryProps {
  media: Array<{
    id: string;
    uuid: string;
    title: string | null;
    caption: string | null;
    media_type: 'image' | 'video' | 'audio' | 'document';
    thumbnail_url: string | null;
    url: string | null;
    original_filename: string;
    file_size: number;
    created_at: string;
  }>;
  loading?: boolean;
  onUploadClick?: () => void;
  onMediaClick?: (media: any) => void;
  onMediaMenuClick?: (media: any, e: React.MouseEvent) => void;
}

export function MediaGallery({ 
  media, 
  loading = false, 
  onUploadClick, 
  onMediaClick, 
  onMediaMenuClick 
}: MediaGalleryProps) {
  const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');
  const [searchQuery, setSearchQuery] = useState('');
  const [filterType, setFilterType] = useState<'all' | 'image' | 'video' | 'audio' | 'document'>('all');

  const filteredMedia = media.filter(m => {
    const matchesSearch = !searchQuery || 
      m.title?.toLowerCase().includes(searchQuery.toLowerCase()) ||
      m.original_filename?.toLowerCase().includes(searchQuery.toLowerCase());
    
    const matchesType = filterType === 'all' || m.media_type === filterType;
    
    return matchesSearch && matchesType;
  });

  return (
    <div className="space-y-4">
      {/* Toolbar */}
      <div className="flex items-center justify-between gap-4">
        <div className="flex items-center gap-2 flex-1">
          <div className="relative flex-1 max-w-md">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
            <input
              type="text"
              placeholder="Search media..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full pl-10 pr-4 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-brand-500"
            />
          </div>
          
          <select
            value={filterType}
            onChange={(e) => setFilterType(e.target.value as any)}
            className="px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-200 focus:outline-none focus:border-brand-500"
          >
            <option value="all">All Types</option>
            <option value="image">Images</option>
            <option value="video">Videos</option>
            <option value="audio">Audio</option>
            <option value="document">Documents</option>
          </select>
        </div>

        <div className="flex items-center gap-2">
          <div className="flex items-center bg-surface-2 rounded-lg border border-surface-border p-1">
            <button
              onClick={() => setViewMode('grid')}
              className={cn(
                'p-2 rounded-md transition-colors',
                viewMode === 'grid' ? 'bg-brand-600 text-white' : 'text-slate-400 hover:text-slate-200'
              )}
            >
              <Grid className="w-4 h-4" />
            </button>
            <button
              onClick={() => setViewMode('list')}
              className={cn(
                'p-2 rounded-md transition-colors',
                viewMode === 'list' ? 'bg-brand-600 text-white' : 'text-slate-400 hover:text-slate-200'
              )}
            >
              <List className="w-4 h-4" />
            </button>
          </div>

          {onUploadClick && (
            <button
              onClick={onUploadClick}
              className="flex items-center gap-2 px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700 transition-colors"
            >
              <Upload className="w-4 h-4" />
              Upload
            </button>
          )}
        </div>
      </div>

      {/* Loading state */}
      {loading && (
        <div className="flex items-center justify-center py-12">
          <div className="text-slate-400">Loading media...</div>
        </div>
      )}

      {/* Empty state */}
      {!loading && filteredMedia.length === 0 && (
        <div className="flex flex-col items-center justify-center py-12 text-center">
          <div className="w-16 h-16 rounded-full bg-surface-3 flex items-center justify-center mb-4">
            <Filter className="w-8 h-8 text-slate-500" />
          </div>
          <p className="text-slate-400 mb-2">No media found</p>
          {searchQuery || filterType !== 'all' ? (
            <p className="text-sm text-slate-500">Try adjusting your filters</p>
          ) : onUploadClick ? (
            <button
              onClick={onUploadClick}
              className="mt-4 px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700 transition-colors"
            >
              Upload your first media
            </button>
          ) : null}
        </div>
      )}

      {/* Grid view */}
      {!loading && filteredMedia.length > 0 && viewMode === 'grid' && (
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
          {filteredMedia.map((media) => (
            <MediaCard
              key={media.uuid}
              media={media}
              onClick={() => onMediaClick?.(media)}
              onMenuClick={(e) => onMediaMenuClick?.(media, e)}
            />
          ))}
        </div>
      )}

      {/* List view */}
      {!loading && filteredMedia.length > 0 && viewMode === 'list' && (
        <div className="space-y-2">
          {filteredMedia.map((media) => (
            <div
              key={media.uuid}
              onClick={() => onMediaClick?.(media)}
              className="flex items-center gap-4 p-3 rounded-lg bg-surface-2 border border-surface-border hover:border-brand-500/50 cursor-pointer transition-colors"
            >
              <div className="w-12 h-12 rounded-lg bg-surface-3 flex items-center justify-center shrink-0 overflow-hidden">
                {((media.thumbnail_url || media.url) && media.media_type === 'image') ? (
                  <img
                    src={(media.thumbnail_url || media.url)!}
                    alt={media.title || media.original_filename}
                    className="w-full h-full object-cover rounded-lg"
                  />
                ) : (
                  <FileText className="w-6 h-6 text-slate-500" />
                )}
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-slate-200 truncate">
                  {media.title || media.original_filename}
                </p>
                <p className="text-xs text-slate-500">
                  {media.media_type} • {(media.file_size / 1024 / 1024).toFixed(2)} MB
                </p>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
