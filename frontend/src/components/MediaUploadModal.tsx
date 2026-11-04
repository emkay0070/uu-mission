'use client';
import { useState, useRef } from 'react';
import { X, Upload, Image as ImageIcon, Video, Music, FileText, AlertCircle } from 'lucide-react';
import { cn } from './ui';

interface MediaUploadModalProps {
  isOpen: boolean;
  onClose: () => void;
  onUpload: (files: File[], metadata: UploadMetadata) => Promise<void>;
}

interface UploadMetadata {
  title?: string;
  caption?: string;
  visibility: 'private' | 'organisational' | 'public';
  attachable_type?: string;
  attachable_id?: number;
  attachment_type?: string;
}

export function MediaUploadModal({ isOpen, onClose, onUpload }: MediaUploadModalProps) {
  const [files, setFiles] = useState<File[]>([]);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [metadata, setMetadata] = useState<UploadMetadata>({
    visibility: 'private',
  });
  const fileInputRef = useRef<HTMLInputElement>(null);
  const dropZoneRef = useRef<HTMLDivElement>(null);

  const handleDragOver = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    if (dropZoneRef.current) {
      dropZoneRef.current.classList.add('border-brand-500', 'bg-brand-500/10');
    }
  };

  const handleDragLeave = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    if (dropZoneRef.current) {
      dropZoneRef.current.classList.remove('border-brand-500', 'bg-brand-500/10');
    }
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    if (dropZoneRef.current) {
      dropZoneRef.current.classList.remove('border-brand-500', 'bg-brand-500/10');
    }

    const droppedFiles = Array.from(e.dataTransfer.files);
    addFiles(droppedFiles);
  };

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const selectedFiles = Array.from(e.target.files || []);
    addFiles(selectedFiles);
  };

  const addFiles = (newFiles: File[]) => {
    // Validate file types and sizes
    const validFiles = newFiles.filter(file => {
      const validTypes = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
        'video/mp4', 'video/webm', 'video/quicktime',
        'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg',
        'application/pdf',
      ];
      
      const maxSize = 500 * 1024 * 1024; // 500MB
      
      if (!validTypes.includes(file.type)) {
        setError(`Invalid file type: ${file.name}`);
        return false;
      }
      
      if (file.size > maxSize) {
        setError(`File too large: ${file.name} (max 500MB)`);
        return false;
      }
      
      return true;
    });

    setFiles(prev => [...prev, ...validFiles]);
    setError(null);
  };

  const removeFile = (index: number) => {
    setFiles(prev => prev.filter((_, i) => i !== index));
  };

  const handleUpload = async () => {
    if (files.length === 0) {
      setError('Please select at least one file');
      return;
    }

    setUploading(true);
    setError(null);

    try {
      await onUpload(files, metadata);
      setFiles([]);
      setMetadata({ visibility: 'private' });
      onClose();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Upload failed');
    } finally {
      setUploading(false);
    }
  };

  const getFileIcon = (file: File) => {
    if (file.type.startsWith('image/')) return ImageIcon;
    if (file.type.startsWith('video/')) return Video;
    if (file.type.startsWith('audio/')) return Music;
    return FileText;
  };

  const formatFileSize = (bytes: number) => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
      <div className="w-full max-w-2xl bg-surface-1 rounded-2xl shadow-2xl border border-surface-border max-h-[90vh] overflow-y-auto">
        {/* Header */}
        <div className="flex items-center justify-between p-4 border-b border-surface-border">
          <h2 className="text-lg font-semibold text-slate-200">Upload Media</h2>
          <button
            onClick={onClose}
            className="p-2 rounded-lg hover:bg-surface-2 text-slate-400 hover:text-slate-200 transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Content */}
        <div className="p-4 space-y-4">
          {/* Drop zone */}
          <div
            ref={dropZoneRef}
            onDragOver={handleDragOver}
            onDragLeave={handleDragLeave}
            onDrop={handleDrop}
            onClick={() => fileInputRef.current?.click()}
            className={cn(
              'border-2 border-dashed border-surface-border rounded-xl p-8 text-center cursor-pointer transition-colors',
              'hover:border-brand-500 hover:bg-brand-500/5'
            )}
          >
            <Upload className="w-12 h-12 mx-auto mb-4 text-slate-500" />
            <p className="text-slate-300 font-medium mb-2">
              Drag and drop files here, or click to browse
            </p>
            <p className="text-sm text-slate-500">
              Images, videos, audio, and PDFs (max 500MB)
            </p>
            <input
              ref={fileInputRef}
              type="file"
              multiple
              accept="image/*,video/*,audio/*,.pdf"
              onChange={handleFileSelect}
              className="hidden"
            />
          </div>

          {/* File list */}
          {files.length > 0 && (
            <div className="space-y-2 max-h-48 overflow-y-auto">
              {files.map((file, index) => {
                const Icon = getFileIcon(file);
                return (
                  <div
                    key={index}
                    className="flex items-center gap-3 p-3 rounded-lg bg-surface-2 border border-surface-border"
                  >
                    <div className="w-10 h-10 rounded-lg bg-surface-3 flex items-center justify-center shrink-0">
                      <Icon className="w-5 h-5 text-slate-500" />
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium text-slate-200 truncate">
                        {file.name}
                      </p>
                      <p className="text-xs text-slate-500">
                        {formatFileSize(file.size)}
                      </p>
                    </div>
                    <button
                      onClick={() => removeFile(index)}
                      className="p-1.5 rounded-lg hover:bg-surface-3 text-slate-400 hover:text-red-400 transition-colors"
                    >
                      <X className="w-4 h-4" />
                    </button>
                  </div>
                );
              })}
            </div>
          )}

          {/* Metadata form */}
          <div className="space-y-3">
            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">
                Title (optional)
              </label>
              <input
                type="text"
                value={metadata.title || ''}
                onChange={(e) => setMetadata({ ...metadata, title: e.target.value })}
                placeholder="Add a title for all files"
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-brand-500"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">
                Caption (optional)
              </label>
              <textarea
                value={metadata.caption || ''}
                onChange={(e) => setMetadata({ ...metadata, caption: e.target.value })}
                placeholder="Add a description for all files"
                rows={2}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-brand-500 resize-none"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">
                Visibility
              </label>
              <select
                value={metadata.visibility}
                onChange={(e) => setMetadata({ ...metadata, visibility: e.target.value as any })}
                className="w-full px-3 py-2 rounded-lg bg-surface-2 border border-surface-border text-sm text-slate-200 focus:outline-none focus:border-brand-500"
              >
                <option value="private">Private (only you)</option>
                <option value="organisational">Organisational (all users)</option>
                <option value="public">Public (anyone)</option>
              </select>
            </div>
          </div>

          {/* Error message */}
          {error && (
            <div className="flex items-start gap-2 p-3 rounded-lg bg-red-500/10 border border-red-500/30">
              <AlertCircle className="w-5 h-5 text-red-400 shrink-0 mt-0.5" />
              <p className="text-sm text-red-400">{error}</p>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end gap-3 p-4 border-t border-surface-border">
          <button
            onClick={onClose}
            disabled={uploading}
            className="px-4 py-2 rounded-lg text-sm font-medium text-slate-300 hover:bg-surface-2 transition-colors disabled:opacity-50"
          >
            Cancel
          </button>
          <button
            onClick={handleUpload}
            disabled={uploading || files.length === 0}
            className="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {uploading ? 'Uploading...' : `Upload ${files.length} file${files.length !== 1 ? 's' : ''}`}
          </button>
        </div>
      </div>
    </div>
  );
}
