<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommunicationRequest;
use App\Http\Requests\UpdateCommunicationRequest;
use App\Models\Campaign;
use App\Models\Communication;
use App\Models\MissionSite;
use App\Models\Team;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\CommunicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * CommunicationController — API endpoints for the Communication Centre.
 *
 * All access is authorised on the backend. The frontend may hide controls,
 * but every mutating or read endpoint re-validates authority against the
 * actual communication scope and the authenticated user's active assignments.
 */
class CommunicationController extends Controller
{
    public function __construct(
        private CommunicationService $communications,
        private AuthorizationService $auth
    ) {}

    /**
     * List communications visible to the authenticated user.
     *
     * GET /api/v1/communications
     *
     * Fail-closed scope filtering: only published, non-expired,
     * non-soft-deleted communications addressed to scopes the user is
     * authorised to receive are returned. Most recent first, paginated.
     * Each item includes the user's personal read/acknowledgement state.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Communication::class);

        $request->validate([
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:5,100'],
        ]);

        $user = $request->user();
        $perPage = $request->integer('per_page') ?? 20;

        $scopes = $this->communications->userReceivableScopes($user);

        $query = Communication::published()
            ->with(['author:id,email', 'scopedOrgUnit:id,name', 'scopedCampaign:id,name', 'scopedSite:id,name', 'scopedTeam:id,name'])
            ->with(['reads' => fn ($q) => $q->where('user_id', $user->id)])
            ->receivableBy($scopes)
            ->orderByDesc('published_at')
            ->orderByDesc('created_at');

        $communications = $query->paginate($perPage);

        return response()->json([
            'data' => $communications->getCollection()->map(fn ($c) => $this->communicationArray($c, $user))->values()->all(),
            'meta' => [
                'current_page' => $communications->currentPage(),
                'per_page' => $communications->perPage(),
                'total' => $communications->total(),
                'last_page' => $communications->lastPage(),
                'unread_count' => $this->communications->unreadCount($user),
            ],
        ]);
    }

    /**
     * Return a single communication, only if the user is authorised to
     * receive it.
     *
     * GET /api/v1/communications/{id}
     */
    public function show(Request $request, Communication $communication): JsonResponse
    {
        $this->authorize('view', $communication);

        $user = $request->user();
        $communication->load(['author:id,email', 'reads' => fn ($q) => $q->where('user_id', $user->id)]);

        return response()->json($this->communicationArray($communication, $user));
    }

    /**
     * Create and optionally publish a communication.
     *
     * POST /api/v1/communications
     *
     * Requires communications.publish at the target scope. Drafts are not
     * visible to recipients.
     */
    public function store(StoreCommunicationRequest $request): JsonResponse
    {
        $user = $request->user();

        $this->authorize('create', Communication::class);

        $data = $request->validated();
        $scopeType = $data['scope_type'];

        // Scope-specific publishing authority.
        $authorised = match ($scopeType) {
            'global' => $this->auth->hasGlobalPermission($user, 'communications.publish'),
            'org_unit' => $this->auth->hasPermissionForOrgUnit(
                $user, 'communications.publish', (int) $data['scoped_org_unit_id']
            ),
            'campaign' => ($campaign = Campaign::find($data['scoped_campaign_id'])) !== null
                && $this->auth->hasPermissionForCampaign($user, 'communications.publish', $campaign),
            'site' => ($site = MissionSite::find($data['scoped_site_id'])) !== null
                && $this->auth->hasPermissionForSite($user, 'communications.publish', $site),
            'team' => ($team = Team::find($data['scoped_team_id'])) !== null
                && $this->auth->hasPermissionForTeam($user, 'communications.publish', $team),
            default => false,
        };

        if (! $authorised) {
            return response()->json([
                'message' => 'You are not authorised to publish to this scope.',
            ], 403);
        }

        $isPublished = ($data['status'] ?? Communication::STATUS_DRAFT) === Communication::STATUS_PUBLISHED;

        $communication = Communication::create([
            'author_user_id' => $user->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'communication_type' => $data['communication_type'],
            'scope_type' => $scopeType,
            'scoped_org_unit_id' => $data['scoped_org_unit_id'] ?? null,
            'scoped_campaign_id' => $data['scoped_campaign_id'] ?? null,
            'scoped_site_id' => $data['scoped_site_id'] ?? null,
            'scoped_team_id' => $data['scoped_team_id'] ?? null,
            'requires_acknowledgement' => $data['requires_acknowledgement'] ?? false,
            'status' => $isPublished ? Communication::STATUS_PUBLISHED : Communication::STATUS_DRAFT,
            'published_at' => $isPublished ? now() : null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return response()->json($this->communicationArray($communication->fresh(), $user), 201);
    }

    /**
     * Update a communication. Scope is immutable after creation.
     *
     * PATCH /api/v1/communications/{id}
     */
    public function update(UpdateCommunicationRequest $request, Communication $communication): JsonResponse
    {
        $this->authorize('update', $communication);

        $data = $request->validated();

        // Once a published communication has at least one read record, its
        // core content is locked so that an existing acknowledgement never
        // appears to apply to materially changed content. Drafts and
        // published-but-unread communications remain freely editable; expiry
        // may always be updated.
        $lockedFields = ['title', 'body', 'communication_type', 'requires_acknowledgement'];
        $lockedAttempted = array_keys(array_filter(
            $lockedFields,
            fn (string $field) => isset($data[$field])
        ));

        if ($communication->status === Communication::STATUS_PUBLISHED
            && $communication->reads()->exists()
            && $lockedAttempted !== []) {
            throw ValidationException::withMessages([
                'body' => [
                    'A published communication that has been read cannot be edited. Publish a corrected communication as a new instruction instead.',
                ],
            ]);
        }

        if (isset($data['status'])) {
            $isPublished = $data['status'] === Communication::STATUS_PUBLISHED;
            $communication->status = $data['status'];
            $communication->published_at = $isPublished && $communication->published_at === null
                ? now()
                : ($isPublished ? $communication->published_at : null);
        }

        foreach (['title', 'body', 'communication_type', 'requires_acknowledgement', 'expires_at'] as $field) {
            if (isset($data[$field])) {
                $communication->{$field} = $data[$field];
            }
        }

        $communication->save();

        return response()->json($this->communicationArray($communication->fresh(), $request->user()));
    }

    /**
     * Revoke (soft-delete) a communication. Historical records are preserved.
     *
     * DELETE /api/v1/communications/{id}
     */
    public function destroy(Request $request, Communication $communication): JsonResponse
    {
        $this->authorize('delete', $communication);

        $communication->delete();

        return response()->json(['message' => 'Communication revoked.']);
    }

    /**
     * Mark a communication as read for the authenticated user. Idempotent.
     *
     * POST /api/v1/communications/{id}/read
     */
    public function read(Request $request, Communication $communication): JsonResponse
    {
        $this->authorize('view', $communication);

        $read = $this->communications->markAsRead($request->user(), $communication);

        return response()->json([
            'read_at' => $read->read_at,
        ]);
    }

    /**
     * Acknowledge a communication requiring acknowledgement. Idempotent.
     *
     * POST /api/v1/communications/{id}/acknowledge
     */
    public function acknowledge(Request $request, Communication $communication): JsonResponse
    {
        $this->authorize('view', $communication);

        try {
            $read = $this->communications->markAsAcknowledged($request->user(), $communication);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->errors()['acknowledge'][0] ?? 'Cannot acknowledge this communication.',
            ], 422);
        }

        return response()->json([
            'acknowledged_at' => $read->acknowledged_at,
        ]);
    }

    /**
     * Count of unread, visible, published, non-expired communications for
     * the authenticated user.
     *
     * GET /api/v1/communications/unread-count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Communication::class);

        return response()->json([
            'unread_count' => $this->communications->unreadCount($request->user()),
        ]);
    }

    /**
     * Visible communications requiring acknowledgement that the user has
     * not yet acknowledged.
     *
     * GET /api/v1/communications/requiring-acknowledgement
     */
    public function requiringAcknowledgement(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Communication::class);

        $items = $this->communications->requiringAcknowledgement($request->user())
            ->map(fn ($c) => $this->communicationArray($c, $request->user()));

        return response()->json(['data' => $items->values()->all()]);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Shape a communication into a response array including the user's
     * personal read/acknowledgement state.
     *
     * @return array<string, mixed>
     */
    private function communicationArray(Communication $c, User $user): array
    {
        $read = $c->reads->first();

        [$scopeType, $scopeId] = $c->assignmentScope();

        return [
            'id' => $c->id,
            'title' => $c->title,
            'body' => $c->body,
            'communication_type' => $c->communication_type,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'scope_label' => $this->scopeLabel($c),
            'author' => $c->author ? [
                'id' => $c->author->id,
                'name' => $c->author->person?->full_name ?? $c->author->email,
                'email' => $c->author->email,
            ] : null,
            'requires_acknowledgement' => (bool) $c->requires_acknowledgement,
            'status' => $c->status,
            'published_at' => $c->published_at?->toIso8601String(),
            'expires_at' => $c->expires_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
            'read_at' => $read?->read_at?->toIso8601String(),
            'acknowledged_at' => $read?->acknowledged_at?->toIso8601String(),
            'is_unread' => $read === null || $read->read_at === null,
            'is_acknowledged' => $read !== null && $read->acknowledged_at !== null,
        ];
    }

    /**
     * Human-readable label for the communication's target scope.
     */
    private function scopeLabel(Communication $c): string
    {
        return match ($c->scope_type) {
            'global' => 'Global Mission',
            'org_unit' => $c->scopedOrgUnit?->name ?? 'Organisational unit',
            'campaign' => $c->scopedCampaign?->name ?? 'Campaign',
            'site' => $c->scopedSite?->name ?? 'Mission site',
            'team' => $c->scopedTeam?->name ?? 'Team',
            default => 'Global Mission',
        };
    }
}
