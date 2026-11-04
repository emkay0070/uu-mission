'use client';
import { useState, useEffect } from 'react';
import { MediaGallery } from '@/components/MediaGallery';
import { MediaUploadModal } from '@/components/MediaUploadModal';
import { MediaPreviewModal } from '@/components/MediaPreviewModal';
import { useAuthStore } from '@/store/auth';

type TabType = 'all' | 'photos' | 'videos' | 'audio' | 'documents' | 'my-uploads' | 'collections';

export default function MediaPage() {
  const { user, token } = useAuthStore();
  const [activeTab, setActiveTab] = useState<TabType>('all');
  const [media, setMedia] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [uploadModalOpen, setUploadModalOpen] = useState(false);
  const [previewModalOpen, setPreviewModalOpen] = useState(false);
  const [selectedMedia, setSelectedMedia] = useState<any>(null);

  const tabs: { id: TabType; label: string }[] = [
    { id: 'all', label: 'All Media' },
    { id: 'photos', label: 'Photos' },
    { id: 'videos', label: 'Videos' },
    { id: 'audio', label: 'Audio' },
    { id: 'documents', label: 'Documents' },
    { id: 'my-uploads', label: 'My Uploads' },
    { id: 'collections', label: 'Collections' },
  ];

  const fetchMedia = async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      
      if (activeTab === 'photos') params.append('media_type', 'image');
      else if (activeTab === 'videos') params.append('media_type', 'video');
      else if (activeTab === 'audio') params.append('media_type', 'audio');
      else if (activeTab === 'documents') params.append('media_type', 'document');
      else if (activeTab === 'my-uploads') params.append('my_uploads', 'true');

      const response = await fetch(`/api/v1/media?${params.toString()}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
        },
      });

      if (response.ok) {
        const data = await response.json();
        setMedia(data.data || []);
      }
    } catch (error) {
      console.error('Failed to fetch media:', error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (token) {
      fetchMedia();
    }
  }, [activeTab, token]);

  const handleUpload = async (files: File[], metadata: any) => {
    const formData = new FormData();
    
    files.forEach(file => {
      formData.append('file', file);
    });

    if (metadata.title) formData.append('title', metadata.title);
    if (metadata.caption) formData.append('caption', metadata.caption);
    formData.append('visibility', metadata.visibility);

    const response = await fetch('/api/v1/media', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
      },
      body: formData,
    });

    if (response.ok) {
      await fetchMedia();
    } else {
      throw new Error('Upload failed');
    }
  };

  const handleMediaClick = async (mediaItem: any) => {
    // Fetch full media details including URL
    try {
      const response = await fetch(`/api/v1/media/${mediaItem.uuid}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
        },
      });

      if (response.ok) {
        const fullMedia = await response.json();
        setSelectedMedia(fullMedia);
        setPreviewModalOpen(true);
      }
    } catch (error) {
      console.error('Failed to fetch media details:', error);
    }
  };

  const handleMediaDelete = async () => {
    if (!selectedMedia) return;

    const response = await fetch(`/api/v1/media/${selectedMedia.uuid}`, {
      method: 'DELETE',
      headers: {
        'Authorization': `Bearer ${token}`,
      },
    });

    if (response.ok) {
      setPreviewModalOpen(false);
      setSelectedMedia(null);
      await fetchMedia();
    }
  };

  return (
    <div className="min-h-screen atmospheric-bg">
      <div className="max-w-7xl mx-auto px-4 py-6">
        {/* Header */}
        <div className="mb-6">
          <h1 className="text-2xl font-bold text-slate-100 mb-2">Media Library</h1>
          <p className="text-slate-400">Upload, organize, and manage your mission media</p>
        </div>

        {/* Tabs */}
        <div className="flex items-center gap-1 mb-6 overflow-x-auto pb-2">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={cn(
                'px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap transition-colors',
                activeTab === tab.id
                  ? 'bg-brand-600 text-white'
                  : 'text-slate-400 hover:bg-surface-2 hover:text-slate-200'
              )}
            >
              {tab.label}
            </button>
          ))}
        </div>

        {/* Collections tab placeholder */}
        {activeTab === 'collections' && (
          <div className="flex flex-col items-center justify-center py-12 text-center">
            <p className="text-slate-400 mb-2">Collections coming soon</p>
            <p className="text-sm text-slate-500">Organize your media into curated collections</p>
          </div>
        )}

        {/* Media gallery */}
        {activeTab !== 'collections' && (
          <MediaGallery
            media={media}
            loading={loading}
            onUploadClick={() => setUploadModalOpen(true)}
            onMediaClick={handleMediaClick}
          />
        )}
      </div>

      {/* Upload modal */}
      <MediaUploadModal
        isOpen={uploadModalOpen}
        onClose={() => setUploadModalOpen(false)}
        onUpload={handleUpload}
      />

      {/* Preview modal */}
      <MediaPreviewModal
        isOpen={previewModalOpen}
        onClose={() => {
          setPreviewModalOpen(false);
          setSelectedMedia(null);
        }}
        media={selectedMedia}
        onDelete={handleMediaDelete}
      />
    </div>
  );
}

function cn(...classes: (string | boolean | undefined | null)[]) {
  return classes.filter(Boolean).join(' ');
}
