'use client';

import { useState, useEffect, useCallback, useRef } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { ArrowLeft, Bookmark, Highlighter, MessageSquare, Loader2, AlertCircle, WifiOff, Sparkles, Send, Save, Edit3, RefreshCw, BookOpen, FileText, X } from 'lucide-react';
import { resourcesApi, ai, learningNotesApi, Resource } from '@/lib/api';
import { offlineStorage } from '@/lib/offlineStorage';
import Viewer from '@embedpdf/react-pdf-viewer';

interface ReadingProgress {
  last_page: number;
  total_pages: number;
  completion_percentage: number;
  reading_position: { page: number; x: number; y: number } | null;
  last_read_at: string;
}

interface Bookmark {
  id: number;
  page_number: number;
  title: string;
  position_data: any;
}

interface Highlighter {
  id: number;
  selected_text: string;
  page_number: number;
  bounding_box: any;
  color: string;
}

interface Note {
  id: number;
  content: string;
  page_number: number;
  position_data: any;
}

interface Conversation {
  id: number;
  title: string;
  last_message_at: string | null;
  message_count: number;
  resource_version_id: number;
}

interface Message {
  id: number;
  role: 'user' | 'assistant';
  content: string;
  page_number: number | null;
  selected_text: string | null;
  status: string;
  error_message: string | null;
  created_at: string;
  citations?: Array<{ page_number: number; chapter?: string; heading?: string }>;
}

interface LearningNote {
  id: number;
  note_type: string;
  title: string;
  content: string;
  chapter: string | null;
  page_number: number | null;
  passage_text: string | null;
  created_at: string;
}

export default function PDFReader() {
  const params = useParams();
  const router = useRouter();
  const resourceId = Number(params.id);
  
  const [resource, setResource] = useState<Resource | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [isOffline, setIsOffline] = useState(false);
  const [pdfBlobUrl, setPdfBlobUrl] = useState<string | null>(null);
  const [remotePdfUrl, setRemotePdfUrl] = useState<string | null>(null);
  const [pdfFileError, setPdfFileError] = useState<string | null>(null);
  
  // Reading state
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(0);
  const [progress, setProgress] = useState<ReadingProgress | null>(null);
  
  // Annotations
  const [bookmarks, setBookmarks] = useState<Bookmark[]>([]);
  const [highlights, setHighlights] = useState<Highlighter[]>([]);
  const [notes, setNotes] = useState<Note[]>([]);
  const [showBookmarks, setShowBookmarks] = useState(false);
  const [showHighlights, setShowHighlights] = useState(false);
  const [showNotes, setShowNotes] = useState(false);
  const [noteError, setNoteError] = useState<string | null>(null);
  
  // AI Companion
  const [showAI, setShowAI] = useState(false);
  const [aiError, setAiError] = useState<string | null>(null);
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [currentConversation, setCurrentConversation] = useState<Conversation | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [chatInput, setChatInput] = useState('');
  const [isChatLoading, setIsChatLoading] = useState(false);
  const [selectedText, setSelectedText] = useState<string | null>(null);
  const [showLearningNotes, setShowLearningNotes] = useState(false);
  const [learningNotes, setLearningNotes] = useState<LearningNote[]>([]);
  const [isCreatingNote, setIsCreatingNote] = useState(false);
  const [noteInput, setNoteInput] = useState('');
  const [noteTitle, setNoteTitle] = useState('');
  const [noteType, setNoteType] = useState<'understanding' | 'reflection' | 'chapter_summary' | 'book_summary' | 'important_passage' | 'question'>('understanding');
  const [editingNote, setEditingNote] = useState<LearningNote | null>(null);
  const [showNotebookView, setShowNotebookView] = useState(false);
  const [notebookView, setNotebookView] = useState<'book' | 'chapter'>('book');
  const [selectedChapter, setSelectedChapter] = useState<string | null>(null);
  const [savedExplanationIds, setSavedExplanationIds] = useState<Set<number>>(new Set());
  const [associatedExplanationId, setAssociatedExplanationId] = useState<number | null>(null);
  
  // Auto-sync timer
  const syncTimerRef = useRef<NodeJS.Timeout | null>(null);

  useEffect(() => {
    loadResource();
    loadReadingProgress();
    loadAnnotations();
    checkOfflineAvailability();

    return () => {
      if (syncTimerRef.current) {
        clearInterval(syncTimerRef.current);
      }
      if (pdfBlobUrl) {
        URL.revokeObjectURL(pdfBlobUrl);
      }
    };
  }, [resourceId]);

  // Load conversations when AI sidebar opens
  useEffect(() => {
    if (showAI && conversations.length === 0) {
      loadConversations();
    }
  }, [showAI]);

  // The PDF viewer fetches its `src` itself, so it cannot send the bearer
  // token. Fetch the protected preview with auth and pass it a local blob URL.
  useEffect(() => {
    let cancelled = false;
    let objectUrl: string | null = null;

    setRemotePdfUrl(null);
    setPdfFileError(null);

    resourcesApi
      .previewBlob(resourceId)
      .then((blob) => {
        if (cancelled) return;
        objectUrl = URL.createObjectURL(blob);
        setRemotePdfUrl(objectUrl);
      })
      .catch((err: any) => {
        if (cancelled) return;
        console.error('Failed to load resource file:', err);
        setPdfFileError(err?.message || 'The resource file could not be loaded.');
      });

    return () => {
      cancelled = true;
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
      }
    };
  }, [resourceId]);

  // Auto-sync reading progress every 30 seconds
  useEffect(() => {
    syncTimerRef.current = setInterval(() => {
      if (resource && currentPage > 0) {
        saveReadingProgress();
      }
    }, 30000);
    
    return () => {
      if (syncTimerRef.current) {
        clearInterval(syncTimerRef.current);
      }
    };
  }, [resource, currentPage]);

  const loadResource = async () => {
    try {
      setLoading(true);
      const data = await resourcesApi.get(resourceId);
      setResource(data);
    } catch (err) {
      setError('Failed to load resource');
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  const loadReadingProgress = async () => {
    try {
      const data: any = await resourcesApi.progress.get(resourceId);
      if (data) {
        setProgress(data);
        if (data.last_page) {
          setCurrentPage(data.last_page);
        }
        if (data.total_pages) {
          setTotalPages(data.total_pages);
        }
      }
    } catch (err) {
      console.error('Failed to load reading progress:', err);
    }
  };

  const loadAnnotations = async () => {
    try {
      const versionId = resource?.currentVersion?.id;
      const [bookmarksData, highlightsData, notesData] = await Promise.all([
        resourcesApi.bookmarks.list(resourceId, versionId),
        resourcesApi.highlights.list(resourceId, versionId ? { version_id: versionId } : undefined),
        resourcesApi.notes.list(resourceId, versionId ? { version_id: versionId } : undefined),
      ]);
      
      setBookmarks((bookmarksData as any) || []);
      setHighlights((highlightsData as any) || []);
      setNotes((notesData as any) || []);
    } catch (err) {
      console.error('Failed to load annotations:', err);
    }
  };

  const checkOfflineAvailability = async () => {
    try {
      const offlineDownload = await offlineStorage.getDownload(resourceId);
      if (offlineDownload) {
        setIsOffline(true);
        // Create blob URL from stored ArrayBuffer
        const blob = new Blob([offlineDownload.fileData], { type: offlineDownload.mimeType });
        const url = URL.createObjectURL(blob);
        setPdfBlobUrl(url);
        await offlineStorage.updateAccessTime(resourceId);
      }
    } catch (err) {
      console.error('Failed to check offline availability:', err);
    }
  };

  const saveReadingProgress = useCallback(async () => {
    if (!resource || !resource.currentVersion) return;
    
    try {
      const completion = totalPages > 0 ? (currentPage / totalPages) * 100 : 0;
      await resourcesApi.progress.save(resourceId, {
        version_id: resource.currentVersion.id,
        last_page: currentPage,
        total_pages: totalPages,
        completion_percentage: Math.min(completion, 100),
        reading_position: { page: currentPage, x: 0, y: 0 },
      });
    } catch (err) {
      console.error('Failed to save reading progress:', err);
    }
  }, [resource, currentPage, totalPages, resourceId]);

  const handlePageChange = (newPage: number) => {
    setCurrentPage(newPage);
    saveReadingProgress();
  };

  const handleBookmark = async () => {
    if (!resource?.currentVersion) return;
    
    try {
      await resourcesApi.bookmarks.create(resourceId, {
        version_id: resource.currentVersion.id,
        page_number: currentPage,
        title: `Page ${currentPage}`,
      });
      loadAnnotations();
    } catch (err) {
      console.error('Failed to create bookmark:', err);
    }
  };

  const handleDeleteBookmark = async (bookmarkId: number) => {
    try {
      await resourcesApi.bookmarks.delete(resourceId, bookmarkId);
      loadAnnotations();
    } catch (err) {
      console.error('Failed to delete bookmark:', err);
    }
  };

  const handleCreateNote = async (content: string): Promise<boolean> => {
    if (!resource?.currentVersion || !content.trim()) return false;
    setNoteError(null);
    try {
      await resourcesApi.notes.create(resourceId, {
        version_id: resource.currentVersion.id,
        content,
        page_number: currentPage || 1,
      });
      await loadAnnotations();
      return true;
    } catch (err: any) {
      console.error('Failed to create note:', err);
      setNoteError(err?.message || 'Note could not be saved.');
      return false;
    }
  };

  // Offline downloads take precedence; otherwise use the authenticated preview
  // blob fetched above. Never build a `?token=` URL: Sanctum only reads the
  // Authorization header.
  const pdfUrl = pdfBlobUrl || remotePdfUrl || '';

  const handleDeleteNote = async (noteId: number) => {
    try {
      await resourcesApi.notes.delete(resourceId, noteId);
      loadAnnotations();
    } catch (err) {
      console.error('Failed to delete note:', err);
    }
  };

  const loadConversations = async () => {
    try {
      const data: any = await ai.conversations(resourceId);
      setConversations(data.conversations || []);
    } catch (err) {
      console.error('Failed to load conversations:', err);
    }
  };

  const loadConversationMessages = async (conversationId: number) => {
    try {
      const data: any = await ai.getConversation(conversationId);
      setMessages(data.messages || []);
      setCurrentConversation(data.conversation);
    } catch (err) {
      console.error('Failed to load conversation messages:', err);
    }
  };

  const startNewConversation = () => {
    setCurrentConversation(null);
    setMessages([]);
    setChatInput('');
  };

  const handleSendMessage = async () => {
    if (!chatInput.trim() || isChatLoading) return;

    setIsChatLoading(true);
    setAiError(null);
    const isDraft = resource?.status === 'draft';
    const basePayload = {
      message: chatInput,
      conversation_id: currentConversation?.id,
      page_number: currentPage,
      selected_text: selectedText || undefined,
    };
    try {
      let data: any;
      try {
        data = await ai.chat(resourceId, {
          ...basePayload,
          context_type: isDraft
            ? 'general_explanation'
            : selectedText
              ? 'selected_text'
              : 'general',
        });
      } catch (firstErr: any) {
        // If the book has no indexed context (draft/unprocessed/PDF without chunks),
        // retry once as a general explanation so the user still gets a response.
        const msg = String(firstErr?.message || '').toLowerCase();
        if (firstErr?.status === 400 && (msg.includes('relevant passages') || msg.includes('no_book_context'))) {
          data = await ai.chat(resourceId, {
            ...basePayload,
            context_type: 'general_explanation',
          });
        } else {
          throw firstErr;
        }
      }

      if (data.conversation_id && !currentConversation) {
        setCurrentConversation({
          id: data.conversation_id,
          title: chatInput.substring(0, 50) + (chatInput.length > 50 ? '...' : ''),
          last_message_at: new Date().toISOString(),
          message_count: 2,
          resource_version_id: resource?.currentVersion?.id || 0,
        });
      }

      // Reload messages to ensure conversation history is up to date
      if (data.conversation_id) {
        await loadConversationMessages(data.conversation_id);
      }

      // Reload conversation list to update message counts
      loadConversations();

      setChatInput('');
      setSelectedText(null);
    } catch (err: any) {
      console.error('Failed to send message:', err);
      const errorMessage = err.message || 'Failed to send message';
      setAiError(err?.message || 'The AI companion could not respond. Check that the resource is readable and the AI service is configured.');
      // Handle AI service unavailable or quota exhausted
      if (err.status === 503 || errorMessage.includes('quota') || errorMessage.includes('unavailable')) {
        alert('AI service is currently unavailable. Please try again later.');
      } else {
        alert(errorMessage);
      }
    } finally {
      setIsChatLoading(false);
    }
  };

  const handleSaveExplanation = async (messageId: number, content: string) => {
    if (savedExplanationIds.has(messageId)) {
      alert('This explanation has already been saved.');
      return;
    }

    try {
      await ai.saveExplanation({
        conversation_message_id: messageId,
        title: `Explanation from ${new Date().toLocaleDateString()}`,
        content,
        page_number: currentPage,
      });
      setSavedExplanationIds(prev => new Set(prev).add(messageId));
      alert('Explanation saved!');
    } catch (err: any) {
      console.error('Failed to save explanation:', err);
      alert(err.message || 'Failed to save explanation');
    }
  };

  const handleRetryMessage = async (messageId: number) => {
    try {
      setIsChatLoading(true);
      await ai.retryMessage(messageId);
      // Reload messages after successful retry
      if (currentConversation) {
        await loadConversationMessages(currentConversation.id);
      }
    } catch (err: any) {
      console.error('Failed to retry message:', err);
      alert(err.message || 'Failed to retry message');
    } finally {
      setIsChatLoading(false);
    }
  };

  const loadLearningNotes = async () => {
    try {
      const params: any = {};
      if (notebookView === 'chapter' && selectedChapter) {
        params.chapter = selectedChapter;
      }
      const data: any = await learningNotesApi.list(resourceId, params);
      setLearningNotes(data.learning_notes || []);
    } catch (err) {
      console.error('Failed to load learning notes:', err);
    }
  };

  const handleCreateLearningNote = async () => {
    if (!noteInput.trim() || !noteTitle.trim()) return;

    setIsCreatingNote(true);
    try {
      await learningNotesApi.create(resourceId, {
        note_type: noteType,
        title: noteTitle,
        content: noteInput,
        chapter: selectedChapter || undefined,
        page_number: currentPage,
        conversation_message_id: associatedExplanationId || undefined,
      });
      setNoteInput('');
      setNoteTitle('');
      setAssociatedExplanationId(null);
      loadLearningNotes();
      alert('Learning note saved!');
    } catch (err: any) {
      console.error('Failed to create learning note:', err);
      alert(err.message || 'Failed to save note');
    } finally {
      setIsCreatingNote(false);
    }
  };

  const handleUpdateLearningNote = async () => {
    if (!editingNote || !noteInput.trim() || !noteTitle.trim()) return;

    setIsCreatingNote(true);
    try {
      await learningNotesApi.update(resourceId, editingNote.id, {
        title: noteTitle,
        content: noteInput,
      });
      setEditingNote(null);
      setNoteInput('');
      setNoteTitle('');
      loadLearningNotes();
      alert('Learning note updated!');
    } catch (err: any) {
      console.error('Failed to update learning note:', err);
      alert(err.message || 'Failed to update note');
    } finally {
      setIsCreatingNote(false);
    }
  };

  const startEditNote = (note: LearningNote) => {
    setEditingNote(note);
    setNoteTitle(note.title);
    setNoteInput(note.content);
    setNoteType(note.note_type as any);
  };

  const cancelEditNote = () => {
    setEditingNote(null);
    setNoteInput('');
    setNoteTitle('');
  };

  const handleWriteUnderstanding = async (messageId: number, aiContent: string) => {
    setEditingNote(null);
    setNoteType('understanding');
    setNoteTitle('My Understanding');
    setNoteInput('');
    setShowLearningNotes(true);
    // Optionally associate with the saved explanation
    setAssociatedExplanationId(messageId);
    // Pre-fill with a prompt to help user write their own understanding
    setNoteInput('In my own words: ');
  };

  // Reload learning notes when view or chapter filter changes
  useEffect(() => {
    if (showLearningNotes) {
      loadLearningNotes();
    }
  }, [notebookView, selectedChapter, showLearningNotes]);

  const handleDeleteLearningNote = async (noteId: number) => {
    try {
      await learningNotesApi.delete(resourceId, noteId);
      loadLearningNotes();
    } catch (err) {
      console.error('Failed to delete learning note:', err);
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-surface flex items-center justify-center">
        <div className="text-center">
          <Loader2 className="w-12 h-12 text-brand-400 animate-spin mx-auto mb-4" />
          <p className="text-slate-400">Loading your book...</p>
        </div>
      </div>
    );
  }

  if (error || !resource) {
    return (
      <div className="min-h-screen bg-surface flex items-center justify-center p-6">
        <div className="text-center max-w-md">
          <AlertCircle className="w-12 h-12 text-red-400 mx-auto mb-4" />
          <h2 className="text-xl font-semibold text-slate-100 mb-2">
            {error || 'Resource not found'}
          </h2>
          <p className="text-slate-400 mb-6">
            We couldn't load this resource. Please try again or contact support.
          </p>
          <button
            onClick={() => router.back()}
            className="px-6 py-2 bg-brand-500 hover:bg-brand-600 text-white rounded-lg transition-colors"
          >
            Go Back
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="h-screen flex flex-col bg-surface">
      {/* Header */}
      <div className="bg-surface-2 border-b border-surface-border px-4 py-3 flex items-center justify-between">
        <div className="flex items-center gap-4">
          <button
            onClick={() => router.back()}
            className="p-2 hover:bg-surface-3 rounded-lg transition-colors"
            title="Back to library"
          >
            <ArrowLeft className="w-5 h-5 text-slate-400" />
          </button>
          <div className="flex-1 min-w-0">
            <h1 className="font-semibold text-slate-100 truncate">{resource.title}</h1>
            <p className="text-xs text-slate-500">
              Page {currentPage} of {totalPages || '?'} · {progress ? `${Math.round(progress.completion_percentage)}% complete` : '0% complete'}
            </p>
          </div>
        </div>
        
        <div className="flex items-center gap-2">
          {isOffline && (
            <div className="flex items-center gap-1 px-2 py-1 bg-amber-500/20 text-amber-400 rounded text-xs">
              <WifiOff className="w-3 h-3" />
              Offline
            </div>
          )}
          <button
            onClick={() => setShowAI(!showAI)}
            className={`p-2 rounded-lg transition-colors ${showAI ? 'bg-brand-500/20 text-brand-400' : 'hover:bg-surface-3 text-slate-400'}`}
            title="AI Learning Companion"
          >
            <Sparkles className="w-5 h-5" />
          </button>
          <button
            onClick={() => setShowBookmarks(!showBookmarks)}
            className={`p-2 rounded-lg transition-colors ${showBookmarks ? 'bg-brand-500/20 text-brand-400' : 'hover:bg-surface-3 text-slate-400'}`}
            title="Bookmarks"
          >
            <Bookmark className="w-5 h-5" />
          </button>
          <button
            onClick={() => setShowHighlights(!showHighlights)}
            className={`p-2 rounded-lg transition-colors ${showHighlights ? 'bg-brand-500/20 text-brand-400' : 'hover:bg-surface-3 text-slate-400'}`}
            title="Highlights"
          >
            <Highlighter className="w-5 h-5" />
          </button>
          <button
            onClick={() => setShowNotes(!showNotes)}
            className={`p-2 rounded-lg transition-colors ${showNotes ? 'bg-brand-500/20 text-brand-400' : 'hover:bg-surface-3 text-slate-400'}`}
            title="Notes"
          >
            <MessageSquare className="w-5 h-5" />
          </button>
        </div>
      </div>

      {/* Main Content */}
      <div className="flex-1 flex overflow-hidden relative">
        {/* PDF Viewer */}
        <div className={`flex-1 bg-surface-3 overflow-hidden transition-all ${showAI ? 'hidden md:block' : 'block'}`}>
          {pdfUrl ? (
            <div className="h-full">
              <Viewer
                config={{
                  src: pdfUrl,
                  theme: { preference: 'system' },
                }}
                style={{ width: '100%', height: '100%' }}
              />
            </div>
          ) : pdfFileError ? (
            <div className="h-full flex flex-col items-center justify-center gap-3 p-6 text-center">
              <AlertCircle className="w-10 h-10 text-amber-400" />
              <p className="font-medium text-slate-200">This file could not be opened</p>
              <p className="max-w-md text-sm text-slate-400">{pdfFileError}</p>
              <button
                type="button"
                onClick={() => router.back()}
                className="mt-1 rounded-lg bg-surface-2 px-4 py-2 text-sm text-slate-200 hover:bg-surface-3"
              >
                Go back
              </button>
            </div>
          ) : (
            <div className="h-full flex items-center justify-center">
              <Loader2 className="w-12 h-12 text-brand-400 animate-spin" />
            </div>
          )}
        </div>

        {/* Sidebar - Bookmarks */}
        {showBookmarks && (
          <div className="w-80 bg-surface-2 border-l border-surface-border overflow-y-auto">
            <div className="p-4 border-b border-surface-border">
              <h3 className="font-semibold text-slate-100 flex items-center gap-2">
                <Bookmark className="w-4 h-4" />
                Bookmarks
              </h3>
            </div>
            <div className="p-4 space-y-2">
              {bookmarks.length === 0 ? (
                <p className="text-sm text-slate-500">No bookmarks yet</p>
              ) : (
                bookmarks.map((bookmark) => (
                  <div
                    key={bookmark.id}
                    className="flex items-center justify-between p-3 bg-surface-3 rounded-lg"
                  >
                    <div>
                      <p className="text-sm font-medium text-slate-200">{bookmark.title}</p>
                      <p className="text-xs text-slate-500">Page {bookmark.page_number}</p>
                    </div>
                    <button
                      onClick={() => handleDeleteBookmark(bookmark.id)}
                      className="p-1 hover:bg-surface-4 rounded text-slate-500 hover:text-red-400 transition-colors"
                    >
                      ×
                    </button>
                  </div>
                ))
              )}
              <button
                onClick={handleBookmark}
                className="w-full mt-4 px-4 py-2 bg-brand-500 hover:bg-brand-600 text-white rounded-lg text-sm transition-colors"
              >
                Add Bookmark
              </button>
            </div>
          </div>
        )}

        {/* Sidebar - Highlights */}
        {showHighlights && (
          <div className="w-80 bg-surface-2 border-l border-surface-border overflow-y-auto">
            <div className="p-4 border-b border-surface-border">
              <h3 className="font-semibold text-slate-100 flex items-center gap-2">
                <Highlighter className="w-4 h-4" />
                Highlights
              </h3>
            </div>
            <div className="p-4 space-y-2">
              {highlights.length === 0 ? (
                <p className="text-sm text-slate-500">No highlights yet</p>
              ) : (
                highlights.map((highlight) => (
                  <div
                    key={highlight.id}
                    className="p-3 bg-surface-3 rounded-lg border-l-2"
                    style={{ borderColor: highlight.color }}
                  >
                    <p className="text-sm text-slate-300 mb-1">{highlight.selected_text}</p>
                    <p className="text-xs text-slate-500">Page {highlight.page_number}</p>
                  </div>
                ))
              )}
            </div>
          </div>
        )}

        {/* Sidebar - Notes */}
        {showNotes && (
          <div className="w-80 bg-surface-2 border-l border-surface-border overflow-y-auto">
            <div className="p-4 border-b border-surface-border">
              <h3 className="font-semibold text-slate-100 flex items-center gap-2">
                <MessageSquare className="w-4 h-4" />
                Notes
              </h3>
            </div>
            <div className="p-4 space-y-2">
              {noteError && (
                <p className="text-xs text-red-300">{noteError}</p>
              )}
              {notes.length === 0 ? (
                <p className="text-sm text-slate-500">No notes yet. Notes are private to you and saved per page (press Enter to save).</p>
              ) : (
                notes.map((note) => (
                  <div
                    key={note.id}
                    className="p-3 bg-surface-3 rounded-lg"
                  >
                    <p className="text-sm text-slate-300 mb-2">{note.content}</p>
                    <div className="flex items-center justify-between">
                      <p className="text-xs text-slate-500">Page {note.page_number}</p>
                      <button
                        onClick={() => handleDeleteNote(note.id)}
                        className="p-1 hover:bg-surface-4 rounded text-slate-500 hover:text-red-400 transition-colors"
                      >
                        ×
                      </button>
                    </div>
                  </div>
                ))
              )}
              <textarea
                placeholder="Add a note..."
                className="w-full mt-4 px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 placeholder-slate-500 focus:outline-none focus:border-brand-500 resize-none"
                rows={3}
                onKeyDown={async (e) => {
                  if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    const value = e.currentTarget.value;
                     await handleCreateNote(value);
                     if (!noteError) {
                       e.currentTarget.value = '';
                     }
                     return;
                  }
                }}
              />
            </div>
          </div>
        )}

        {/* Sidebar - AI Learning Companion */}
        {showAI && (
          <div className={`bg-surface-2 border-l border-surface-border flex flex-col overflow-hidden ${
            showAI ? 'fixed inset-0 z-50 md:relative md:w-96' : 'hidden'
          }`}>
            <div className="p-4 border-b border-surface-border flex items-center justify-between">
              <h3 className="font-semibold text-slate-100 flex items-center gap-2">
                <Sparkles className="w-4 h-4" />
                AI Learning Companion
              </h3>
              <div className="flex gap-2">
                <button
                  onClick={() => setShowLearningNotes(!showLearningNotes)}
                  className={`p-1.5 rounded transition-colors ${showLearningNotes ? 'bg-brand-500/20 text-brand-400' : 'hover:bg-surface-3 text-slate-400'}`}
                  title="Learning Notes"
                >
                  <Edit3 className="w-4 h-4" />
                </button>
                <button
                  onClick={startNewConversation}
                  className="p-1.5 hover:bg-surface-3 rounded text-slate-400 transition-colors"
                  title="New Conversation"
                >
                  +
                </button>
                <button
                  onClick={() => setShowAI(false)}
                  className="md:hidden p-1.5 hover:bg-surface-3 rounded text-slate-400 transition-colors"
                  title="Close"
                >
                  <X className="w-4 h-4" />
                </button>
              </div>
            </div>

            {!showLearningNotes ? (
              <>
                {/* Conversation List or Chat */}
                {!currentConversation ? (
                  <div className="flex-1 overflow-y-auto p-4">
                    <h4 className="text-sm font-medium text-slate-400 mb-3">Conversations</h4>
                    {conversations.length === 0 ? (
                      <p className="text-sm text-slate-500">No conversations yet</p>
                    ) : (
                      <div className="space-y-2">
                        {conversations.map((conv) => (
                          <button
                            key={conv.id}
                            onClick={() => loadConversationMessages(conv.id)}
                            className="w-full text-left p-3 bg-surface-3 hover:bg-surface-4 rounded-lg transition-colors"
                          >
                            <p className="text-sm font-medium text-slate-200 truncate">{conv.title}</p>
                            <p className="text-xs text-slate-500">{conv.message_count} messages</p>
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                ) : (
                  <div className="flex-1 flex flex-col overflow-hidden">
                    {/* Messages */}
                    <div className="flex-1 overflow-y-auto p-4 space-y-4">
                      {aiError && (
                        <div className="rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-xs text-red-300">
                          {aiError}
                        </div>
                      )}
                      {messages.map((msg) => (
                        <div
                          key={msg.id}
                          className={`p-3 rounded-lg ${msg.role === 'user' ? 'bg-brand-500/20 ml-8' : 'bg-surface-3 mr-8'}`}
                        >
                          <p className="text-sm text-slate-200 whitespace-pre-wrap">{msg.content}</p>
                          {msg.citations && msg.citations.length > 0 && (
                            <div className="mt-2 pt-2 border-t border-surface-border">
                              <p className="text-xs text-slate-500 mb-1">References:</p>
                              {msg.citations.map((citation, idx) => (
                                <button
                                  key={idx}
                                  onClick={() => handlePageChange(citation.page_number)}
                                  className="block text-xs text-brand-400 hover:text-brand-300 mb-1 text-left"
                                >
                                  Page {citation.page_number}
                                  {citation.chapter && `, ${citation.chapter}`}
                                  {citation.heading && ` - ${citation.heading}`}
                                </button>
                              ))}
                            </div>
                          )}
                          {msg.role === 'assistant' && msg.status === 'completed' && (
                            <div className="mt-2 flex gap-2 flex-wrap">
                              <button
                                onClick={() => handleSaveExplanation(msg.id, msg.content)}
                                disabled={savedExplanationIds.has(msg.id)}
                                className="text-xs flex items-center gap-1 text-slate-400 hover:text-brand-400 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                              >
                                <Save className="w-3 h-3" />
                                {savedExplanationIds.has(msg.id) ? 'Saved' : 'Save explanation'}
                              </button>
                              <button
                                onClick={() => {
                                  // Continue discussing - just focus the chat input
                                  const chatInputEl = document.querySelector('input[placeholder="Ask about this book..."]') as HTMLInputElement;
                                  if (chatInputEl) {
                                    chatInputEl.focus();
                                  }
                                }}
                                className="text-xs flex items-center gap-1 text-slate-400 hover:text-brand-400 transition-colors"
                              >
                                <MessageSquare className="w-3 h-3" />
                                Continue discussing
                              </button>
                              <button
                                onClick={() => handleWriteUnderstanding(msg.id, msg.content)}
                                className="text-xs flex items-center gap-1 text-slate-400 hover:text-brand-400 transition-colors"
                              >
                                <Edit3 className="w-3 h-3" />
                                Write my understanding
                              </button>
                            </div>
                          )}
                          {msg.status === 'failed' && (
                            <div className="mt-2 flex gap-2 items-center">
                              <p className="text-xs text-red-400">{msg.error_message}</p>
                              <button
                                onClick={() => handleRetryMessage(msg.id)}
                                className="text-xs flex items-center gap-1 text-slate-400 hover:text-brand-400 transition-colors"
                                disabled={isChatLoading}
                              >
                                <RefreshCw className="w-3 h-3" />
                                Retry
                              </button>
                            </div>
                          )}
                        </div>
                      ))}
                      {isChatLoading && (
                        <div className="p-3 bg-surface-3 rounded-lg mr-8">
                          <div className="flex items-center gap-2">
                            <Loader2 className="w-4 h-4 text-brand-400 animate-spin" />
                            <p className="text-sm text-slate-400">Thinking...</p>
                          </div>
                        </div>
                      )}
                    </div>

                  </div>
                )}
                    {/* Chat Input */}
                    <div className="p-4 border-t border-surface-border">
                      {selectedText && (
                        <div className="mb-2 p-2 bg-surface-3 rounded text-xs text-slate-400">
                          Selected: "{selectedText.substring(0, 60)}..."
                          <button
                            onClick={() => setSelectedText(null)}
                            className="ml-2 text-slate-500 hover:text-slate-300"
                          >
                            ×
                          </button>
                        </div>
                      )}
                      <div className="flex gap-2">
                        <input
                          type="text"
                          value={chatInput}
                          onChange={(e) => setChatInput(e.target.value)}
                          onKeyDown={(e) => e.key === 'Enter' && handleSendMessage()}
                          placeholder="Ask about this book..."
                          className="flex-1 px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 placeholder-slate-500 focus:outline-none focus:border-brand-500"
                        />
                        <button
                          onClick={handleSendMessage}
                          disabled={!chatInput.trim() || isChatLoading}
                          className="p-2 bg-brand-500 hover:bg-brand-600 disabled:bg-surface-4 disabled:text-slate-500 text-white rounded-lg transition-colors"
                        >
                          <Send className="w-4 h-4 text-white" />
                        </button>
                      </div>
                      <div className="mt-2 flex gap-2">
                        <button
                          onClick={() => setSelectedText('Ask about selected text')}
                          className="text-xs px-2 py-1 bg-surface-3 hover:bg-surface-4 rounded text-slate-400 transition-colors"
                        >
                          Ask about selected text
                        </button>
                        <button
                          onClick={() => {
                            setChatInput(`Tell me about page ${currentPage}`);
                            setSelectedText(null);
                          }}
                          className="text-xs px-2 py-1 bg-surface-3 hover:bg-surface-4 rounded text-slate-400 transition-colors"
                        >
                          Discuss this page
                        </button>
                      </div>
                    </div>

              </>
            ) : (
              /* Learning Notes */
              <div className="flex-1 overflow-y-auto p-4">
                <div className="mb-4 flex items-center justify-between">
                  <div className="flex gap-2">
                    <button
                      onClick={() => setShowLearningNotes(false)}
                      className="px-3 py-1 text-xs rounded bg-surface-3 text-slate-300 hover:bg-surface-4"
                      title="Back to chat"
                    >
                      Back to chat
                    </button>
                    <button
                      onClick={() => setNotebookView('book')}
                      className={`px-3 py-1 text-xs rounded ${notebookView === 'book' ? 'bg-brand-500 text-white' : 'bg-surface-3 text-slate-400 hover:bg-surface-4'}`}
                    >
                      Book View
                    </button>
                    <button
                      onClick={() => setNotebookView('chapter')}
                      className={`px-3 py-1 text-xs rounded ${notebookView === 'chapter' ? 'bg-brand-500 text-white' : 'bg-surface-3 text-slate-400 hover:bg-surface-4'}`}
                    >
                      Chapter View
                    </button>
                  </div>
                  {notebookView === 'chapter' && (
                    <input
                      type="text"
                      value={selectedChapter || ''}
                      onChange={(e) => setSelectedChapter(e.target.value || null)}
                      placeholder="Chapter name..."
                      className="px-2 py-1 text-xs bg-surface-3 border border-surface-border rounded text-slate-100 placeholder-slate-500 focus:outline-none focus:border-brand-500 w-32"
                    />
                  )}
                </div>
                <div className="mb-4">
                  <h4 className="text-sm font-medium text-slate-400 mb-2">
                    {editingNote ? 'Edit Note' : 'Create Note'}
                    {editingNote && (
                      <button
                        onClick={cancelEditNote}
                        className="ml-2 text-slate-500 hover:text-slate-300"
                      >
                        <X className="w-3 h-3 inline" />
                      </button>
                    )}
                  </h4>
                  <input
                    type="text"
                    value={noteTitle}
                    onChange={(e) => setNoteTitle(e.target.value)}
                    placeholder="Note title..."
                    className="w-full mb-2 px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 placeholder-slate-500 focus:outline-none focus:border-brand-500"
                  />
                  <select
                    value={noteType}
                    onChange={(e) => setNoteType(e.target.value as any)}
                    className="w-full mb-2 px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 focus:outline-none focus:border-brand-500"
                  >
                    <option value="understanding">Understanding</option>
                    <option value="reflection">Reflection</option>
                    <option value="chapter_summary">Chapter Summary</option>
                    <option value="book_summary">Book Summary</option>
                    <option value="important_passage">Important Passage</option>
                    <option value="question">Question</option>
                  </select>
                  <textarea
                    value={noteInput}
                    onChange={(e) => setNoteInput(e.target.value)}
                    placeholder="Write your understanding..."
                    className="w-full px-3 py-2 bg-surface-3 border border-surface-border rounded-lg text-slate-100 placeholder-slate-500 focus:outline-none focus:border-brand-500 resize-none"
                    rows={3}
                  />
                  <button
                    onClick={editingNote ? handleUpdateLearningNote : handleCreateLearningNote}
                    disabled={!noteInput.trim() || !noteTitle.trim() || isCreatingNote}
                    className="w-full mt-2 px-4 py-2 bg-brand-500 hover:bg-brand-600 disabled:bg-surface-4 disabled:text-slate-500 text-white rounded-lg text-sm transition-colors"
                  >
                    {isCreatingNote ? 'Saving...' : editingNote ? 'Update Note' : 'Save Note'}
                  </button>
                </div>

                <h4 className="text-sm font-medium text-slate-400 mb-2">My Learning Notes</h4>
                {learningNotes.length === 0 ? (
                  <p className="text-sm text-slate-500">No learning notes yet</p>
                ) : (
                  <div className="space-y-2">
                    {learningNotes.map((note) => (
                      <div
                        key={note.id}
                        className="p-3 bg-surface-3 rounded-lg border-l-2 border-brand-500"
                      >
                        <div className="flex items-start justify-between">
                          <div className="flex-1">
                            <p className="text-xs font-medium text-brand-400 capitalize">{note.note_type.replace('_', ' ')}</p>
                            <p className="text-sm font-medium text-slate-200 mt-1">{note.title}</p>
                            <p className="text-sm text-slate-300 mt-1">{note.content}</p>
                            <div className="flex gap-2 mt-2">
                              {note.page_number && (
                                <p className="text-xs text-slate-500">Page {note.page_number}</p>
                              )}
                              {note.chapter && (
                                <p className="text-xs text-slate-500">Chapter: {note.chapter}</p>
                              )}
                            </div>
                          </div>
                          <div className="flex gap-1">
                            <button
                              onClick={() => startEditNote(note)}
                              className="p-1 hover:bg-surface-4 rounded text-slate-500 hover:text-brand-400 transition-colors"
                              title="Edit note"
                            >
                              <Edit3 className="w-3 h-3" />
                            </button>
                            <button
                              onClick={() => handleDeleteLearningNote(note.id)}
                              className="p-1 hover:bg-surface-4 rounded text-slate-500 hover:text-red-400 transition-colors"
                              title="Delete note"
                            >
                              <X className="w-3 h-3" />
                            </button>
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
