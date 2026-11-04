'use client';
import { Image as ImageIcon, Video, Music, FileText, MoreVertical } from 'lucide-react';
import { useState } from 'react';
import { cn } from './ui';

interface MediaCardProps {
  media: {
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
  };
  onClick?: () => void;
  onMenuClick?: (e: React.MouseEvent) => void;
}

export function MediaCard({ media, onClick, onMenuClick }: MediaCardProps) {
  const [imageError, setImageError] = useState(false);

  const getIcon = () => {
    switch (media.media_type) {
      case 'image': return ImageIcon;
      case 'video': return Video;
      case 'audio': return Music;
      case 'document': return FileText;
      default: return FileText;
    }
  };

  const Icon = getIcon();
  const formatFileSize = (bytes: number) => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  };

  return (
    <div
      className={cn(
        'group relative aspect-square rounded-xl overflow-hidden cursor-pointer',
        'bg-surface-2 border border-surface-border hover:border-brand-500/50',
        'transition-all duration-200 hover:shadow-lg hover:shadow-brand-500/10'
      )}
      onClick={onClick}
    >
      {/* Thumbnail or placeholder */}
      {((media.thumbnail_url || media.url) && media.media_type === 'image') && !imageError ? (
        <img
          src={(media.thumbnail_url || media.url)!}
          alt={media.title || media.original_filename}
          className="w-full h-full object-cover"
          onError={() => setImageError(true)}
        />
      ) : (
        <div className="w-full h-full flex items-center justify-center bg-surface-3">
          <Icon className="w-12 h-12 text-slate-500" />
        </div>
      )}

      {/* Media type indicator */}
      <div className="absolute top-2 left-2">
        <div className={cn(
          'px-2 py-1 rounded-md text-xs font-medium backdrop-blur-sm',
          media.media_type === 'image' && 'bg-blue-500/80 text-white',
          media.media_type === 'video' && 'bg-purple-500/80 text-white',
          media.media_type === 'audio' && 'bg-green-500/80 text-white',
          media.media_type === 'document' && 'bg-orange-500/80 text-white'
        )}>
          {media.media_type}
        </div>
      </div>

      {/* Menu button */}
      {onMenuClick && (
        <button
          onClick={(e) => {
            e.stopPropagation();
            onMenuClick(e);
          }}
          className="absolute top-2 right-2 p-1.5 rounded-lg bg-black/50 backdrop-blur-sm text-white opacity-0 group-hover:opacity-100 transition-opacity"
        >
          <MoreVertical className="w-4 h-4" />
        </button>
      )}

      {/* Overlay with info */}
      <div className="absolute inset-0 bg-gradient-to-t from-black/80 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity">
        <div className="absolute bottom-0 left-0 right-0 p-3">
          <p className="text-white text-sm font-medium truncate">
            {media.title || media.original_filename}
          </p>
          {media.caption && (
            <p className="text-slate-300 text-xs truncate mt-1">
              {media.caption}
            </p>
          )}
          <p className="text-slate-400 text-xs mt-1">
            {formatFileSize(media.file_size)}
          </p>
        </div>
      </div>
    </div>
  );
}
