'use client';
import { useState, useRef, useEffect } from 'react';
import Link from 'next/link';
import { Languages, Mic, MessageSquare, Volume2, Copy, History, Book, ArrowRight, Play, Loader2, Square, RotateCcw, Trash2, StopCircle, Bookmark, BookmarkCheck, X } from 'lucide-react';
import { translationApi, transcribeApi, synthesisApi, type TranslationRequest } from '@/lib/api';
import { savePhrase, getSavedPhrases, deletePhrase, updatePhraseWithAudio, type SavedPhrase } from '@/lib/indexedDB';

type LanguageCapability = 'translation' | 'stt' | 'tts';

interface Language {
  code: string;
  name: string;
  nativeName?: string;
  capabilities: LanguageCapability[];
}

const LANGUAGES: Language[] = [
  { code: 'eng', name: 'English', nativeName: 'English', capabilities: ['translation', 'stt', 'tts'] },
  { code: 'lug', name: 'Luganda', nativeName: 'Luganda', capabilities: ['translation', 'stt', 'tts'] },
  { code: 'ach', name: 'Acholi', nativeName: 'Acholi', capabilities: ['translation', 'stt', 'tts'] },
  { code: 'swa', name: 'Swahili', nativeName: 'Kiswahili', capabilities: ['translation', 'stt', 'tts'] },
  { code: 'nyn', name: 'Runyankole', nativeName: 'Runyankole', capabilities: ['translation', 'stt', 'tts'] },
  { code: 'laj', name: 'Lango', nativeName: 'Lango', capabilities: ['translation'] }, // STT and TTS not supported by Sunbird
];

type Mode = 'text' | 'conversation';

interface ConversationTurn {
  id: string;
  direction: 'source-to-target' | 'target-to-source';
  originalText: string;
  translatedText: string;
  timestamp: Date;
}

interface TranslationTurn {
  id: string;
  sourceText: string;
  translatedText: string;
  sourceLanguage: string;
  targetLanguage: string;
  timestamp: Date;
}

type ConversationState = 'idle' | 'listening' | 'transcribing' | 'translating' | 'synthesizing' | 'speaking' | 'error';
type ConversationInputMode = 'automatic' | 'tap-to-speak';

export default function TranslatorPage() {
  const [mode, setMode] = useState<Mode>('text');
  const [sourceLang, setSourceLang] = useState('eng');
  const [targetLang, setTargetLang] = useState('lug');
  const [sourceText, setSourceText] = useState('');
  const [translatedText, setTranslatedText] = useState('');
  const [isRecording, setIsRecording] = useState(false);
  const [isSpeaking, setIsSpeaking] = useState(false);
  const [isTranslating, setIsTranslating] = useState(false);
  const [isTranscribing, setIsTranscribing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showLanguageSelector, setShowLanguageSelector] = useState<'source' | 'target' | null>(null);
  const [recordingTime, setRecordingTime] = useState(0);
  const [audioBlob, setAudioBlob] = useState<Blob | null>(null);
  const [conversationHistory, setConversationHistory] = useState<ConversationTurn[]>([]);
  const [conversationState, setConversationState] = useState<ConversationState>('idle');
  const [currentSpeaker, setCurrentSpeaker] = useState<'source' | 'target' | null>(null);
  const [conversationInputMode, setConversationInputMode] = useState<ConversationInputMode>('automatic');
  const [savedPhrases, setSavedPhrases] = useState<SavedPhrase[]>([]);
  const [showSavedPhrases, setShowSavedPhrases] = useState(false);
  const [isLoadingSaved, setIsLoadingSaved] = useState(false);
  const [lastAudioUrl, setLastAudioUrl] = useState<string | null>(null);
  const [isSavingAudio, setIsSavingAudio] = useState(false);
  const [lastSavedPhraseId, setLastSavedPhraseId] = useState<string | null>(null);
  const [translationHistory, setTranslationHistory] = useState<TranslationTurn[]>([]);
  const [lastConversationAudioUrl, setLastConversationAudioUrl] = useState<string | null>(null);
  const [lastConversationTurn, setLastConversationTurn] = useState<ConversationTurn | null>(null);
  const [savedPhrasesTab, setSavedPhrasesTab] = useState<'all' | 'audio' | 'text'>('all');

  const mediaRecorderRef = useRef<MediaRecorder | null>(null);
  const audioChunksRef = useRef<Blob[]>([]);
  const recordingIntervalRef = useRef<NodeJS.Timeout | null>(null);
  const audioContextRef = useRef<AudioContext | null>(null);
  const analyserRef = useRef<AnalyserNode | null>(null);
  const vadIntervalRef = useRef<NodeJS.Timeout | null>(null);
  const silenceStartRef = useRef<number | null>(null);
  const audioElementRef = useRef<HTMLAudioElement | null>(null);
  const conversationInputModeRef = useRef<ConversationInputMode>('automatic'); // Ref to avoid stale closures
  const visualizerCanvasRef = useRef<HTMLCanvasElement | null>(null);
  const visualizerAnimationRef = useRef<number | null>(null);

  const selectedSource = LANGUAGES.find(l => l.code === sourceLang);
  const selectedTarget = LANGUAGES.find(l => l.code === targetLang);

  const hasCapability = (lang: Language | undefined, capability: LanguageCapability) =>
    lang?.capabilities.includes(capability) ?? false;

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      if (recordingIntervalRef.current) clearInterval(recordingIntervalRef.current);
      if (vadIntervalRef.current) clearInterval(vadIntervalRef.current);
      if (visualizerAnimationRef.current) cancelAnimationFrame(visualizerAnimationRef.current);
      if (audioContextRef.current) audioContextRef.current.close();
      if (audioElementRef.current) {
        audioElementRef.current.pause();
        audioElementRef.current = null;
      }
    };
  }, []);

  // Sync conversation input mode ref with state when toggle changes
  useEffect(() => {
    conversationInputModeRef.current = conversationInputMode;
  }, [conversationInputMode]);

  // Load saved phrases on mount
  useEffect(() => {
    const loadSavedPhrases = async () => {
      setIsLoadingSaved(true);
      try {
        const phrases = await getSavedPhrases();
        setSavedPhrases(phrases);
      } catch (error) {
        console.error('Failed to load saved phrases:', error);
      } finally {
        setIsLoadingSaved(false);
      }
    };
    loadSavedPhrases();
  }, []);

  // Simple VAD using audio level detection
  const setupVAD = (stream: MediaStream) => {
    if (!audioContextRef.current) {
      audioContextRef.current = new (window.AudioContext || (window as any).webkitAudioContext)();
    }

    const audioContext = audioContextRef.current;
    const source = audioContext.createMediaStreamSource(stream);
    const analyser = audioContext.createAnalyser();
    analyser.fftSize = 256;
    source.connect(analyser);

    analyserRef.current = analyser;
    const dataArray = new Uint8Array(analyser.frequencyBinCount);

    const SILENCE_THRESHOLD = 3; // Lower threshold to account for ambient noise around 5-6
    const SILENCE_DURATION_MS = 3000; // 3 seconds of silence to stop (increased for conversation mode)

    vadIntervalRef.current = setInterval(() => {
      analyser.getByteFrequencyData(dataArray);
      const average = dataArray.reduce((a, b) => a + b) / dataArray.length;

      console.log('[VAD] Audio level:', average.toFixed(2), 'Silence threshold:', SILENCE_THRESHOLD);

      if (average < SILENCE_THRESHOLD) {
        if (!silenceStartRef.current) {
          silenceStartRef.current = Date.now();
          console.log('[VAD] Silence started');
        } else if (Date.now() - silenceStartRef.current > SILENCE_DURATION_MS) {
          console.log('[VAD] Silence duration exceeded, stopping recording');
          // Silence detected for long enough, stop recording (Automatic mode only)
          stopAutomaticRecording();
        }
      } else {
        silenceStartRef.current = null;
      }
    }, 100);

    // Setup visualizer
    if (visualizerCanvasRef.current) {
      const canvas = visualizerCanvasRef.current;
      const canvasCtx = canvas.getContext('2d');
      if (canvasCtx) {
        const drawVisualizer = () => {
          visualizerAnimationRef.current = requestAnimationFrame(drawVisualizer);
          analyser.getByteFrequencyData(dataArray);

          // Clear canvas with transparent background
          canvasCtx.clearRect(0, 0, canvas.width, canvas.height);

          const centerX = canvas.width / 2;
          const centerY = canvas.height / 2;
          const radius = 30;
          const barCount = 32;
          const angleStep = (Math.PI * 2) / barCount;

          for (let i = 0; i < barCount; i++) {
            const dataIndex = Math.floor(i * (dataArray.length / barCount));
            const value = dataArray[dataIndex] / 255;
            const barHeight = value * 20;

            const angle = i * angleStep - Math.PI / 2;
            const x1 = centerX + Math.cos(angle) * radius;
            const y1 = centerY + Math.sin(angle) * radius;
            const x2 = centerX + Math.cos(angle) * (radius + barHeight);
            const y2 = centerY + Math.sin(angle) * (radius + barHeight);

            // Use theme-aware colors (will work with CSS variables when theme is implemented)
            canvasCtx.strokeStyle = 'rgba(255, 255, 255, 0.8)';
            canvasCtx.lineWidth = 3;
            canvasCtx.lineCap = 'round';

            canvasCtx.beginPath();
            canvasCtx.moveTo(x1, y1);
            canvasCtx.lineTo(x2, y2);
            canvasCtx.stroke();
          }
        };

        drawVisualizer();
      }
    }
  };

  // Automatic Conversation recording functions (with VAD)
  const startAutomaticRecording = async (speaker: 'source' | 'target') => {
    const recordLang = speaker === 'source' ? sourceLang : targetLang;
    const langObj = LANGUAGES.find(l => l.code === recordLang);

    if (!hasCapability(langObj, 'stt')) {
      alert(`Voice input is not supported for ${langObj?.name}.`);
      return;
    }

    const recordingStart = Date.now();
    console.log(`[PERF] Automatic recording started at ${new Date().toISOString()}`);

    setCurrentSpeaker(speaker);
    setConversationState('listening');
    setError(null);

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      audioChunksRef.current = [];

      const mediaRecorder = new MediaRecorder(stream, {
        mimeType: 'audio/webm',
      });

      mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) {
          audioChunksRef.current.push(event.data);
        }
      };

      mediaRecorder.onstop = async () => {
        const recordingDuration = (Date.now() - recordingStart) / 1000;
        console.log(`[PERF] Automatic recording stopped after ${recordingDuration.toFixed(2)}s`);

        const blob = new Blob(audioChunksRef.current, { type: 'audio/webm' });
        const audioFinalized = Date.now();
        console.log(`[PERF] Audio blob finalized at ${audioFinalized - recordingStart}ms from start`);
        stream.getTracks().forEach(track => track.stop());

        if (vadIntervalRef.current) {
          clearInterval(vadIntervalRef.current);
          vadIntervalRef.current = null;
        }

        setIsRecording(false);
        // Pass blob directly to avoid state timing issues
        await processConversationTurn(speaker, blob);
      };

      mediaRecorderRef.current = mediaRecorder;
      mediaRecorder.start();
      setIsRecording(true);
      setRecordingTime(0);

      if (recordingIntervalRef.current) {
        clearInterval(recordingIntervalRef.current);
      }
      recordingIntervalRef.current = setInterval(() => {
        setRecordingTime(prev => prev + 1);
      }, 1000);

      setupVAD(stream);

    } catch (err) {
      console.error('Microphone access error:', err);
      setError('Could not access microphone. Please check permissions.');
      setConversationState('error');
    }
  };

  const stopAutomaticRecording = () => {
    if (mediaRecorderRef.current && mediaRecorderRef.current.state === 'recording') {
      mediaRecorderRef.current.stop();
    }
    if (vadIntervalRef.current) {
      clearInterval(vadIntervalRef.current);
      vadIntervalRef.current = null;
    }
  };

  // Tap to Speak recording functions (no VAD, manual stop)
  const startTapToSpeakRecording = async (speaker: 'source' | 'target') => {
    const recordLang = speaker === 'source' ? sourceLang : targetLang;
    const langObj = LANGUAGES.find(l => l.code === recordLang);

    if (!hasCapability(langObj, 'stt')) {
      alert(`Voice input is not supported for ${langObj?.name}.`);
      return;
    }

    const recordingStart = Date.now();
    console.log(`[PERF] Tap-to-speak recording started at ${new Date().toISOString()}`);

    setCurrentSpeaker(speaker);
    setConversationState('listening');
    setError(null);

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      audioChunksRef.current = [];

      const mediaRecorder = new MediaRecorder(stream, {
        mimeType: 'audio/webm',
      });

      mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) {
          audioChunksRef.current.push(event.data);
        }
      };

      mediaRecorder.onstop = async () => {
        const recordingDuration = (Date.now() - recordingStart) / 1000;
        console.log(`[PERF] Tap-to-speak recording stopped after ${recordingDuration.toFixed(2)}s`);

        const blob = new Blob(audioChunksRef.current, { type: 'audio/webm' });
        const audioFinalized = Date.now();
        console.log(`[PERF] Audio blob finalized at ${audioFinalized - recordingStart}ms from start`);
        stream.getTracks().forEach(track => track.stop());

        setIsRecording(false);
        // Pass blob directly to avoid state timing issues
        await processConversationTurn(speaker, blob);
      };

      mediaRecorderRef.current = mediaRecorder;
      mediaRecorder.start();
      setIsRecording(true);
      setRecordingTime(0);

      if (recordingIntervalRef.current) {
        clearInterval(recordingIntervalRef.current);
      }
      recordingIntervalRef.current = setInterval(() => {
        setRecordingTime(prev => prev + 1);
      }, 1000);

      // Setup visualizer for Tap to Speak mode
      const audioContext = new AudioContext();
      const source = audioContext.createMediaStreamSource(stream);
      const analyser = audioContext.createAnalyser();
      analyser.fftSize = 256;
      source.connect(analyser);

      const dataArray = new Uint8Array(analyser.frequencyBinCount);

      // Setup visualizer
      if (visualizerCanvasRef.current) {
        const canvas = visualizerCanvasRef.current;
        const canvasCtx = canvas.getContext('2d');
        if (canvasCtx) {
          const drawVisualizer = () => {
            visualizerAnimationRef.current = requestAnimationFrame(drawVisualizer);
            analyser.getByteFrequencyData(dataArray);

            // Clear canvas with transparent background
            canvasCtx.clearRect(0, 0, canvas.width, canvas.height);

            const centerX = canvas.width / 2;
            const centerY = canvas.height / 2;
            const radius = 30;
            const barCount = 32;
            const angleStep = (Math.PI * 2) / barCount;

            for (let i = 0; i < barCount; i++) {
              const dataIndex = Math.floor(i * (dataArray.length / barCount));
              const value = dataArray[dataIndex] / 255;
              const barHeight = value * 20;

              const angle = i * angleStep - Math.PI / 2;
              const x1 = centerX + Math.cos(angle) * radius;
              const y1 = centerY + Math.sin(angle) * radius;
              const x2 = centerX + Math.cos(angle) * (radius + barHeight);
              const y2 = centerY + Math.sin(angle) * (radius + barHeight);

              // Use theme-aware colors
              canvasCtx.strokeStyle = 'rgba(255, 255, 255, 0.8)';
              canvasCtx.lineWidth = 3;
              canvasCtx.lineCap = 'round';

              canvasCtx.beginPath();
              canvasCtx.moveTo(x1, y1);
              canvasCtx.lineTo(x2, y2);
              canvasCtx.stroke();
            }
          };

          drawVisualizer();
        }
      }

    } catch (err) {
      console.error('Microphone access error:', err);
      setError('Could not access microphone. Please check permissions.');
      setConversationState('error');
    }
  };

  const handleSavePhrase = async () => {
    if (!sourceText.trim() || !translatedText.trim()) {
      return;
    }
    try {
      const saved = await savePhrase({
        sourceText,
        translatedText,
        sourceLanguage: sourceLang,
        targetLanguage: targetLang,
      });
      setSavedPhrases(prev => [saved, ...prev]);
      setLastSavedPhraseId(saved.id);
    } catch (error) {
      console.error('Failed to save phrase:', error);
    }
  };

  const handleDeletePhrase = async (id: string) => {
    try {
      await deletePhrase(id);
      setSavedPhrases(prev => prev.filter(p => p.id !== id));
    } catch (error) {
      console.error('Failed to delete phrase:', error);
    }
  };

  const playAudioFromBlob = async (blob: Blob, mimeType: string): Promise<void> => {
    return new Promise((resolve, reject) => {
      if (audioElementRef.current) {
        audioElementRef.current.pause();
        audioElementRef.current = null;
      }

      const audioUrl = URL.createObjectURL(blob);
      const audio = new Audio(audioUrl);
      audioElementRef.current = audio;

      audio.onended = () => {
        URL.revokeObjectURL(audioUrl);
        audioElementRef.current = null;
        resolve();
      };

      audio.onerror = (e) => {
        URL.revokeObjectURL(audioUrl);
        audioElementRef.current = null;
        reject(new Error('Failed to play audio'));
      };

      audio.play().catch(reject);
    });
  };

  const handleSaveAudio = async (phraseId: string) => {
    if (!lastAudioUrl) {
      setError('No audio to save. Generate audio first.');
      return;
    }

    setIsSavingAudio(true);
    try {
      const response = await fetch(lastAudioUrl);
      if (!response.ok) throw new Error('Failed to fetch audio');
      const blob = await response.blob();
      const mimeType = response.headers.get('content-type') || 'audio/mpeg';
      await updatePhraseWithAudio(phraseId, blob, mimeType);
      setSavedPhrases(prev => prev.map(p => 
        p.id === phraseId ? { ...p, audioBlob: blob, audioMimeType: mimeType } : p
      ));
    } catch (error) {
      console.error('Failed to save audio:', error);
      setError('Failed to save audio. Please try again.');
    } finally {
      setIsSavingAudio(false);
    }
  };

  const stopTapToSpeakRecording = () => {
    if (mediaRecorderRef.current && mediaRecorderRef.current.state === 'recording') {
      mediaRecorderRef.current.stop();
    }
  };

  const processConversationTurn = async (speaker: 'source' | 'target', blob: Blob) => {
    if (!blob) {
      console.error('[Conversation] No audio blob available');
      setConversationState('error');
      setError('No audio recording found. Please try again.');
      return;
    }

    const recordLang = speaker === 'source' ? sourceLang : targetLang;
    const translateFrom = speaker === 'source' ? sourceLang : targetLang;
    const translateTo = speaker === 'source' ? targetLang : sourceLang;

    setConversationState('transcribing');
    setError(null);

    const pipelineStart = Date.now();
    const transcriptionStart = Date.now();
    let transcriptionDuration = 0;
    let translationDuration = 0;
    let synthesisDuration = 0;
    let totalToTextDisplay = 0;

    try {
      console.log(`[PERF] Pipeline started at ${new Date().toISOString()}`);
      console.log(`[Conversation] Processing turn for speaker: ${speaker}, language: ${recordLang}`);

      // STT
      console.log(`[PERF] STT request started at ${Date.now() - pipelineStart}ms`);
      const file = new File([blob], 'recording.webm', { type: 'audio/webm' });
      console.log(`[Conversation] Audio blob size: ${blob.size} bytes`);

      const transcriptionResponse = await transcribeApi.transcribe({ audio: file, language: recordLang });
      transcriptionDuration = (Date.now() - transcriptionStart) / 1000;
      const transcribedText = transcriptionResponse.transcription;

      console.log(`[PERF] STT completed in ${transcriptionDuration.toFixed(2)}s (${(Date.now() - pipelineStart)}ms total)`);
      console.log(`[Conversation] Transcribed text: "${transcribedText}"`);

      // Handle empty transcript
      if (!transcribedText || transcribedText.trim().length === 0) {
        console.log('[Conversation] Empty transcript detected, skipping translation');
        setConversationState('idle');
        setAudioBlob(null);
        setRecordingTime(0);
        
        // Resume listening if in automatic mode
        if (conversationInputModeRef.current === 'automatic') {
          setCurrentSpeaker(speaker === 'source' ? 'target' : 'source');
        } else {
          setCurrentSpeaker(null);
        }
        return;
      }

      // Translation (use auto-detection by omitting source_language)
      setConversationState('translating');
      console.log(`[PERF] Translation request started at ${Date.now() - pipelineStart}ms`);
      const translationStart = Date.now();
      const translationResponse = await translationApi.translate({
        text: transcribedText,
        target_language: translateTo,
      });
      translationDuration = (Date.now() - translationStart) / 1000;

      console.log(`[PERF] Translation completed in ${translationDuration.toFixed(2)}s (${(Date.now() - pipelineStart)}ms total)`);

      // Display translation text immediately
      const turn: ConversationTurn = {
        id: Date.now().toString(),
        direction: speaker === 'source' ? 'source-to-target' : 'target-to-source',
        originalText: transcribedText,
        translatedText: translationResponse.translated_text,
        timestamp: new Date(),
      };

      totalToTextDisplay = (Date.now() - pipelineStart) / 1000;
      console.log(`[PERF] Translation text displayed in ${totalToTextDisplay.toFixed(2)}s`);

      setConversationHistory(prev => [...prev, turn]);
      setAudioBlob(null);
      setRecordingTime(0);

      // TTS synthesis (after text is displayed)
      setConversationState('synthesizing');
      console.log(`[PERF] TTS synthesis started at ${Date.now() - pipelineStart}ms`);
      const synthesisStart = Date.now();
      const synthesisResponse = await synthesisApi.synthesize({
        text: translationResponse.translated_text,
        language: translateTo,
      });
      synthesisDuration = (Date.now() - synthesisStart) / 1000;

      console.log(`[PERF] TTS synthesis completed in ${synthesisDuration.toFixed(2)}s (${(Date.now() - pipelineStart)}ms total)`);

      // Save audio URL for saving later
      setLastConversationAudioUrl(synthesisResponse.audio_url);
      setLastConversationTurn(turn);

      // Play audio
      setConversationState('speaking');
      await playAudio(synthesisResponse.audio_url);

      const totalPipelineTime = (Date.now() - pipelineStart) / 1000;
      console.log(`[PERF] Total pipeline completed in ${totalPipelineTime.toFixed(2)}s`);
      console.log(`[PERF] Breakdown - STT: ${transcriptionDuration.toFixed(2)}s, Translation: ${translationDuration.toFixed(2)}s, TTS: ${synthesisDuration.toFixed(2)}s`);

      // Resume listening after playback only if still in automatic mode
      if (conversationInputModeRef.current === 'automatic') {
        setConversationState('idle');
        setCurrentSpeaker(speaker === 'source' ? 'target' : 'source'); // Switch speaker
      } else {
        // Automatic mode was turned off during processing
        setConversationState('idle');
        setCurrentSpeaker(null);
      }

    } catch (err: any) {
      console.error('Continuous conversation error:', err);
      const errorMessage = err?.message || err?.error || err?.errors?.error || 'Conversation failed. Please try again.';
      setError(errorMessage);
      setConversationState('error');
    }
  };

  const playAudio = async (audioUrl: string): Promise<void> => {
    return new Promise((resolve, reject) => {
      if (audioElementRef.current) {
        audioElementRef.current.pause();
        audioElementRef.current = null;
      }

      const audio = new Audio(audioUrl);
      audioElementRef.current = audio;

      audio.onended = () => {
        audioElementRef.current = null;
        resolve();
      };

      audio.onerror = (e) => {
        console.error('Audio playback error:', e);
        audioElementRef.current = null;
        reject(new Error('Failed to play audio'));
      };

      audio.play().catch(reject);
    });
  };

  const handleSpeakTranslation = async () => {
    if (!translatedText) return;

    const targetLangCode = targetLang;
    setIsSpeaking(true);
    setError(null);

    try {
      const synthesisResponse = await synthesisApi.synthesize({
        text: translatedText,
        language: targetLangCode,
      });

      setLastAudioUrl(synthesisResponse.audio_url);
      await playAudio(synthesisResponse.audio_url);
    } catch (err: any) {
      console.error('TTS error:', err);
      setError(err.message || 'Failed to play audio. Please try again.');
    } finally {
      setIsSpeaking(false);
    }
  };

  const handleSpeak = async (text: string, language: string) => {
    setIsSpeaking(true);
    setError(null);

    try {
      const synthesisResponse = await synthesisApi.synthesize({
        text,
        language,
      });

      await playAudio(synthesisResponse.audio_url);
    } catch (err: any) {
      console.error('TTS error:', err);
      setError(err.message || 'Failed to play audio. Please try again.');
    } finally {
      setIsSpeaking(false);
    }
  };

  const handleSavePhraseForHistory = async (turn: TranslationTurn) => {
    try {
      const phraseId = await savePhrase({
        sourceText: turn.sourceText,
        translatedText: turn.translatedText,
        sourceLanguage: turn.sourceLanguage,
        targetLanguage: turn.targetLanguage,
      });
      setLastSavedPhraseId(phraseId.id as string);
    } catch (err: any) {
      console.error('Failed to save phrase:', err);
      setError(err.message || 'Failed to save phrase');
    }
  };

  const handleSaveConversationAudio = async (turn: ConversationTurn) => {
    if (!lastConversationAudioUrl) {
      setError('No audio to save.');
      return;
    }

    setIsSavingAudio(true);
    try {
      const response = await fetch(lastConversationAudioUrl);
      if (!response.ok) throw new Error('Failed to fetch audio');
      const blob = await response.blob();
      const mimeType = response.headers.get('content-type') || 'audio/mpeg';
      
      const turnSourceLang = turn.direction === 'source-to-target' ? sourceLang : targetLang;
      const turnTargetLang = turn.direction === 'source-to-target' ? targetLang : sourceLang;
      
      const phraseId = await savePhrase({
        sourceText: turn.originalText,
        translatedText: turn.translatedText,
        sourceLanguage: turnSourceLang,
        targetLanguage: turnTargetLang,
      });
      
      await updatePhraseWithAudio(phraseId.id as string, blob, mimeType);
      setSavedPhrases(prev => prev.map(p => 
        p.id === phraseId.id ? { ...p, audioBlob: blob, audioMimeType: mimeType } : p
      ));
      setLastSavedPhraseId(phraseId.id as string);
    } catch (error) {
      console.error('Failed to save audio:', error);
      setError('Failed to save audio. Please try again.');
    } finally {
      setIsSavingAudio(false);
    }
  };

  const handleTranslate = async () => {
    if (!sourceText.trim()) return;

    setIsTranslating(true);
    setError(null);

    try {
      const request: TranslationRequest = {
        text: sourceText,
        target_language: targetLang,
      };

      if (sourceLang) {
        request.source_language = sourceLang;
      }

      const response = await translationApi.translate(request);

      // Add to translation history
      const newTurn: TranslationTurn = {
        id: Date.now().toString(),
        sourceText: sourceText,
        translatedText: response.translated_text,
        sourceLanguage: sourceLang,
        targetLanguage: targetLang,
        timestamp: new Date(),
      };

      setTranslationHistory(prev => [...prev, newTurn]);
      setSourceText('');
    } catch (err: any) {
      console.error('Translation error:', err);
      const errorMessage = err?.message || err?.error || err?.errors?.error || 'Translation failed. Please try again.';
      setError(errorMessage);
    } finally {
      setIsTranslating(false);
    }
  };

  const handleSwapLanguages = () => {
    setSourceLang(targetLang);
    setTargetLang(sourceLang);
    setSourceText(translatedText);
    setTranslatedText(sourceText);
    setError(null);
  };

  const startRecording = async () => {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      audioChunksRef.current = [];

      const mediaRecorder = new MediaRecorder(stream, {
        mimeType: 'audio/webm',
      });

      mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) {
          audioChunksRef.current.push(event.data);
        }
      };

      mediaRecorder.onstop = async () => {
        const blob = new Blob(audioChunksRef.current, { type: 'audio/webm' });
        setAudioBlob(blob);
        stream.getTracks().forEach(track => track.stop());
      };

      mediaRecorderRef.current = mediaRecorder;
      mediaRecorder.start();
      setIsRecording(true);
      setRecordingTime(0);

      recordingIntervalRef.current = setInterval(() => {
        setRecordingTime(prev => prev + 1);
      }, 1000);

    } catch (err) {
      console.error('Microphone access error:', err);
      setError('Could not access microphone. Please check permissions.');
    }
  };

  const stopRecording = () => {
    if (mediaRecorderRef.current && isRecording) {
      mediaRecorderRef.current.stop();
      setIsRecording(false);
      if (recordingIntervalRef.current) {
        clearInterval(recordingIntervalRef.current);
      }
    }
  };

  const cancelRecording = () => {
    stopRecording();
    setAudioBlob(null);
    setRecordingTime(0);
  };

  const submitRecording = async (language: string) => {
    if (!audioBlob) return;

    setIsTranscribing(true);
    setError(null);

    try {
      const file = new File([audioBlob], 'recording.webm', { type: 'audio/webm' });
      const response = await transcribeApi.transcribe({ audio: file, language });
      setSourceText(response.transcription);
      setAudioBlob(null);
      setRecordingTime(0);
    } catch (err: any) {
      console.error('Transcription error:', err);
      const errorMessage = err?.message || err?.error || 'Speech recognition failed. Please try again.';
      setError(errorMessage);
    } finally {
      setIsTranscribing(false);
    }
  };

  const handleVoiceInput = async () => {
    if (!hasCapability(selectedSource, 'stt')) {
      alert(`Voice input is not supported for ${selectedSource?.name}.`);
      return;
    }

    if (isRecording) {
      stopRecording();
    } else {
      await startRecording();
    }
  };

  const handleCopyTranslation = () => {
    if (translatedText) {
      navigator.clipboard.writeText(translatedText);
    }
  };

  const formatTime = (seconds: number) => {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}:${secs.toString().padStart(2, '0')}`;
  };

  // Conversation mode handlers
  const handleConversationRecord = async (direction: 'source-to-target' | 'target-to-source') => {
    const recordLang = direction === 'source-to-target' ? sourceLang : targetLang;
    const langObj = LANGUAGES.find(l => l.code === recordLang);

    if (!hasCapability(langObj, 'stt')) {
      alert(`Voice input is not supported for ${langObj?.name}.`);
      return;
    }

    const speaker = direction === 'source-to-target' ? 'source' : 'target';

    // Check if currently recording for this speaker (Tap to Speak mode)
    if (conversationState === 'listening' && currentSpeaker === speaker) {
      // Stop recording
      stopTapToSpeakRecording();
      return;
    }

    // Start recording (Tap to Speak mode)
    await startTapToSpeakRecording(speaker);
  };

  const handleConversationSubmit = async (direction: 'source-to-target' | 'target-to-source') => {
    if (!audioBlob) return;

    const recordLang = direction === 'source-to-target' ? sourceLang : targetLang;
    const translateFrom = direction === 'source-to-target' ? sourceLang : targetLang;
    const translateTo = direction === 'source-to-target' ? targetLang : sourceLang;

    setIsTranscribing(true);
    setError(null);

    const transcriptionStart = Date.now();
    let transcriptionDuration = 0;
    let translationDuration = 0;

    try {
      const file = new File([audioBlob], 'recording.webm', { type: 'audio/webm' });
      const transcriptionResponse = await transcribeApi.transcribe({ audio: file, language: recordLang });
      transcriptionDuration = (Date.now() - transcriptionStart) / 1000;
      const transcribedText = transcriptionResponse.transcription;

      console.log(`[Conversation] Transcription completed in ${transcriptionDuration.toFixed(2)}s`);

      const translationStart = Date.now();
      const translationResponse = await translationApi.translate({
        text: transcribedText,
        source_language: translateFrom,
        target_language: translateTo,
      });
      translationDuration = (Date.now() - translationStart) / 1000;

      console.log(`[Conversation] Translation completed in ${translationDuration.toFixed(2)}s`);
      console.log(`[Conversation] Total pipeline: ${(transcriptionDuration + translationDuration).toFixed(2)}s`);

      const turn: ConversationTurn = {
        id: Date.now().toString(),
        direction,
        originalText: transcribedText,
        translatedText: translationResponse.translated_text,
        timestamp: new Date(),
      };

      setConversationHistory(prev => [...prev, turn]);
      setAudioBlob(null);
      setRecordingTime(0);
    } catch (err: any) {
      console.error('Conversation error:', err);
      const errorMessage = err?.message || err?.error || err?.errors?.error || 'Conversation failed. Please try again.';
      setError(errorMessage);
      console.log(`[Conversation] Failed at stage: ${err?.stage || 'unknown'}`);
    } finally {
      setIsTranscribing(false);
    }
  };

  const clearConversation = () => {
    setConversationHistory([]);
  };

  return (
    <div className="min-h-screen flex flex-col bg-surface">
      {/* Top Controls Bar - Floating, Compact */}
      <div className="fixed top-4 left-1/2 -translate-x-1/2 z-20">
        <div className="flex items-center gap-1 sm:gap-2 bg-surface-2/90 backdrop-blur-xl border border-surface-border/50 rounded-2xl px-2 py-1.5 sm:px-3 sm:py-2 shadow-2xl">
          {/* Mode Toggle - Compact */}
          <div className="flex gap-1 p-1 bg-surface-3/50 rounded-xl">
            <button
              onClick={() => setMode('text')}
              className={`p-1.5 sm:p-2 rounded-lg transition-all ${
                mode === 'text'
                  ? 'bg-brand-500/20 text-brand-400'
                  : 'text-slate-400 hover:text-slate-200'
              }`}
              title="Translate"
            >
              <Languages className="w-3.5 h-3.5 sm:w-4 sm:h-4" />
            </button>
            <button
              onClick={() => setMode('conversation')}
              className={`p-1.5 sm:p-2 rounded-lg transition-all ${
                mode === 'conversation'
                  ? 'bg-brand-500/20 text-brand-400'
                  : 'text-slate-400 hover:text-slate-200'
              }`}
              title="Conversation"
            >
              <MessageSquare className="w-3.5 h-3.5 sm:w-4 sm:h-4" />
            </button>
          </div>

          <div className="w-px h-5 sm:h-6 bg-surface-border/50" />

          {/* Language Selector - Compact */}
          <div className="flex items-center gap-1 sm:gap-2">
            <button
              onClick={() => setShowLanguageSelector('source')}
              className="px-2 py-1 sm:px-3 sm:py-1.5 rounded-lg hover:bg-surface-3/50 transition-colors text-xs sm:text-sm font-medium text-slate-100"
            >
              {selectedSource?.code.toUpperCase()}
            </button>

            <button
              onClick={handleSwapLanguages}
              className="p-1 sm:p-1.5 rounded-full hover:bg-surface-3/50 transition-colors text-brand-400 hover:text-brand-300"
            >
              <ArrowRight className="w-3 h-3 sm:w-4 sm:h-4" />
            </button>

            <button
              onClick={() => setShowLanguageSelector('target')}
              className="px-2 py-1 sm:px-3 sm:py-1.5 rounded-lg hover:bg-surface-3/50 transition-colors text-xs sm:text-sm font-medium text-slate-100"
            >
              {selectedTarget?.code.toUpperCase()}
            </button>
          </div>

          <div className="w-px h-5 sm:h-6 bg-surface-border/50 hidden sm:block" />

          {/* Saved Phrases Button - Hidden on mobile */}
          <button
            onClick={() => setShowSavedPhrases(true)}
            className="hidden sm:block p-2 rounded-lg hover:bg-surface-3/50 transition-colors text-brand-400 hover:text-brand-300"
            title="Saved phrases"
          >
            <Bookmark className="w-4 h-4" />
          </button>
        </div>
      </div>

      {/* Text Mode */}
      {mode === 'text' && (
        <div className="flex-1 flex flex-col pt-24 px-4 max-w-4xl mx-auto w-full">
          {/* Translation History */}
          <div className="flex-1 overflow-y-auto pb-32">
            {translationHistory.length > 0 ? (
              <div className="space-y-4">
                {translationHistory.map((turn) => (
                  <div
                    key={turn.id}
                    className="bg-surface-2 border border-surface-border rounded-2xl p-5 shadow-sm"
                  >
                    <div className="flex items-center justify-between mb-3">
                      <div className="flex items-center gap-2">
                        <span className="text-xs font-medium text-slate-500">
                          {LANGUAGES.find(l => l.code === turn.sourceLanguage)?.name}
                        </span>
                        <ArrowRight className="w-3 h-3 text-slate-500" />
                        <span className="text-xs font-medium text-slate-500">
                          {LANGUAGES.find(l => l.code === turn.targetLanguage)?.name}
                        </span>
                      </div>
                      <div className="flex gap-1">
                        <button
                          onClick={() => {
                            const lang = LANGUAGES.find(l => l.code === turn.targetLanguage);
                            if (lang && hasCapability(lang, 'tts')) {
                              handleSpeak(turn.translatedText, turn.targetLanguage);
                            }
                          }}
                          disabled={!hasCapability(LANGUAGES.find(l => l.code === turn.targetLanguage), 'tts')}
                          className="p-1.5 rounded-lg transition-colors hover:bg-surface-3/50 text-slate-400"
                          title="Listen"
                        >
                          <Volume2 className="w-4 h-4" />
                        </button>
                        <button
                          onClick={() => {
                            navigator.clipboard.writeText(turn.translatedText);
                          }}
                          className="p-1.5 rounded-lg transition-colors hover:bg-surface-3/50 text-slate-400"
                          title="Copy"
                        >
                          <Copy className="w-4 h-4" />
                        </button>
                        <button
                          onClick={() => handleSavePhraseForHistory(turn)}
                          className="p-1.5 rounded-lg transition-colors hover:bg-surface-3/50 text-slate-400"
                          title="Save phrase"
                        >
                          <Bookmark className="w-4 h-4" />
                        </button>
                      </div>
                    </div>
                    <div className="space-y-2">
                      <p className="text-sm text-slate-400">{turn.sourceText}</p>
                      <p className="text-base text-slate-100 font-medium">{turn.translatedText}</p>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="flex items-center justify-center h-full">
                <p className="text-slate-500 text-sm">Enter text below to translate</p>
              </div>
            )}
          </div>

          {/* Input Bar - Fixed at bottom */}
          <div className="fixed bottom-[84px] left-1/2 -translate-x-1/2 w-full max-w-4xl px-4 z-10 lg:bottom-4 lg:z-10">
            <div className="bg-surface-2/90 backdrop-blur-xl border border-surface-border rounded-2xl shadow-2xl">
              {/* Recording indicator */}
              {isRecording && (
                <div className="px-3 py-1.5 sm:px-4 sm:py-2 bg-red-50 border-b border-red-100 flex items-center gap-2">
                  <span className="w-2 h-2 bg-red-500 rounded-full animate-pulse" />
                  <span className="text-[10px] sm:text-xs text-red-600 font-medium">Recording {formatTime(recordingTime)}</span>
                </div>
              )}

              {/* Recording ready to submit */}
              {audioBlob && !isRecording && (
                <div className="px-3 py-1.5 sm:px-4 sm:py-2 bg-brand-50 border-b border-brand-100 flex items-center justify-between">
                  <span className="text-[10px] sm:text-xs text-brand-600 font-medium">Recording ready ({formatTime(recordingTime)})</span>
                  <div className="flex gap-2">
                    <button
                      onClick={() => submitRecording(sourceLang)}
                      disabled={isTranscribing}
                      className="px-2 py-1 sm:px-3 sm:py-1 bg-brand-600 hover:bg-brand-500 disabled:bg-slate-200 disabled:text-slate-400 text-white text-[10px] sm:text-xs font-medium rounded-lg transition-colors flex items-center gap-1"
                    >
                      {isTranscribing ? (
                        <>
                          <Loader2 className="w-3 h-3 animate-spin" />
                          Transcribing...
                        </>
                      ) : (
                        'Submit'
                      )}
                    </button>
                    <button
                      onClick={cancelRecording}
                      className="px-2 py-1 sm:px-3 sm:py-1 bg-surface-3/50 hover:bg-surface-3 text-slate-400 text-[10px] sm:text-xs font-medium rounded-lg transition-colors flex items-center gap-1"
                    >
                      <RotateCcw className="w-3 h-3" />
                      Retry
                    </button>
                  </div>
                </div>
              )}

              <div className="flex items-end gap-2 p-2 sm:p-3">
                <textarea
                  value={sourceText}
                  onChange={(e) => setSourceText(e.target.value)}
                  placeholder={`Translate from ${selectedSource?.name}...`}
                  className="flex-1 p-2 sm:p-3 bg-transparent resize-none text-slate-100 placeholder-slate-500 focus:outline-none text-sm sm:text-base leading-relaxed max-h-24 sm:max-h-32"
                  rows={1}
                  onInput={(e) => {
                    const target = e.target as HTMLTextAreaElement;
                    target.style.height = 'auto';
                    target.style.height = Math.min(target.scrollHeight, 96) + 'px';
                  }}
                />
                <div className="flex items-center gap-1 sm:gap-2">
                  <button
                    onClick={handleVoiceInput}
                    disabled={!hasCapability(selectedSource, 'stt')}
                    className={`p-2 sm:p-3 rounded-lg sm:rounded-xl transition-colors ${
                      hasCapability(selectedSource, 'stt')
                        ? isRecording
                          ? 'bg-red-100 text-red-600'
                          : 'hover:bg-surface-3/50 text-slate-400'
                        : 'opacity-30 cursor-not-allowed text-slate-500'
                    }`}
                    title={hasCapability(selectedSource, 'stt') ? 'Voice input' : 'Voice not supported'}
                  >
                    {isRecording ? <Square className="w-4 h-4 sm:w-5 sm:h-5" /> : <Mic className="w-4 h-4 sm:w-5 sm:h-5" />}
                  </button>
                  <button
                    onClick={handleTranslate}
                    disabled={!sourceText.trim() || isTranslating}
                    className="p-2 sm:p-3 bg-brand-600 hover:bg-brand-500 disabled:bg-slate-200 disabled:text-slate-400 text-white rounded-lg sm:rounded-xl transition-all duration-300 shadow-lg shadow-brand-600/20 hover:shadow-brand-600/30 flex items-center justify-center"
                    title="Translate"
                  >
                    {isTranslating ? (
                      <Loader2 className="w-4 h-4 sm:w-5 sm:h-5 animate-spin" />
                    ) : (
                      <ArrowRight className="w-4 h-4 sm:w-5 sm:h-5" />
                    )}
                  </button>
                </div>
              </div>
              {error && (
                <div className="px-3 pb-2 sm:px-4 sm:pb-3">
                  <p className="text-[10px] sm:text-xs text-red-600">{error}</p>
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Conversation Mode */}
      {mode === 'conversation' && (
        <div className="flex-1 flex flex-col pt-24 px-4 max-w-4xl mx-auto w-full">
          {/* Conversation History */}
          <div className="flex-1 overflow-y-auto pb-36 sm:pb-4">
            {conversationHistory.length > 0 && (
              <div className="space-y-4">
                {conversationHistory.map((turn) => (
                  <div
                    key={turn.id}
                    className={`flex gap-3 ${
                      turn.direction === 'source-to-target' ? 'flex-row' : 'flex-row-reverse'
                    }`}
                  >
                    <div className={`flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center ${
                      turn.direction === 'source-to-target'
                        ? 'bg-brand-100 text-brand-600'
                        : 'bg-emerald-100 text-emerald-600'
                    }`}>
                      {turn.direction === 'source-to-target' ? (
                        <Mic className="w-4 h-4" />
                      ) : (
                        <Volume2 className="w-4 h-4" />
                      )}
                    </div>
                    <div className={`flex-1 max-w-[80%] space-y-1 ${
                      turn.direction === 'source-to-target' ? 'text-left' : 'text-right'
                    }`}>
                      <div className={`inline-block px-4 py-2 rounded-xl ${
                        turn.direction === 'source-to-target'
                          ? 'bg-surface-2 border border-surface-border text-slate-100 rounded-tl-sm'
                          : 'bg-brand-600 text-white rounded-tr-sm shadow-sm'
                      }`}>
                        <p className="text-sm leading-relaxed">{turn.originalText}</p>
                      </div>
                      <div className={`inline-block px-4 py-2 rounded-xl ${
                        turn.direction === 'source-to-target'
                          ? 'bg-brand-50 border border-brand-200 text-brand-700 rounded-bl-sm'
                          : 'bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-br-sm'
                      }`}>
                        <div className="flex items-center gap-2">
                          <p className="text-sm leading-relaxed font-medium">{turn.translatedText}</p>
                          <button
                            onClick={() => {
                              if (lastConversationTurn?.id === turn.id && lastConversationAudioUrl) {
                                handleSaveConversationAudio(turn);
                              }
                            }}
                            disabled={!lastConversationAudioUrl || lastConversationTurn?.id !== turn.id}
                            className={`p-1 rounded transition-colors ${
                              lastConversationAudioUrl && lastConversationTurn?.id === turn.id
                                ? 'text-brand-600 hover:bg-brand-100'
                                : 'text-slate-300 cursor-not-allowed'
                            }`}
                            title="Save audio"
                          >
                            <Bookmark className="w-3 h-3" />
                          </button>
                        </div>
                      </div>
                      <p className="text-xs text-slate-500 mt-1">
                        {turn.direction === 'source-to-target' ? selectedSource?.name : selectedTarget?.name}
                      </p>
                    </div>
                  </div>
                ))}
              </div>
            )}

            {conversationHistory.length === 0 && (
              <div className="flex items-center justify-center h-full">
                <div className="text-center">
                  <MessageSquare className="w-12 h-12 text-slate-300 mx-auto mb-3" />
                  <p className="text-sm text-slate-400 font-medium">Start a conversation</p>
                  <p className="text-xs text-slate-500 mt-1">
                    {conversationInputMode === 'automatic'
                      ? 'Tap the button below to begin automatic translation'
                      : 'Select a language below to speak'}
                  </p>
                </div>
              </div>
            )}
          </div>

          {/* Conversation Controls - Fixed at bottom */}
          <div className="fixed bottom-[84px] left-4 right-4 max-w-4xl mx-auto z-10 lg:bottom-4 lg:left-1/2 lg:right-auto lg:-translate-x-1/2 lg:w-full lg:max-w-4xl">
            <div className="bg-surface-2/90 backdrop-blur-xl border border-surface-border rounded-2xl shadow-2xl lg:shadow-sm">
              {/* Mode Selector */}
              <div className="flex justify-center p-2 border-b border-surface-border">
                <div className="inline-flex bg-surface-3/50 rounded-full p-1">
                  <button
                    onClick={() => setConversationInputMode('automatic')}
                    className={`px-3 py-1.5 rounded-full text-xs font-medium transition-all ${
                      conversationInputMode === 'automatic'
                        ? 'bg-surface-2 text-slate-100 shadow-sm'
                        : 'text-slate-400 hover:text-slate-200'
                    }`}
                  >
                    Automatic
                  </button>
                  <button
                    onClick={() => setConversationInputMode('tap-to-speak')}
                    className={`px-3 py-1.5 rounded-full text-xs font-medium transition-all ${
                      conversationInputMode === 'tap-to-speak'
                        ? 'bg-surface-2 text-slate-100 shadow-sm'
                        : 'text-slate-400 hover:text-slate-200'
                    }`}
                  >
                    Tap to speak
                  </button>
                </div>
              </div>

              {/* Controls */}
              <div className="p-4">
                {conversationInputMode === 'automatic' ? (
                  <div className="flex flex-col items-center gap-3">
                    <button
                      onClick={() => {
                        if (conversationState === 'idle' || conversationState === 'error') {
                          startAutomaticRecording('source');
                        } else {
                          stopAutomaticRecording();
                        }
                      }}
                      className={`w-16 h-16 rounded-full flex items-center justify-center transition-all ${
                        conversationState === 'idle' || conversationState === 'error'
                          ? 'bg-brand-600 hover:bg-brand-500 text-white shadow-lg shadow-brand-600/30'
                          : conversationState === 'listening'
                          ? 'bg-red-500 text-white shadow-lg shadow-red-500/30'
                          : 'bg-slate-400 text-white'
                      }`}
                    >
                      {conversationState === 'idle' || conversationState === 'error' ? (
                        <Play className="w-6 h-6" />
                      ) : conversationState === 'listening' ? (
                        <StopCircle className="w-6 h-6" />
                      ) : (
                        <Loader2 className="w-6 h-6 animate-spin" />
                      )}
                    </button>
                    <p className="text-xs text-slate-400">
                      {conversationState === 'idle' && 'Tap to start'}
                      {conversationState === 'listening' && 'Listening...'}
                      {conversationState === 'transcribing' && 'Transcribing...'}
                      {conversationState === 'translating' && 'Translating...'}
                      {conversationState === 'synthesizing' && 'Speaking...'}
                      {conversationState === 'error' && 'Error - tap to retry'}
                    </p>
                  </div>
                ) : (
                  <div className="flex items-center justify-center">
                    {conversationState === 'idle' ? (
                      <div className="flex items-center justify-center gap-4">
                        <button
                          onClick={() => handleConversationRecord('source-to-target')}
                          className="flex-1 p-4 rounded-xl flex flex-col items-center gap-2 transition-all bg-surface-3/50 hover:bg-surface-3 text-slate-400"
                        >
                          <Mic className="w-6 h-6" />
                          <span className="text-xs font-medium">{selectedSource?.name}</span>
                        </button>
                        <button
                          onClick={() => handleConversationRecord('target-to-source')}
                          className="flex-1 p-4 rounded-xl flex flex-col items-center gap-2 transition-all bg-surface-3/50 hover:bg-surface-3 text-slate-400"
                        >
                          <Volume2 className="w-6 h-6" />
                          <span className="text-xs font-medium">{selectedTarget?.name}</span>
                        </button>
                      </div>
                    ) : (
                      <div className="relative flex flex-col items-center gap-4">
                        <div className={`relative w-24 h-24 sm:w-32 sm:h-32 rounded-full flex items-center justify-center transition-all ${
                          conversationState === 'listening'
                            ? currentSpeaker === 'source'
                              ? 'bg-brand-600 shadow-2xl shadow-brand-600/40'
                              : 'bg-emerald-600 shadow-2xl shadow-emerald-600/40'
                            : conversationState === 'transcribing'
                            ? 'bg-blue-500 shadow-2xl shadow-blue-500/40'
                            : conversationState === 'translating'
                            ? 'bg-purple-500 shadow-2xl shadow-purple-500/40'
                            : conversationState === 'synthesizing'
                            ? 'bg-orange-500 shadow-2xl shadow-orange-500/40'
                            : 'bg-slate-400'
                        }`}>
                          {conversationState === 'listening' && (
                            <canvas
                              ref={visualizerCanvasRef}
                              width="128"
                              height="128"
                              className="absolute inset-0 w-full h-full opacity-60"
                            />
                          )}
                          <div className="relative z-10 flex flex-col items-center">
                            {conversationState === 'listening' ? (
                              <button
                                onClick={() => {
                                  if (currentSpeaker === 'source') {
                                    stopTapToSpeakRecording();
                                  } else {
                                    stopTapToSpeakRecording();
                                  }
                                }}
                                className="w-16 h-16 rounded-full bg-white/20 flex items-center justify-center"
                              >
                                <Square className="w-8 h-8" />
                              </button>
                            ) : (
                              <Loader2 className="w-10 h-10 sm:w-12 sm:h-12 animate-spin text-white" />
                            )}
                          </div>
                        </div>
                        <div className="text-center">
                          <p className="text-sm font-medium text-slate-100">
                            {conversationState === 'listening' && 'Tap to stop'}
                            {conversationState === 'transcribing' && 'Transcribing...'}
                            {conversationState === 'translating' && 'Translating...'}
                            {conversationState === 'synthesizing' && 'Speaking...'}
                          </p>
                          {conversationState === 'listening' && (
                            <p className="text-xs text-slate-400 mt-1">{formatTime(recordingTime)}</p>
                          )}
                        </div>
                      </div>
                    )}
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Language Selector Modal */}
      {showLanguageSelector && (
        <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 p-4">
          <div className="bg-surface-2 w-full max-w-md rounded-2xl max-h-[70vh] overflow-hidden flex flex-col shadow-xl">
            <div className="p-4 border-b border-surface-border flex items-center justify-between">
              <h3 className="font-semibold text-slate-100">
                Select {showLanguageSelector === 'source' ? 'Source' : 'Target'} Language
              </h3>
              <button
                onClick={() => setShowLanguageSelector(null)}
                className="p-2 rounded-lg hover:bg-surface-3/50 transition-colors text-slate-400"
              >
                <X className="w-5 h-5" />
              </button>
            </div>
            <div className="flex-1 overflow-y-auto p-2">
              {LANGUAGES.map(lang => (
                <button
                  key={lang.code}
                  onClick={() => {
                    if (showLanguageSelector === 'source') {
                      setSourceLang(lang.code);
                    } else {
                      setTargetLang(lang.code);
                    }
                    setShowLanguageSelector(null);
                  }}
                  className={`w-full flex items-center justify-between p-3 rounded-xl transition-colors ${
                    (showLanguageSelector === 'source' ? sourceLang : targetLang) === lang.code
                      ? 'bg-brand-50 border-2 border-brand-200'
                      : 'hover:bg-surface-3/50 border-2 border-transparent'
                  }`}
                >
                  <div className="text-left">
                    <p className="text-sm font-medium text-slate-100">{lang.name}</p>
                    {lang.nativeName && (
                      <p className="text-xs text-slate-500">{lang.nativeName}</p>
                    )}
                  </div>
                  <div className="flex gap-1">
                    {lang.capabilities.map(cap => (
                      <span
                        key={cap}
                        className="px-1.5 py-0.5 text-[9px] font-semibold rounded bg-surface-3/50 text-slate-500"
                        title={cap === 'stt' ? 'Speech-to-text' : cap === 'tts' ? 'Text-to-speech' : 'Translation'}
                      >
                        {cap === 'stt' ? '🎤' : cap === 'tts' ? '🔊' : '📝'}
                      </span>
                    ))}
                  </div>
                </button>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* Saved Phrases Modal */}
      {showSavedPhrases && (
        <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 p-4">
          <div className="bg-surface-2 w-full max-w-md rounded-2xl max-h-[70vh] overflow-hidden flex flex-col shadow-xl">
            <div className="p-4 border-b border-surface-border flex items-center justify-between">
              <h3 className="font-semibold text-slate-100">Saved Phrases</h3>
              <button
                onClick={() => setShowSavedPhrases(false)}
                className="p-2 rounded-lg hover:bg-surface-3/50 transition-colors text-slate-400"
              >
                <X className="w-5 h-5" />
              </button>
            </div>
            {/* Tabs */}
            <div className="flex border-b border-surface-border">
              <button
                onClick={() => setSavedPhrasesTab('all')}
                className={`flex-1 py-2 text-sm font-medium transition-colors ${
                  savedPhrasesTab === 'all'
                    ? 'text-brand-400 border-b-2 border-brand-400'
                    : 'text-slate-400 hover:text-slate-200'
                }`}
              >
                All
              </button>
              <button
                onClick={() => setSavedPhrasesTab('audio')}
                className={`flex-1 py-2 text-sm font-medium transition-colors ${
                  savedPhrasesTab === 'audio'
                    ? 'text-brand-400 border-b-2 border-brand-400'
                    : 'text-slate-400 hover:text-slate-200'
                }`}
              >
                Audio
              </button>
              <button
                onClick={() => setSavedPhrasesTab('text')}
                className={`flex-1 py-2 text-sm font-medium transition-colors ${
                  savedPhrasesTab === 'text'
                    ? 'text-brand-400 border-b-2 border-brand-400'
                    : 'text-slate-400 hover:text-slate-200'
                }`}
              >
                Text
              </button>
            </div>
            <div className="flex-1 overflow-y-auto p-2">
              {isLoadingSaved ? (
                <div className="flex items-center justify-center py-8">
                  <Loader2 className="w-6 h-6 animate-spin text-slate-400" />
                </div>
              ) : savedPhrases.length === 0 ? (
                <div className="text-center py-8">
                  <BookmarkCheck className="w-12 h-12 mx-auto mb-3 text-slate-300" />
                  <p className="text-sm text-slate-500">No saved phrases yet</p>
                  <p className="text-xs text-slate-400 mt-1">Save translations to access them offline</p>
                </div>
              ) : (
                <div className="space-y-2">
                  {savedPhrases
                    .filter(phrase => {
                      if (savedPhrasesTab === 'audio') return phrase.audioBlob && phrase.audioMimeType;
                      if (savedPhrasesTab === 'text') return !(phrase.audioBlob && phrase.audioMimeType);
                      return true;
                    })
                    .map(phrase => (
                    <div
                      key={phrase.id}
                      className="p-3 bg-surface-3/50 rounded-xl border border-surface-border"
                    >
                      <div className="flex justify-between items-start mb-2">
                        <div className="flex-1">
                          <p className="text-sm font-medium text-slate-100">{phrase.sourceText}</p>
                          <p className="text-sm text-brand-600 mt-1">{phrase.translatedText}</p>
                          <p className="text-xs text-slate-400 mt-2">
                            {LANGUAGES.find(l => l.code === phrase.sourceLanguage)?.name} → {LANGUAGES.find(l => l.code === phrase.targetLanguage)?.name}
                          </p>
                        </div>
                        <div className="flex gap-1">
                          {phrase.audioBlob && phrase.audioMimeType && (
                            <button
                              onClick={() => playAudioFromBlob(phrase.audioBlob!, phrase.audioMimeType!)}
                              className="p-1.5 rounded-lg hover:bg-surface-3/50 transition-colors text-slate-400 hover:text-brand-600"
                              title="Play saved audio"
                            >
                              <Play className="w-4 h-4" />
                            </button>
                          )}
                          <button
                            onClick={() => handleDeletePhrase(phrase.id)}
                            className="p-1.5 rounded-lg hover:bg-surface-3/50 transition-colors text-surface-4 hover:text-red-400"
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
