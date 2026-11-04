'use client';
import { useState } from 'react';
import { X, Plus, Image as ImageIcon, Video, Music, FileText } from 'lucide-react';
import { cn } from './ui';

interface MediaAttachmentPickerProps {
  selectedMedia: any[];
  onMediaSelect: (media: any) => void;
  onMediaRemove: (mediaId: string) => void;
  onOpenMediaLibrary: () => void;
  maxSelections?: number;
  attachmentType?: string;
}

export function MediaAttachmentPicker({
  selectedMedia,
  onMediaSelect,
  onMediaRemove,
  onOpenMediaLibrary,
  maxSelections = 10,
  attachmentType = 'general',
}: MediaAttachmentPickerProps) {
  const [error, setError] = useState<string | null>(null);

  const handleAddMedia = (media: any) => {
    if (selectedMedia.length >= maxSelections) {
      setError(`Maximum ${maxSelections} media items allowed`);
      return;
    }

    if (selectedMedia.some(m => m.uuid === media.uuid)) {
      setError('Media already selected');
      return;
    }

    onMediaSelect(media);
    setError(null);
  };

  const getIcon = (mediaType: string) => {
    switch (mediaType) {
      case 'image': return ImageIcon;
      case 'video': return Video;
      case 'audio': return Music;
      case 'document': return FileText;
      default: return FileText;
    }
  };

  return (
    <div className="space-y-3">
      {/* Selected media grid */}
      {selectedMedia.length > 0 && (
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
          {selectedMedia.map((media) => {
            const Icon = getIcon(media.media_type);
            return (
              <div
                key={media.uuid}
                className="relative aspect-square rounded-lg overflow-hidden bg-surface-2 border border-surface-border group"
              >
                {media.thumbnail_url ? (
                  <img
                    src={media.thumbnail_url}
                    alt={media.title || media.original_filename}
                    className="w-full h-full object-cover"
                  />
                ) : (
                  <div className="w-full h-full flex items-center justify-center">
                    <Icon className="w-8 h-8 text-slate-500" />
                  </div>
                )}
                <button
                  onClick={() => onMediaRemove(media.uuid)}
                  className="absolute top-1 right-1 p-1.5 rounded-full bg-black/60 backdrop-blur-sm text-white opacity-0 group-hover:opacity-100 transition-opacity hover:bg-red-500"
                >
                  <X className="w-3 h-3" />
                </button>
              </div>
            );
          })}
        </div>
      )}

      {/* Add media button */}
      {selectedMedia.length < maxSelections && (
        <button
          onClick={onOpenMediaLibrary}
          className="flex items-center justify-center gap-2 w-full p-4 rounded-lg border-2 border-dashed border-surface-border hover:border-brand-500 hover:bg-brand-500/5 transition-colors text-slate-400 hover:text-slate-200"
        >
          <Plus className="w-5 h-5" />
          <span>Add Media ({selectedMedia.length}/{maxSelections})</span>
        </button>
      )}

      {/* Error message */}
      {error && (
        <p className="text-sm text-red-400">{error}</p>
      )}

      {/* Attachment type indicator */}
      <p className="text-xs text-slate-500">
        Attachment type: <span className="font-medium">{attachmentType}</span>
      </p>
    </div>
  );
}
