<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TranslationController — Mission Translator integration
 *
 * Provides text translation through Sunbird AI API for African languages.
 * All API credentials are stored server-side; never exposed to frontend.
 */
class TranslationController extends Controller
{
    /**
     * Supported language codes (ISO 639-3)
     */
    private const SUPPORTED_LANGUAGES = [
        'eng' => 'English',
        'lug' => 'Luganda',
        'ach' => 'Acholi',
        'swa' => 'Swahili',
        'nyn' => 'Runyankole',
        'laj' => 'Lango',
    ];

    /**
     * Maximum input text length (characters)
     */
    private const MAX_TEXT_LENGTH = 5000;

    /**
     * Translate text between supported languages.
     *
     * POST /api/v1/translate
     *
     * Request body:
     * - source_language: string (optional, auto-detected if omitted)
     * - target_language: string (required)
     * - text: string (required, max 5000 characters)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function translate(Request $request): JsonResponse
    {
        // Validate request
        $validated = $request->validate([
            'source_language' => ['sometimes', 'string', 'in:'.implode(',', array_keys(self::SUPPORTED_LANGUAGES))],
            'target_language' => ['required', 'string', 'in:'.implode(',', array_keys(self::SUPPORTED_LANGUAGES))],
            'text' => ['required', 'string', 'min:1', 'max:'.self::MAX_TEXT_LENGTH],
        ]);

        $sourceLanguage = $validated['source_language'] ?? null;
        $targetLanguage = $validated['target_language'];
        $text = $validated['text'];

        // Prevent translating to the same language
        if ($sourceLanguage && $sourceLanguage === $targetLanguage) {
            return response()->json([
                'error' => 'Source and target languages cannot be the same.',
            ], 400);
        }

        // Check if Sunbird API token is configured
        $apiToken = config('services.sunbird.token');
        if (!$apiToken) {
            Log::error('Sunbird API token not configured');
            return response()->json([
                'error' => 'Translation service is not configured. Please contact your administrator.',
            ], 503);
        }

        try {
            $startTime = microtime(true);

            // Call Sunbird AI translation API
            $response = Http::timeout(120)->withHeaders([
                'Authorization' => "Bearer {$apiToken}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post('https://api.sunbird.ai/tasks/translate', [
                'text' => $text,
                'target_language' => $targetLanguage,
                'source_language' => $sourceLanguage,
            ]);

            $duration = round(microtime(true) - $startTime, 2);

            Log::info('Translation API call completed', [
                'duration_seconds' => $duration,
                'status' => $response->status(),
                'text_length' => strlen($text),
            ]);

            // Handle API errors
            if ($response->failed()) {
                $statusCode = $response->status();
                $errorBody = null;

                try {
                    $errorBody = $response->json();
                } catch (\Exception $e) {
                    $errorBody = ['raw' => $response->body()];
                }

                Log::error('Sunbird translation API error', [
                    'duration_seconds' => $duration,
                    'status' => $statusCode,
                    'body' => $errorBody,
                ]);

                // Map common error codes to user-friendly messages
                if ($statusCode === 401) {
                    return response()->json([
                        'error' => 'Translation service authentication failed. Please contact your administrator.',
                    ], 503);
                }

                if ($statusCode === 400) {
                    return response()->json([
                        'error' => $errorBody['detail'] ?? 'Invalid translation request. Please check your language selection.',
                    ], 400);
                }

                if ($statusCode === 429) {
                    return response()->json([
                        'error' => 'Too many translation requests. Please wait a moment and try again.',
                    ], 429);
                }

                // Generic error
                return response()->json([
                    'error' => 'Translation service is temporarily unavailable. Please try again later.',
                ], 503);
            }

            $data = $response->json();

            // Verify the response contains expected data
            if (!isset($data['status']) || $data['status'] !== 'COMPLETED') {
                Log::error('Sunbird translation incomplete', ['response' => $data]);
                return response()->json([
                    'error' => 'Translation could not be completed. Please try again.',
                ], 503);
            }

            if (!isset($data['output']['translated_text'])) {
                Log::error('Sunbird translation missing output', ['response' => $data]);
                return response()->json([
                    'error' => 'Translation service returned invalid data. Please try again.',
                ], 503);
            }

            // Return successful translation
            return response()->json([
                'translated_text' => $data['output']['translated_text'],
                'source_language' => $data['output']['source_language'] ?? $sourceLanguage,
                'target_language' => $data['output']['target_language'] ?? $targetLanguage,
            ]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Sunbird API connection error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Could not connect to translation service. Please check your internet connection.',
            ], 503);
        } catch (\Illuminate\Http\Client\TimeoutException $e) {
            Log::error('Sunbird API timeout', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Translation service took too long to respond. Please try again.',
            ], 503);
        } catch (\Exception $e) {
            Log::error('Unexpected translation error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'An unexpected error occurred. Please try again.',
            ], 500);
        }
    }

    /**
     * Get list of supported languages with their capabilities.
     *
     * GET /api/v1/languages
     *
     * @return JsonResponse
     */
    public function languages(): JsonResponse
    {
        return response()->json([
            'languages' => self::SUPPORTED_LANGUAGES,
        ]);
    }

    /**
     * Supported speech-to-text languages (Sunbird AI faster-whisper model)
     */
    private const STT_SUPPORTED_LANGUAGES = [
        'eng' => 'English',
        'lug' => 'Luganda',
        'ach' => 'Acholi',
        'swa' => 'Swahili',
        'nyn' => 'Runyankole',
        // Note: Lango (laj) is NOT supported by Sunbird's STT model
    ];

    /**
     * Supported text-to-speech languages (Sunbird AI Orpheus-3B model)
     */
    private const TTS_SUPPORTED_LANGUAGES = [
        'eng' => 'English',
        'lug' => 'Luganda',
        'ach' => 'Acholi',
        'swa' => 'Swahili',
        'nyn' => 'Runyankole',
        // Note: Lango (laj) is NOT supported by Sunbird's TTS model
    ];

    /**
     * Transcribe audio to text using Sunbird AI speech-to-text.
     *
     * POST /api/v1/transcribe
     *
     * Request body (multipart/form-data):
     * - audio: file (required, max 10MB)
     * - language: string (required, ISO 639-3 code)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function transcribe(Request $request): JsonResponse
    {
        // Validate request
        $validated = $request->validate([
            'audio' => ['required', 'file', 'max:10240', 'mimetypes:audio/mpeg,audio/wav,audio/mp4,audio/ogg,audio/webm,video/webm'],
            'language' => ['required', 'string', 'in:'.implode(',', array_keys(self::STT_SUPPORTED_LANGUAGES))],
        ]);

        $audioFile = $request->file('audio');
        $language = $validated['language'];

        Log::info('Transcription request received', [
            'language' => $language,
            'file_size' => $audioFile->getSize(),
            'mime_type' => $audioFile->getMimeType(),
        ]);

        // Check if Sunbird API token is configured
        $apiToken = config('services.sunbird.token');
        if (!$apiToken) {
            Log::error('Sunbird API token not configured for transcription');
            return response()->json([
                'error' => 'Speech recognition service is not configured. Please contact your administrator.',
            ], 503);
        }

        try {
            // Read audio file content
            $audioContent = file_get_contents($audioFile->getPathname());
            if ($audioContent === false) {
                Log::error('Failed to read audio file', ['path' => $audioFile->getPathname()]);
                return response()->json([
                    'error' => 'Failed to process audio file. Please try again.',
                ], 500);
            }

            $startTime = microtime(true);

            Log::info('Calling Sunbird STT API', [
                'content_length' => strlen($audioContent),
                'language' => $language,
            ]);

            // Call Sunbird AI speech-to-text API
            $response = Http::timeout(120)->attach(
                'audio',
                $audioContent,
                $audioFile->getClientOriginalName()
            )->withHeaders([
                'Authorization' => "Bearer {$apiToken}",
                'Accept' => 'application/json',
            ])->asMultipart()->post('https://api.sunbird.ai/tasks/audio/transcriptions', [
                'language' => $language,
                'timestamps' => false,
            ]);

            $duration = round(microtime(true) - $startTime, 2);

            Log::info('Sunbird STT API response', [
                'duration_seconds' => $duration,
                'status' => $response->status(),
                'successful' => $response->successful(),
            ]);

            // Handle API errors
            if ($response->failed()) {
                $statusCode = $response->status();
                $errorBody = null;

                try {
                    $errorBody = $response->json();
                } catch (\Exception $e) {
                    $errorBody = ['raw' => $response->body()];
                }

                Log::error('Sunbird STT API error', [
                    'status' => $statusCode,
                    'body' => $errorBody,
                ]);

                if ($statusCode === 401) {
                    return response()->json([
                        'error' => 'Speech recognition service authentication failed. Please contact your administrator.',
                    ], 503);
                }

                if ($statusCode === 400 || $statusCode === 422) {
                    return response()->json([
                        'error' => $errorBody['detail'] ?? $errorBody['error'] ?? 'Invalid transcription request. Please check your audio file and language selection.',
                    ], 400);
                }

                if ($statusCode === 413) {
                    return response()->json([
                        'error' => 'Audio file is too large. Please record a shorter message.',
                    ], 413);
                }

                if ($statusCode === 429) {
                    return response()->json([
                        'error' => 'Too many speech recognition requests. Please wait a moment and try again.',
                    ], 429);
                }

                return response()->json([
                    'error' => 'Speech recognition service is temporarily unavailable. Please try again later.',
                ], 503);
            }

            $data = null;
            try {
                $data = $response->json();
            } catch (\Exception $e) {
                Log::error('Failed to parse Sunbird STT response', [
                    'exception' => $e->getMessage(),
                    'raw_body' => $response->body(),
                ]);
                return response()->json([
                    'error' => 'Speech recognition service returned invalid data. Please try again.',
                ], 503);
            }

            // Verify the response contains expected data
            if (!isset($data['audio_transcription'])) {
                Log::error('Sunbird STT missing transcription', ['response' => $data]);
                return response()->json([
                    'error' => 'Speech recognition service returned invalid data. Please try again.',
                ], 503);
            }

            // Return successful transcription
            return response()->json([
                'transcription' => $data['audio_transcription'],
                'language' => $data['language'] ?? $language,
                'duration_seconds' => $data['duration_seconds'] ?? null,
            ]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Sunbird STT connection error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Could not connect to speech recognition service. Please check your internet connection.',
            ], 503);
        } catch (\Illuminate\Http\Client\TimeoutException $e) {
            Log::error('Sunbird STT timeout', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Speech recognition took too long to process. Please try with a shorter recording.',
            ], 503);
        } catch (\Exception $e) {
            Log::error('Unexpected transcription error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'An unexpected error occurred during speech recognition. Please try again.',
            ], 500);
        }
    }

    /**
     * Synthesize speech from text using Sunbird AI text-to-speech.
     *
     * POST /api/v1/synthesize
     *
     * Request body (application/json):
     * - text: string (required)
     * - language: string (required, ISO 639-3 code)
     * - voice: string (optional, speaker name)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function synthesize(Request $request): JsonResponse
    {
        // Validate request
        $validated = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:5000'],
            'language' => ['required', 'string', 'in:'.implode(',', array_keys(self::TTS_SUPPORTED_LANGUAGES))],
            'voice' => ['sometimes', 'string'],
        ]);

        $text = $validated['text'];
        $language = $validated['language'];
        $voice = $validated['voice'] ?? null;

        // Check if Sunbird API token is configured
        $apiToken = config('services.sunbird.token');
        if (!$apiToken) {
            Log::error('Sunbird API token not configured for synthesis');
            return response()->json([
                'error' => 'Speech synthesis service is not configured. Please contact your administrator.',
            ], 503);
        }

        try {
            $startTime = microtime(true);

            // Call Sunbird AI text-to-speech API
            $response = Http::timeout(120)->withHeaders([
                'Authorization' => "Bearer {$apiToken}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post('https://api.sunbird.ai/tasks/audio/speech', [
                'text' => $text,
                'language' => $language,
                'voice' => $voice,
                'response_mode' => 'url',
            ]);

            $duration = round(microtime(true) - $startTime, 2);

            Log::info('TTS API call completed', [
                'duration_seconds' => $duration,
                'status' => $response->status(),
                'text_length' => strlen($text),
            ]);

            // Handle API errors
            if ($response->failed()) {
                $statusCode = $response->status();
                $errorBody = null;

                try {
                    $errorBody = $response->json();
                } catch (\Exception $e) {
                    $errorBody = ['raw' => $response->body()];
                }

                Log::error('Sunbird TTS API error', [
                    'duration_seconds' => $duration,
                    'status' => $statusCode,
                    'body' => $errorBody,
                ]);

                if ($statusCode === 401) {
                    return response()->json([
                        'error' => 'Speech synthesis service authentication failed. Please contact your administrator.',
                    ], 503);
                }

                if ($statusCode === 400 || $statusCode === 422) {
                    return response()->json([
                        'error' => $errorBody['detail'] ?? $errorBody['error'] ?? 'Invalid synthesis request. Please check your text and language selection.',
                    ], 400);
                }

                if ($statusCode === 429) {
                    return response()->json([
                        'error' => 'Too many speech synthesis requests. Please wait a moment and try again.',
                    ], 429);
                }

                return response()->json([
                    'error' => 'Speech synthesis service is temporarily unavailable. Please try again later.',
                ], 503);
            }

            $data = null;
            try {
                $data = $response->json();
            } catch (\Exception $e) {
                Log::error('Failed to parse Sunbird TTS response', [
                    'exception' => $e->getMessage(),
                    'raw_body' => $response->body(),
                ]);
                return response()->json([
                    'error' => 'Speech synthesis service returned invalid data. Please try again.',
                ], 503);
            }

            // Verify the response contains expected data
            if (!isset($data['audio_url'])) {
                Log::error('Sunbird TTS missing audio_url', ['response' => $data]);
                return response()->json([
                    'error' => 'Speech synthesis service returned invalid data. Please try again.',
                ], 503);
            }

            // Return successful synthesis
            return response()->json([
                'audio_url' => $data['audio_url'],
                'duration_seconds' => $data['duration_seconds'] ?? null,
                'language' => $data['language'] ?? $language,
                'voice' => $data['voice'] ?? null,
            ]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Sunbird TTS connection error', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Could not connect to speech synthesis service. Please check your internet connection.',
            ], 503);
        } catch (\Illuminate\Http\Client\TimeoutException $e) {
            Log::error('Sunbird TTS timeout', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'Speech synthesis took too long to process. Please try with shorter text.',
            ], 503);
        } catch (\Exception $e) {
            Log::error('Unexpected synthesis error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'An unexpected error occurred during speech synthesis. Please try again.',
            ], 500);
        }
    }
}
