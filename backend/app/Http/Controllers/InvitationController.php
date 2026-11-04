<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\Person;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvitationController extends Controller
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * Create an invitation for a person or email.
     *
     * POST /api/v1/invitations
     *
     * Requires admin or coordinator permissions.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'person_id' => ['nullable', 'exists:people,id'],
            'org_unit_id' => ['nullable', 'exists:org_units,id'],
            'membership_type' => ['nullable', 'in:member,associate,partner'],
        ]);

        $user = $request->user();

        // Authorization check: must have people.create permission
        if (!$this->auth->hasGlobalPermission($user, 'people.create')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check if there's already a pending invitation for this email
        $existing = Invitation::where('email', $request->email)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'email' => ['A pending invitation already exists for this email.'],
            ]);
        }

        // Check if user already exists
        $existingUser = User::where('email', $request->email)->first();
        if ($existingUser) {
            throw ValidationException::withMessages([
                'email' => ['A user with this email already exists.'],
            ]);
        }

        // Generate secure token
        $token = Str::random(60);
        $tokenHash = Hash::make($token);

        $invitation = Invitation::create([
            'person_id' => $request->person_id,
            'org_unit_id' => $request->org_unit_id,
            'membership_type' => $request->membership_type,
            'email' => $request->email,
            'token_hash' => $tokenHash,
            'expires_at' => now()->addDays(7),
            'created_by_user_id' => $user->id,
            'status' => 'pending',
        ]);

        // In production, send email with the token
        // For now, return the token in response for testing
        return response()->json([
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'expires_at' => $invitation->expires_at,
                'token' => $token, // Only for testing - remove in production
            ],
        ], 201);
    }

    /**
     * Accept an invitation and create account.
     *
     * POST /api/v1/invitations/{token}/accept
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'min:8'],
            'first_name' => ['required', 'string'],
            'last_name' => ['required', 'string'],
            'bio' => ['sometimes', 'string', 'nullable'],
            'training_notes' => ['sometimes', 'string', 'nullable'],
        ]);

        // Find invitation by checking token hash
        $invitation = Invitation::where('status', 'pending')
            ->where('expires_at', '>', now())
            ->get()
            ->first(fn ($inv) => Hash::check($token, $inv->token_hash));

        if (!$invitation) {
            throw ValidationException::withMessages([
                'token' => ['Invalid or expired invitation.'],
            ]);
        }

        // Check if user already exists
        if (User::where('email', $invitation->email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['An account with this email already exists.'],
            ]);
        }

        // Use transaction to prevent race conditions
        return \DB::transaction(function () use ($request, $invitation, $token) {
            // Double-check status is still pending (single-use enforcement)
            $invitation->refresh();
            if ($invitation->status !== 'pending') {
                throw ValidationException::withMessages([
                    'token' => ['This invitation has already been used or is no longer valid.'],
                ]);
            }

            // Create or link person
            $person = null;
            if ($invitation->person_id) {
                $person = Person::find($invitation->person_id);
            }

            if (!$person) {
                $person = Person::create([
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                ]);
            } else {
                // Update person name if provided
                $person->update([
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                ]);
            }

            // Create user
            $user = User::create([
                'email' => $invitation->email,
                'password' => Hash::make($request->password),
                'status' => 'active',
                'email_verified_at' => now(), // Auto-verify for invited users
            ]);

            // Link person to user
            $person->update(['user_id' => $user->id]);

            // Create MissionaryProfile for the person
            $missionaryProfile = \App\Models\MissionaryProfile::create([
                'person_id' => $person->id,
                'deployment_status' => 'available',
            ]);

            // Update MissionaryProfile with bio and training notes if provided
            if ($request->filled('bio') || $request->filled('training_notes')) {
                $missionaryProfile->update([
                    'bio' => $request->bio,
                    'training_notes' => $request->training_notes,
                ]);
            }

            // Create OrgMembership if org_unit_id provided
            if ($invitation->org_unit_id) {
                $existingMembership = \App\Models\OrgMembership::where('person_id', $person->id)
                    ->where('org_unit_id', $invitation->org_unit_id)
                    ->first();
                
                if (!$existingMembership) {
                    \App\Models\OrgMembership::create([
                        'person_id' => $person->id,
                        'org_unit_id' => $invitation->org_unit_id,
                        'membership_type' => $invitation->membership_type ?? 'member',
                        'joined_on' => now()->toDateString(),
                        'approval_status' => 'pending', // Invitation acceptance doesn't prove official membership
                    ]);
                }
            }

            // Mark invitation as accepted
            $invitation->update([
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

            // Issue token
            $deviceName = $request->userAgent() ?? 'Invitation Acceptance';
            $authToken = $user->createToken($deviceName, ['*'], now()->addMinutes(config('sanctum.expiration', 480)));

            return response()->json([
                'token' => $authToken->plainTextToken,
                'expires_at' => $authToken->accessToken->expires_at,
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'status' => $user->status,
                    'person' => [
                        'id' => $person->id,
                        'first_name' => $person->first_name,
                        'last_name' => $person->last_name,
                    ],
                ],
            ]);
        });
    }

    /**
     * List invitations for the current user's scope.
     *
     * GET /api/v1/invitations
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'in:pending,accepted,expired,revoked'],
            'per_page' => ['sometimes', 'integer', 'between:5,100'],
        ]);

        $query = Invitation::with(['person', 'orgUnit', 'createdBy']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $invitations = $query->orderBy('created_at', 'desc')
                           ->paginate($request->input('per_page', 20));

        return response()->json($invitations);
    }

    /**
     * Show invitation details.
     *
     * GET /api/v1/invitations/{id}
     */
    public function show(Request $request, Invitation $invitation): JsonResponse
    {
        $invitation->load(['person', 'orgUnit', 'createdBy']);
        return response()->json($invitation);
    }

    /**
     * Revoke an invitation.
     *
     * POST /api/v1/invitations/{id}/revoke
     */
    public function revoke(Request $request, Invitation $invitation): JsonResponse
    {
        if ($invitation->status !== 'pending') {
            return response()->json(['message' => 'Only pending invitations can be revoked.'], 400);
        }

        $invitation->update(['status' => 'revoked']);

        return response()->json(['message' => 'Invitation revoked successfully.']);
    }

    /**
     * Resend an invitation (generates new token).
     *
     * POST /api/v1/invitations/{id}/resend
     */
    public function resend(Request $request, Invitation $invitation): JsonResponse
    {
        if ($invitation->status !== 'pending' && $invitation->status !== 'expired') {
            return response()->json(['message' => 'Only pending or expired invitations can be resent.'], 400);
        }

        // Generate new token
        $token = Str::random(60);
        $tokenHash = Hash::make($token);

        $invitation->update([
            'token_hash' => $tokenHash,
            'expires_at' => now()->addDays(7),
            'status' => 'pending',
        ]);

        // Send new invitation email
        try {
            Mail::to($invitation->email)->send(new InvitationEmail($invitation, $token));
        } catch (\Exception $e) {
            \Log::error('Failed to resend invitation email', [
                'invitation_id' => $invitation->id,
                'email' => $invitation->email,
                'error' => $e->getMessage(),
            ]);
        }

        $responseData = [
            'message' => 'Invitation resent successfully.',
        ];

        if (config('app.env') === 'local') {
            $responseData['token'] = $token;
        }

        return response()->json($responseData);
    }
}
