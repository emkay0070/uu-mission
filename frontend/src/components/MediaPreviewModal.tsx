'use client';
import { useState } from 'react';
import { X, Download, Share2, Edit, Trash2, Calendar, User, Eye, FileText } from 'lucide-react';
import { cn } from './ui';

interface MediaPreviewModalProps {
  isOpen: boolean;
  onClose: () => void;
  media: {
    uuid: string;
    title: string | null;
    caption: string | null;
    media_type: 'image' | 'video' | 'audio' | 'document';
    url: string | null;
    thumbnail_url: string | null;
    original_filename: string;
    file_size: number;
    mime_type: string;
    width: number | null;
    height: number | null;
    duration: number | null;
    visibility: 'private' | 'organisational' | 'public';
    created_at: string;
    uploader?: {
      id: number;
      person?: {
        full_name: string;
      };
    };
  };
  onEdit?: () => void;
  onDelete?: () => void;
}

export function MediaPreviewModal({ isOpen, onClose, media, onEdit, onDelete }: MediaPreviewModalProps) {
  const [loading, setLoading] = useState(false);

  if (!isOpen) return null;

  const formatFileSize = (bytes: number) => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  };

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });
  };

  const handleDownload = async () => {
    if (!media.url) return;
    setLoading(true);
    try {
      const response = await fetch(media.url);
      const blob = await response.blob();
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = media.original_filename;
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
      document.body.removeChild(a);
    } catch (err) {
      console.error('Download failed:', err);
    } finally {
      setLoading(false);
    }
  };

  const renderMediaContent = () => {
    if (!media.url) {
      return (
        <div className="w-full h-full flex items-center justify-center bg-surface-3">
          <FileText className="w-16 h-16 text-slate-500" />
        </div>
      );
    }

    switch (media.media_type) {
      case 'image':
        return (
          <img
            src={media.url}
            alt={media.title || media.original_filename}
            className="w-full h-full object-contain"
          />
        );
      case 'video':
        return (
          <video
            src={media.url}
            controls
            className="w-full h-full"
          />
        );
      case 'audio':
        return (
          <div className="w-full h-full flex items-center justify-center bg-surface-3">
            <audio
              src={media.url}
              controls
              className="w-full max-w-md"
            />
          </div>
        );
      case 'document':
        return (
          <div className="w-full h-full flex flex-col items-center justify-center bg-surface-3 p-8">
            <FileText className="w-24 h-24 text-slate-500 mb-4" />
            <p className="text-slate-400 text-center">{media.original_filename}</p>
            <button
              onClick={handleDownload}
              disabled={loading}
              className="mt-4 px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700 transition-colors disabled:opacity-50"
            >
              {loading ? 'Downloading...' : 'Download'}
            </button>
          </div>
        );
      default:
        return null;
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
      <div className="w-full max-w-5xl max-h-[90vh] bg-surface-1 rounded-2xl shadow-2xl border border-surface-border overflow-hidden flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between p-4 border-b border-surface-border shrink-0">
          <h2 className="text-lg font-semibold text-slate-200 truncate">
            {media.title || media.original_filename}
          </h2>
          <div className="flex items-center gap-2">
            {onEdit && (
              <button
                onClick={onEdit}
                className="p-2 rounded-lg hover:bg-surface-2 text-slate-400 hover:text-slate-200 transition-colors"
                title="Edit"
              >
                <Edit className="w-5 h-5" />
              </button>
            )}
            {onDelete && (
              <button
                onClick={onDelete}
                className="p-2 rounded-lg hover:bg-red-500/20 text-slate-400 hover:text-red-400 transition-colors"
                title="Delete"
              >
                <Trash2 className="w-5 h-5" />
              </button>
            )}
            <button
              onClick={onClose}
              className="p-2 rounded-lg hover:bg-surface-2 text-slate-400 hover:text-slate-200 transition-colors"
            >
              <X className="w-5 h-5" />
            </button>
          </div>
        </div>

        {/* Content */}
        <div className="flex-1 flex overflow-hidden">
          {/* Media preview */}
          <div className="flex-1 bg-surface-2 flex items-center justify-center min-h-0">
            <div className="w-full h-full flex items-center justify-center p-4">
              {renderMediaContent()}
            </div>
          </div>

          {/* Sidebar with details */}
          <div className="w-80 border-l border-surface-border p-4 overflow-y-auto shrink-0">
            <div className="space-y-6">
              {/* Title and caption */}
              <div>
                <h3 className="text-sm font-semibold text-slate-300 mb-2">Details</h3>
                {media.title && (
                  <p className="text-slate-200 font-medium mb-1">{media.title}</p>
                )}
                {media.caption && (
                  <p className="text-sm text-slate-400">{media.caption}</p>
                )}
              </div>

              {/* File info */}
              <div>
                <h3 className="text-sm font-semibold text-slate-300 mb-2">File Information</h3>
                <div className="space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span className="text-slate-500">Filename</span>
                    <span className="text-slate-300 truncate max-w-[150px]">{media.original_filename}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-slate-500">Size</span>
                    <span className="text-slate-300">{formatFileSize(media.file_size)}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-slate-500">Type</span>
                    <span className="text-slate-300">{media.mime_type}</span>
                  </div>
                  {media.width && media.height && (
                    <div className="flex justify-between">
                      <span className="text-slate-500">Dimensions</span>
                      <span className="text-slate-300">{media.width} × {media.height}px</span>
                    </div>
                  )}
                  {media.duration && (
                    <div className="flex justify-between">
                      <span className="text-slate-500">Duration</span>
                      <span className="text-slate-300">{Math.floor(media.duration / 60)}:{(media.duration % 60).toString().padStart(2, '0')}</span>
                    </div>
                  )}
                </div>
              </div>

              {/* Visibility */}
              <div>
                <h3 className="text-sm font-semibold text-slate-300 mb-2">Visibility</h3>
                <div className="flex items-center gap-2">
                  <Eye className="w-4 h-4 text-slate-500" />
                  <span className={cn(
                    'text-sm capitalize',
                    media.visibility === 'private' && 'text-slate-400',
                    media.visibility === 'organisational' && 'text-brand-400',
                    media.visibility === 'public' && 'text-green-400'
                  )}>
                    {media.visibility}
                  </span>
                </div>
              </div>

              {/* Upload info */}
              <div>
                <h3 className="text-sm font-semibold text-slate-300 mb-2">Uploaded</h3>
                <div className="space-y-2 text-sm">
                  <div className="flex items-center gap-2">
                    <Calendar className="w-4 h-4 text-slate-500" />
                    <span className="text-slate-300">{formatDate(media.created_at)}</span>
                  </div>
                  {media.uploader && (
                    <div className="flex items-center gap-2">
                      <User className="w-4 h-4 text-slate-500" />
                      <span className="text-slate-300">{media.uploader.person?.full_name || 'Unknown'}</span>
                    </div>
                  )}
                </div>
              </div>

              {/* Actions */}
              <div className="pt-4 border-t border-surface-border">
                <button
                  onClick={handleDownload}
                  disabled={loading || !media.url}
                  className="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-surface-2 hover:bg-surface-3 text-slate-300 text-sm font-medium transition-colors disabled:opacity-50"
                >
                  <Download className="w-4 h-4" />
                  {loading ? 'Downloading...' : 'Download'}
                </button>
                <button
                  className="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-surface-2 hover:bg-surface-3 text-slate-300 text-sm font-medium transition-colors mt-2"
                >
                  <Share2 className="w-4 h-4" />
                  Share
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
