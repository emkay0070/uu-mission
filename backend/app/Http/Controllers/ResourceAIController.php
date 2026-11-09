<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Models\ResourceConversation;
use App\Models\ResourceConversationMessage;
use App\Models\ResourceLearningNote;
use App\Models\ResourceSavedExplanation;
use App\Services\AIService;
use App\Services\BookRetrievalService;
use App\Services\ResourceVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ResourceAIController — AI Learning Companion endpoints.
 *
 * Handles chat, conversations, saved explanations, and learning notes.
 * All operations verify resource access via ResourceVisibilityService.
 * Conversations and notes are private to the authenticated user.
 */
class ResourceAIController extends Controller
{
    protected AIService $aiService;

    protected BookRetrievalService $retrieval;

    protected ResourceVisibilityService $visibility;

    public function __construct(AIService $aiService, BookRetrievalService $retrieval, ResourceVisibilityService $visibility)
    {
        $this->aiService = $aiService;
        $this->retrieval = $retrieval;
        $this->visibility = $visibility;
    }

    /**
     * Send a chat message and stream the AI response.
     *
     * POST /api/v1/resources/{resource}/ai/chat
     */
    public function chat(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        if (! $resource->hasCurrentVersion()) {
            return response()->json(['error' => 'No file available for this resource'], 404);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:5000'],
            'conversation_id' => ['nullable', 'exists:resource_conversations,id'],
            'page_number' => ['nullable', 'integer'],
            'selected_text' => ['nullable', 'string', 'max:5000'],
            'context_type' => ['nullable', 'in:selected_text,current_page,whole_book,general,general_explanation'],
        ]);

        $user = $request->user();
        $version = $resource->currentVersion;

        // Verify AI service is configured
        if (! $this->aiService->isConfigured()) {
            return response()->json(['error' => 'AI service is not configured. Please contact your administrator.'], 503);
        }

        // Get or create conversation
        $conversationId = $validated['conversation_id'] ?? null;
        if ($conversationId) {
            $conversation = ResourceConversation::where('id', $conversationId)
                ->where('user_id', $user->id)
                ->where('resource_id', $resource->id)
                ->where('resource_version_id', $version->id)
                ->first();

            if (! $conversation) {
                return response()->json(['error' => 'Conversation not found'], 404);
            }
        } else {
            $conversation = ResourceConversation::create([
                'user_id' => $user->id,
                'resource_id' => $resource->id,
                'resource_version_id' => $version->id,
                'title' => $this->generateConversationTitle($validated['message']),
            ]);
        }

        // Create user message
        $userMessage = ResourceConversationMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $validated['message'],
            'page_number' => $validated['page_number'] ?? null,
            'selected_text' => $validated['selected_text'] ?? null,
            'context_metadata' => [
                'context_type' => $validated['context_type'] ?? 'general',
            ],
            'status' => 'completed',
        ]);

        // Build conversation history
        $history = $conversation->messages()
            ->where('status', 'completed')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($msg) => [
                'role' => $msg->role,
                'content' => $msg->content,
            ])
            ->toArray();

        // Build context for AI
        $context = [
            'resource_title' => $resource->title,
            'selected_text' => $validated['selected_text'] ?? null,
            'page_number' => $validated['page_number'] ?? null,
            'context_type' => $validated['context_type'] ?? 'general',
        ];

        // Retrieve relevant book passages if version is a PDF
        $requireBookContext = config('services.retrieval.require_book_context', true);
        $hasBookContext = false;

        if ($version->isPdf()) {
            try {
                $retrievalResult = $this->retrieval->retrieve($user, $resource, $version, $validated['message']);

                if ($retrievalResult['success'] ?? false && ! empty($retrievalResult['passages'])) {
                    $context['book_passages'] = $retrievalResult['passages'];
                    $context['retrieval_metadata'] = $retrievalResult['metadata'];
                    $hasBookContext = true;
                } else {
                    // Retrieval failed or no passages found — continue without book context.
                    // The AI service can still answer from general knowledge.
                    Log::info('Book retrieval returned no passages', [
                        'resource_version_id' => $version->id,
                        'reason' => $retrievalResult['error'] ?? 'no passages found',
                    ]);
                }
            } catch (Exception $e) {
                // Retrieval error — continue without book context.
                Log::warning('Book retrieval failed', [
                    'resource_version_id' => $version->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Create assistant message (pending)
        $assistantMessage = ResourceConversationMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
            'page_number' => $validated['page_number'] ?? null,
            'selected_text' => $validated['selected_text'] ?? null,
            'context_metadata' => $context,
            'status' => 'pending',
        ]);

        // Store citations in provider_metadata for later retrieval
        $citations = [];
        $retrievedChunkIds = [];

        if (isset($context['book_passages'])) {
            foreach ($context['book_passages'] as $passage) {
                $chunkId = $passage['chunk_id'] ?? null;
                if ($chunkId) {
                    $retrievedChunkIds[] = $chunkId;
                    $citations[] = [
                        'page_number' => $passage['page_number'],
                        'chapter' => $passage['chapter'] ?? null,
                        'heading' => $passage['heading'] ?? null,
                        'chunk_id' => $chunkId,
                    ];
                }
            }
        }

        // Store retrieved chunk IDs for validation
        $context['retrieved_chunk_ids'] = $retrievedChunkIds;

        // Call AI service
        $result = $this->aiService->chat($history, $context, function ($content) use ($assistantMessage) {
            // Update message with content (for non-streaming, this is the full response)
            $assistantMessage->content = $content;
            $assistantMessage->status = 'completed';
            $assistantMessage->provider_metadata = $content ? ['streamed' => true] : null;
            $assistantMessage->save();
        });

        if (! $result['success']) {
            $assistantMessage->status = 'failed';
            $assistantMessage->error_message = $result['error'];
            $assistantMessage->save();

            return response()->json(['error' => $result['error']], $result['http_status'] ?? 500);
        }

        // Update conversation timestamp
        $conversation->last_message_at = now();
        $conversation->save();

        // Update assistant message with metadata including citations
        $assistantMessage->provider_metadata = array_merge($result['metadata'] ?? [], [
            'citations' => $citations,
            'retrieved_chunk_ids' => $retrievedChunkIds,
            'retrieval_metadata' => $context['retrieval_metadata'] ?? null,
        ]);
        $assistantMessage->save();

        // Validate citations against retrieved passages
        $validatedCitations = $this->validateCitations($citations, $retrievedChunkIds);

        return response()->json([
            'conversation_id' => $conversation->id,
            'user_message_id' => $userMessage->id,
            'assistant_message_id' => $assistantMessage->id,
            'content' => $result['content'],
            'metadata' => $result['metadata'],
            'citations' => $validatedCitations,
        ]);
    }

    /**
     * List conversations for a resource.
     *
     * GET /api/v1/resources/{resource}/ai/conversations
     */
    public function conversations(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        $user = $request->user();

        $conversations = ResourceConversation::where('user_id', $user->id)
            ->where('resource_id', $resource->id)
            ->withCount('messages')
            ->orderBy('last_message_at', 'desc')
            ->get()
            ->map(fn ($conv) => [
                'id' => $conv->id,
                'title' => $conv->title,
                'last_message_at' => $conv->last_message_at?->toIso8601String(),
                'message_count' => $conv->messages_count,
                'resource_version_id' => $conv->resource_version_id,
            ]);

        return response()->json(['conversations' => $conversations]);
    }

    /**
     * List all user conversations across resources.
     *
     * GET /api/v1/ai/conversations
     */
    public function allConversations(Request $request): JsonResponse
    {
        $user = $request->user();

        $conversations = ResourceConversation::where('user_id', $user->id)
            ->with(['resource', 'resourceVersion'])
            ->withCount('messages')
            ->orderBy('last_message_at', 'desc')
            ->get()
            ->filter(function ($conv) use ($user) {
                // Recheck resource access for each conversation
                return $user->can('view', $conv->resource);
            })
            ->map(fn ($conv) => [
                'id' => $conv->id,
                'title' => $conv->title,
                'last_message_at' => $conv->last_message_at?->toIso8601String(),
                'message_count' => $conv->messages_count,
                'resource' => [
                    'id' => $conv->resource->id,
                    'title' => $conv->resource->title,
                ],
                'resource_version_id' => $conv->resource_version_id,
            ]);

        return response()->json(['conversations' => $conversations]);
    }

    /**
     * Get a specific conversation with messages.
     *
     * GET /api/v1/ai/conversations/{id}
     */
    public function getConversation(Request $request, ResourceConversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        // Verify resource access (ResourcePolicy::view also covers a creator's own drafts)
        if (! $request->user()->can('view', $conversation->resource)) {
            return response()->json(['error' => 'Resource not accessible'], 403);
        }

        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'role' => $msg->role,
                'content' => $msg->content,
                'page_number' => $msg->page_number,
                'selected_text' => $msg->selected_text,
                'status' => $msg->status,
                'error_message' => $msg->error_message,
                'created_at' => $msg->created_at->toIso8601String(),
                'citations' => $msg->provider_metadata['citations'] ?? null,
            ]);

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'resource' => [
                    'id' => $conversation->resource->id,
                    'title' => $conversation->resource->title,
                ],
                'resource_version_id' => $conversation->resource_version_id,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            ],
            'messages' => $messages,
        ]);
    }

    /**
     * Delete a conversation.
     *
     * DELETE /api/v1/ai/conversations/{id}
     */
    public function deleteConversation(Request $request, ResourceConversation $conversation): JsonResponse
    {
        $this->authorize('delete', $conversation);

        $conversation->delete();

        return response()->json(null, 204);
    }

    /**
     * Retry a failed assistant message.
     *
     * POST /api/v1/ai/messages/{message}/retry
     */
    public function retryMessage(Request $request, ResourceConversationMessage $message): JsonResponse
    {
        $user = $request->user();

        // Verify message belongs to user's conversation
        if ((int) $message->conversation->user_id !== (int) $user->id) {
            return response()->json(['error' => 'Message not found or access denied'], 404);
        }

        // Verify it's an assistant message with failed status
        if ($message->role !== 'assistant' || $message->status !== 'failed') {
            return response()->json(['error' => 'Only failed assistant messages can be retried'], 400);
        }

        $resource = $message->conversation->resource;
        $version = $message->conversation->resourceVersion;

        // Verify resource access (ResourcePolicy::view also covers a creator's own drafts)
        if (! $user->can('view', $resource)) {
            return response()->json(['error' => 'Resource not accessible'], 403);
        }

        // Verify AI service is configured
        if (! $this->aiService->isConfigured()) {
            return response()->json(['error' => 'AI service is not configured. Please contact your administrator.'], 503);
        }

        // Reset message to pending
        $message->status = 'pending';
        $message->error_message = null;
        $message->save();

        // Build conversation history (all completed messages before this one)
        $history = $message->conversation->messages()
            ->where('status', 'completed')
            ->where('id', '<', $message->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($msg) => [
                'role' => $msg->role,
                'content' => $msg->content,
            ])
            ->toArray();

        // Build context from original message metadata
        $context = $message->context_metadata ?? [];

        // Re-retrieve book passages for follow-up questions with same rules as chat()
        $requireBookContext = config('services.retrieval.require_book_context', true);

        if ($version->isPdf() && isset($context['resource_title'])) {
            try {
                // Get the most recent user message in the conversation
                $latestUserMessage = $message->conversation->messages()
                    ->where('role', 'user')
                    ->orderBy('id', 'desc')
                    ->first();

                if ($latestUserMessage) {
                    $retrievalResult = $this->retrieval->retrieve($user, $resource, $version, $latestUserMessage->content);

                    if ($retrievalResult['success'] && ! empty($retrievalResult['passages'])) {
                        $context['book_passages'] = $retrievalResult['passages'];
                        $context['retrieval_metadata'] = $retrievalResult['metadata'];
                    } else {
                        // Retrieval failed or no passages found
                        Log::info('Book retrieval returned no passages during retry', [
                            'resource_version_id' => $version->id,
                            'reason' => $retrievalResult['error'] ?? 'no passages found',
                        ]);

                        // If book context is required and user didn't explicitly request general explanation
                        if ($requireBookContext && ($context['context_type'] ?? 'general') !== 'general_explanation') {
                            return response()->json([
                                'error' => 'I could not find relevant passages in the book to answer your question. Please try rephrasing your question or ask for a general explanation (which I can provide, but it will not be based on the book).',
                                'no_book_context' => true,
                            ], 400);
                        }
                    }
                }
            } catch (Exception $e) {
                // Retrieval error
                Log::warning('Book retrieval failed during retry', [
                    'resource_version_id' => $version->id,
                    'error' => $e->getMessage(),
                ]);

                if ($requireBookContext && ($context['context_type'] ?? 'general') !== 'general_explanation') {
                    return response()->json([
                        'error' => 'I encountered an error while searching the book. Please try again or ask for a general explanation.',
                        'no_book_context' => true,
                    ], 500);
                }
            }
        }

        // Call AI service
        $result = $this->aiService->chat($history, $context, function ($content) use ($message) {
            $message->content = $content;
            $message->status = 'completed';
            $message->provider_metadata = $content ? ['streamed' => true] : null;
            $message->save();
        });

        if (! $result['success']) {
            $message->status = 'failed';
            $message->error_message = $result['error'];
            $message->save();

            return response()->json(['error' => $result['error']], $result['http_status'] ?? 500);
        }

        // Extract citations from context
        $citations = [];
        $retrievedChunkIds = [];

        if (isset($context['book_passages'])) {
            foreach ($context['book_passages'] as $passage) {
                $chunkId = $passage['chunk_id'] ?? null;
                if ($chunkId) {
                    $retrievedChunkIds[] = $chunkId;
                    $citations[] = [
                        'page_number' => $passage['page_number'],
                        'chapter' => $passage['chapter'] ?? null,
                        'heading' => $passage['heading'] ?? null,
                        'chunk_id' => $chunkId,
                    ];
                }
            }
        }

        // Update message with metadata including citations
        $message->provider_metadata = array_merge($result['metadata'] ?? [], [
            'citations' => $citations,
            'retrieved_chunk_ids' => $retrievedChunkIds,
            'retrieval_metadata' => $context['retrieval_metadata'] ?? null,
        ]);
        $message->save();

        // Validate citations against retrieved passages
        $validatedCitations = $this->validateCitations($citations, $retrievedChunkIds);

        // Update conversation timestamp
        $message->conversation->last_message_at = now();
        $message->conversation->save();

        return response()->json([
            'message_id' => $message->id,
            'content' => $result['content'],
            'metadata' => $result['metadata'],
            'citations' => $validatedCitations,
        ]);
    }

    /**
     * Save an AI explanation.
     *
     * POST /api/v1/ai/saved-explanations
     */
    public function saveExplanation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_message_id' => ['required', 'exists:resource_conversation_messages,id'],
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'content' => ['required', 'string', 'min:1'],
            'page_number' => ['nullable', 'integer'],
            'selected_text' => ['nullable', 'string'],
        ]);

        $user = $request->user();

        // Verify the message belongs to the user's conversation
        $message = ResourceConversationMessage::with('conversation')
            ->where('id', $validated['conversation_message_id'])
            ->where('role', 'assistant')
            ->first();

        if (! $message || (int) $message->conversation->user_id !== (int) $user->id) {
            return response()->json(['error' => 'Message not found or access denied'], 404);
        }

        // Verify resource and version consistency
        if (! $this->visibility->canView($user, $message->conversation->resource, $message->conversation->resourceVersion)) {
            return response()->json(['error' => 'Resource not accessible'], 403);
        }

        // Check for duplicate save
        $existing = ResourceSavedExplanation::where('user_id', $user->id)
            ->where('conversation_message_id', $validated['conversation_message_id'])
            ->first();

        if ($existing) {
            return response()->json(['error' => 'This explanation has already been saved'], 400);
        }

        $savedExplanation = ResourceSavedExplanation::create([
            'user_id' => $user->id,
            'resource_id' => $message->conversation->resource_id,
            'resource_version_id' => $message->conversation->resource_version_id,
            'conversation_message_id' => $validated['conversation_message_id'],
            'title' => $validated['title'],
            'content' => $validated['content'],
            'page_number' => $validated['page_number'] ?? null,
            'selected_text' => $validated['selected_text'] ?? null,
        ]);

        return response()->json(['saved_explanation' => $savedExplanation], 201);
    }

    /**
     * List saved explanations.
     *
     * GET /api/v1/ai/saved-explanations
     */
    public function savedExplanations(Request $request): JsonResponse
    {
        $user = $request->user();
        $resourceId = $request->query('resource_id');

        $query = ResourceSavedExplanation::where('user_id', $user->id)
            ->with(['resource', 'resourceVersion']);

        if ($resourceId) {
            $query->where('resource_id', $resourceId);
        }

        $explanations = $query->orderBy('created_at', 'desc')
            ->get()
            ->filter(function ($exp) use ($user) {
                // Recheck resource access for each explanation
                return $this->visibility->canView($user, $exp->resource, $exp->resourceVersion);
            })
            ->map(fn ($exp) => [
                'id' => $exp->id,
                'title' => $exp->title,
                'content' => $exp->content,
                'page_number' => $exp->page_number,
                'selected_text' => $exp->selected_text,
                'resource' => [
                    'id' => $exp->resource->id,
                    'title' => $exp->resource->title,
                ],
                'conversation_message_id' => $exp->conversation_message_id,
                'created_at' => $exp->created_at->toIso8601String(),
            ]);

        return response()->json(['saved_explanations' => $explanations]);
    }

    /**
     * Create a learning note.
     *
     * POST /api/v1/resources/{resource}/learning-notes
     */
    public function createLearningNote(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        if (! $resource->hasCurrentVersion()) {
            return response()->json(['error' => 'No file available for this resource'], 404);
        }

        $validated = $request->validate([
            'note_type' => ['required', 'in:understanding,reflection,chapter_summary,book_summary,important_passage,question'],
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'content' => ['required', 'string', 'min:1'],
            'chapter' => ['nullable', 'string'],
            'page_number' => ['nullable', 'integer'],
            'passage_text' => ['nullable', 'string'],
            'passage_position' => ['nullable', 'array'],
            'saved_explanation_id' => ['nullable', 'exists:resource_saved_explanations,id'],
            'conversation_message_id' => ['nullable', 'exists:resource_conversation_messages,id'],
        ]);

        $user = $request->user();
        $version = $resource->currentVersion;

        // Validate saved explanation ownership if provided
        if (isset($validated['saved_explanation_id'])) {
            $savedExplanation = ResourceSavedExplanation::where('id', $validated['saved_explanation_id'])
                ->where('user_id', $user->id)
                ->where('resource_id', $resource->id)
                ->first();

            if (! $savedExplanation) {
                return response()->json(['error' => 'Saved explanation not found or access denied'], 404);
            }
        }

        // Validate conversation message ownership if provided
        if (isset($validated['conversation_message_id'])) {
            $message = ResourceConversationMessage::with('conversation')
                ->where('id', $validated['conversation_message_id'])
                ->first();

            if (! $message || (int) $message->conversation->user_id !== (int) $user->id) {
                return response()->json(['error' => 'Conversation message not found or access denied'], 404);
            }
        }

        $note = ResourceLearningNote::create([
            'user_id' => $user->id,
            'resource_id' => $resource->id,
            'resource_version_id' => $version->id,
            'saved_explanation_id' => $validated['saved_explanation_id'] ?? null,
            'conversation_message_id' => $validated['conversation_message_id'] ?? null,
            'note_type' => $validated['note_type'],
            'title' => $validated['title'],
            'content' => $validated['content'],
            'chapter' => $validated['chapter'] ?? null,
            'page_number' => $validated['page_number'] ?? null,
            'passage_text' => $validated['passage_text'] ?? null,
            'passage_position' => $validated['passage_position'] ?? null,
        ]);

        return response()->json(['learning_note' => $note], 201);
    }

    /**
     * List learning notes for a resource.
     *
     * GET /api/v1/resources/{resource}/learning-notes
     */
    public function learningNotes(Request $request, Resource $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        $user = $request->user();
        $noteType = $request->query('note_type');
        $chapter = $request->query('chapter');

        $query = ResourceLearningNote::where('user_id', $user->id)
            ->where('resource_id', $resource->id)
            ->with(['savedExplanation', 'conversationMessage']);

        if ($noteType) {
            $query->where('note_type', $noteType);
        }

        if ($chapter) {
            $query->where('chapter', $chapter);
        }

        $notes = $query->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($note) => [
                'id' => $note->id,
                'note_type' => $note->note_type,
                'title' => $note->title,
                'content' => $note->content,
                'chapter' => $note->chapter,
                'page_number' => $note->page_number,
                'passage_text' => $note->passage_text,
                'passage_position' => $note->passage_position,
                'saved_explanation_id' => $note->saved_explanation_id,
                'conversation_message_id' => $note->conversation_message_id,
                'created_at' => $note->created_at->toIso8601String(),
                'updated_at' => $note->updated_at->toIso8601String(),
            ]);

        return response()->json(['learning_notes' => $notes]);
    }

    /**
     * Update a learning note.
     *
     * PUT /api/v1/resources/{resource}/learning-notes/{id}
     */
    public function updateLearningNote(Request $request, Resource $resource, ResourceLearningNote $note): JsonResponse
    {
        $this->authorize('view', $resource);
        $this->authorize('update', $note);

        if ((int) $note->resource_id !== (int) $resource->id) {
            return response()->json(['error' => 'Note does not belong to this resource'], 404);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:1', 'max:200'],
            'content' => ['sometimes', 'required', 'string', 'min:1'],
        ]);

        $note->update($validated);

        return response()->json(['learning_note' => $note]);
    }

    /**
     * Delete a learning note.
     *
     * DELETE /api/v1/resources/{resource}/learning-notes/{id}
     */
    public function deleteLearningNote(Request $request, Resource $resource, ResourceLearningNote $note): JsonResponse
    {
        $this->authorize('view', $resource);
        $this->authorize('delete', $note);

        if ((int) $note->resource_id !== (int) $resource->id) {
            return response()->json(['error' => 'Note does not belong to this resource'], 404);
        }

        $note->delete();

        return response()->json(null, 204);
    }

    /**
     * Generate a conversation title from the first message.
     */
    protected function generateConversationTitle(string $message): string
    {
        // Truncate to first 50 characters
        $title = mb_substr($message, 0, 50);
        if (mb_strlen($message) > 50) {
            $title .= '...';
        }

        return $title;
    }

    /**
     * Validate citations against retrieved passages.
     * Only return citations that reference chunks that were actually retrieved.
     *
     * @param  array  $citations  Citations extracted from context
     * @param  array  $retrievedChunkIds  Chunk IDs that were actually retrieved
     * @return array Validated citations
     */
    protected function validateCitations(array $citations, array $retrievedChunkIds): array
    {
        if (empty($citations) || empty($retrievedChunkIds)) {
            return [];
        }

        // Filter citations to only include those with valid chunk IDs
        $chunkIdSet = array_flip($retrievedChunkIds);
        $validated = [];

        foreach ($citations as $citation) {
            $chunkId = $citation['chunk_id'] ?? null;
            if ($chunkId && isset($chunkIdSet[$chunkId])) {
                $validated[] = $citation;
            }
        }

        return $validated;
    }
}
