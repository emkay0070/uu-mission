<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\VerificationEmail;
use App\Models\MissionaryProfile;
use App\Models\OrgMembership;
use App\Models\Person;
use App\Models\PersonAddress;
use App\Models\PersonLanguage;
use App\Models\PersonMissionExperience;
use App\Models\PersonSkill;
use App\Models\PersonTraining;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\WorkspaceResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * AuthController — handles Sanctum token-based authentication.
 *
 * All endpoints return JSON. Tokens are issued with named abilities
 * so that future mobile/offline clients can be scoped appropriately.
 *
 * Security notes:
 * - Passwords are validated with minimum strength rules (8+ chars, mixed).
 * - Login errors always return 422 with the same generic message
 *   to prevent user-enumeration via differential responses.
 * - last_login_at is recorded on every successful login for audit purposes.
 * - Token revocation on logout immediately invalidates the current token.
 */
class AuthController extends Controller
{
    public function __construct(
        private AuthorizationService $auth,
        private WorkspaceResolver $workspaces,
    ) {}

    /**
     * Authenticate an existing user and return a Sanctum token.
     *
     * POST /api/v1/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:200'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The credentials you provided are incorrect.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['Your account is not active. Please contact your administrator.'],
            ]);
        }

        // Record last login for audit
        $user->update(['last_login_at' => now()]);

        $deviceName = $request->input('device_name', $request->userAgent() ?? 'Unknown Device');

        $token = $user->createToken($deviceName, ['*'], now()->addMinutes(config('sanctum.expiration', 480)));

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Return the authenticated user's profile.
     *
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['person.missionaryProfile', 'person.addresses', 'person.skills', 'person.languages.language', 'person.missionExperience', 'person.training']);

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Revoke the current token (logout).
     *
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * Revoke all tokens for this user (logout from all devices).
     *
     * POST /api/v1/auth/logout-all
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out from all devices.']);
    }

    /**
     * Register a new user account with full onboarding.
     *
     * POST /api/v1/auth/register
     *
     * Public self-registration creates a pending account that requires email verification.
     * Creates Person, User, and all related onboarding records.
     * Does not grant any roles or permissions.
     */
    public function register(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            // Support both simple signup format and full onboarding format
            $isSimpleFormat = $request->has('first_name') && ! $request->has('personal');

            if ($isSimpleFormat) {
                // Simple signup format (for /signup page)
                $request->validate([
                    'email' => ['required', 'string', 'email', 'unique:users,email'],
                    'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
                    'first_name' => ['required', 'string'],
                    'last_name' => ['required', 'string'],
                ]);

                // Check for existing user
                if (User::where('email', $request->email)->exists()) {
                    throw ValidationException::withMessages([
                        'email' => ['An account with this email already exists.'],
                    ]);
                }

                // Create person with minimal info
                $person = Person::create([
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                ]);

                // Create user with pending status
                $user = User::create([
                    'email' => $request->email,
                    'password' => Hash::make($request->password),
                    'status' => 'pending',
                    'timezone' => 'Africa/Kampala',
                ]);

                // Link person to user
                $person->update(['user_id' => $user->id]);

                // Note: MissionaryProfile is NOT created for ordinary signup.
                // Users can become missionaries later via the onboarding wizard.
            } else {
                // Full onboarding format (for /register page)
                $request->validate([
                    'email' => ['required', 'string', 'email', 'unique:users,email'],
                    'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
                    'personal' => ['required', 'array'],
                    'contact_residence' => ['sometimes', 'array'],
                    'church_affiliation' => ['sometimes', 'array'],
                    'ministry_profile' => ['sometimes', 'array'],
                    'readiness' => ['sometimes', 'array'],
                ]);

                // Check for existing user
                if (User::where('email', $request->email)->exists()) {
                    throw ValidationException::withMessages([
                        'email' => ['An account with this email already exists.'],
                    ]);
                }

                // Extract personal data
                $personalData = $request->validate([
                    'personal.first_name' => ['required', 'string'],
                    'personal.middle_name' => ['sometimes', 'string', 'nullable'],
                    'personal.last_name' => ['required', 'string'],
                    'personal.preferred_name' => ['sometimes', 'string', 'nullable'],
                    'personal.gender' => ['sometimes', 'in:male,female,unspecified'],
                    'personal.date_of_birth' => ['sometimes', 'date', 'before:today'],
                    'personal.nationality' => ['sometimes', 'string'],
                ])['personal'];

                // Create person
                $person = Person::create([
                    'first_name' => $personalData['first_name'],
                    'middle_name' => $personalData['middle_name'] ?? null,
                    'last_name' => $personalData['last_name'],
                    'preferred_name' => $personalData['preferred_name'] ?? null,
                    'gender' => $personalData['gender'] ?? null,
                    'date_of_birth' => $personalData['date_of_birth'] ?? null,
                    'nationality' => $personalData['nationality'] ?? null,
                ]);

                // Handle contact & residence
                if ($request->filled('contact_residence')) {
                    $this->handleContactResidence($request->contact_residence, $person);
                }

                // Handle church affiliation
                if ($request->filled('church_affiliation')) {
                    $this->handleChurchAffiliation($request->church_affiliation, $person);
                }

                // Handle ministry profile
                if ($request->filled('ministry_profile')) {
                    $this->handleMinistryProfile($request->ministry_profile, $person);
                }

                // Handle readiness
                if ($request->filled('readiness')) {
                    $this->handleReadiness($request->readiness, $person);
                }

                // Create user with pending status
                $user = User::create([
                    'email' => $request->email,
                    'password' => Hash::make($request->password),
                    'status' => 'pending',
                    'timezone' => 'Africa/Kampala',
                ]);

                // Link person to user
                $person->update(['user_id' => $user->id]);

                // Create MissionaryProfile for the person
                MissionaryProfile::create([
                    'person_id' => $person->id,
                    'deployment_status' => 'available',
                ]);
            }

            // Generate email verification token
            $verificationToken = Str::random(60);
            $tokenHash = Hash::make($verificationToken);

            \DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => $tokenHash, 'created_at' => now()]
            );

            // Send verification email
            try {
                Mail::to($user->email)->send(new VerificationEmail($user, $verificationToken));
            } catch (\Exception $e) {
                \Log::error('Failed to send verification email', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
            }

            $responseData = [
                'message' => 'Registration successful. Please verify your email.',
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'status' => $user->status,
                    'person_id' => $person->id,
                ],
            ];

            // Always return verification token for development/testing
            $responseData['verification_token'] = $verificationToken;

            return response()->json($responseData, 201);
        });
    }

    /**
     * Handle contact and residence section.
     */
    private function handleContactResidence(array $data, Person $person): void
    {
        $contactData = data_get($data, 'contact', []);
        $residenceData = data_get($data, 'address', []);

        // Contact information
        $person->update([
            'phone' => $contactData['phone'] ?? null,
            'whatsapp_phone' => $contactData['whatsapp_phone'] ?? null,
            'alternative_phone' => $contactData['alternative_phone'] ?? null,
            'email' => $contactData['email'] ?? null,
            'preferred_contact_method' => $contactData['preferred_contact_method'] ?? null,
            'emergency_contact_name' => $contactData['emergency_contact_name'] ?? null,
            'emergency_contact_relationship' => $contactData['emergency_contact_relationship'] ?? null,
            'emergency_contact_phone' => $contactData['emergency_contact_phone'] ?? null,
            'occupation' => $contactData['occupation'] ?? null,
        ]);

        // Residence address
        if (! empty($residenceData)) {
            PersonAddress::create([
                'person_id' => $person->id,
                'country' => $residenceData['country'] ?? null,
                'region' => $residenceData['region'] ?? null,
                'district' => $residenceData['district'] ?? null,
                'county_municipality' => $residenceData['county_municipality'] ?? null,
                'parish_town' => $residenceData['parish_town'] ?? null,
                'village_area' => $residenceData['village_area'] ?? null,
                'physical_address' => $residenceData['physical_address'] ?? null,
                'is_primary' => $residenceData['is_primary'] ?? true,
            ]);
        }
    }

    /**
     * Handle church affiliation section.
     */
    private function handleChurchAffiliation(array $data, Person $person): void
    {
        if (empty($data['local_church_id'])) {
            return;
        }

        // Check for duplicate membership
        $existing = OrgMembership::where('person_id', $person->id)
            ->where('org_unit_id', $data['local_church_id'])
            ->whereNull('ended_on')
            ->first();

        if ($existing) {
            return;
        }

        OrgMembership::create([
            'person_id' => $person->id,
            'org_unit_id' => $data['local_church_id'],
            'membership_type' => $data['membership_type'] ?? 'member',
            'joined_on' => now()->toDateString(),
            'membership_number' => $data['membership_number'] ?? null,
            'baptism_date' => $data['baptism_date'] ?? null,
            'baptism_location' => $data['baptism_location'] ?? null,
            'approval_status' => 'pending',
        ]);
    }

    /**
     * Handle ministry profile section.
     */
    private function handleMinistryProfile(array $data, Person $person): void
    {
        // Skills
        if (! empty($data['skills'])) {
            foreach ($data['skills'] as $skill) {
                PersonSkill::create([
                    'person_id' => $person->id,
                    'skill_category' => $skill['skill_category'],
                    'skill_name' => $skill['skill_name'],
                    'proficiency_level' => $skill['proficiency_level'] ?? null,
                    'years_experience' => $skill['years_experience'] ?? null,
                    'notes' => $skill['notes'] ?? null,
                ]);
            }
        }

        // Languages
        if (! empty($data['languages'])) {
            foreach ($data['languages'] as $language) {
                PersonLanguage::updateOrCreate(
                    [
                        'person_id' => $person->id,
                        'language_code' => $language['language_code'],
                    ],
                    [
                        'is_native' => $language['is_native'] ?? false,
                        'proficiency_level' => $language['proficiency_level'] ?? null,
                        'notes' => $language['notes'] ?? null,
                    ]
                );
            }
        }

        // Mission experience
        if (! empty($data['mission_experience'])) {
            foreach ($data['mission_experience'] as $experience) {
                PersonMissionExperience::create([
                    'person_id' => $person->id,
                    'mission_name' => $experience['mission_name'],
                    'location' => $experience['location'] ?? null,
                    'started_on' => $experience['started_on'] ?? null,
                    'ended_on' => $experience['ended_on'] ?? null,
                    'role' => $experience['role'] ?? null,
                    'description' => $experience['description'] ?? null,
                ]);
            }
        }
    }

    /**
     * Handle readiness section.
     */
    private function handleReadiness(array $data, Person $person): void
    {
        // Training
        if (! empty($data['training'])) {
            foreach ($data['training'] as $training) {
                PersonTraining::create([
                    'person_id' => $person->id,
                    'training_type' => $training['training_type'],
                    'training_name' => $training['training_name'],
                    'training_provider' => $training['training_provider'] ?? null,
                    'completed_on' => $training['completed_on'] ?? null,
                    'expires_on' => $training['expires_on'] ?? null,
                    'certificate_number' => $training['certificate_number'] ?? null,
                    'notes' => $training['notes'] ?? null,
                ]);
            }
        }

        // Store readiness notes in person notes
        if (! empty($data['readiness_notes'])) {
            $person->update([
                'notes' => ($person->notes ?? '')."\n\nReadiness: ".$data['readiness_notes'],
            ]);
        }
    }

    /**
     * Verify email address.
     *
     * POST /api/v1/auth/verify-email
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
        ]);

        // Find token record by checking hash
        $tokenRecord = null;
        $allTokens = \DB::table('password_reset_tokens')->get();

        foreach ($allTokens as $record) {
            if (Hash::check($request->token, $record->token)) {
                $tokenRecord = $record;
                break;
            }
        }

        if (! $tokenRecord) {
            throw ValidationException::withMessages([
                'token' => ['Invalid or expired verification token.'],
            ]);
        }

        // Check token age (24 hours)
        if (Carbon::parse($tokenRecord->created_at)->diffInHours(now()) > 24) {
            \DB::table('password_reset_tokens')->where('email', $tokenRecord->email)->delete();
            throw ValidationException::withMessages([
                'token' => ['Verification token has expired. Please request a new one.'],
            ]);
        }

        // Find user and activate
        $user = User::where('email', $tokenRecord->email)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'token' => ['User not found.'],
            ]);
        }

        // Activate user
        $user->update(['status' => 'active', 'email_verified_at' => now()]);

        // Delete token (single-use)
        \DB::table('password_reset_tokens')->where('email', $tokenRecord->email)->delete();

        return response()->json([
            'message' => 'Email verified successfully.',
        ]);
    }

    /**
     * Reset password with token.
     *
     * POST /api/v1/auth/reset-password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $resetRecord = \DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (! $resetRecord || ! Hash::check($request->token, $resetRecord->token)) {
            throw ValidationException::withMessages([
                'token' => ['Invalid or expired reset token.'],
            ]);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['User not found.'],
            ]);
        }

        $user->update(['password' => Hash::make($request->password)]);

        // Delete used token
        \DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json(['message' => 'Password reset successfully.']);
    }

    /**
     * Update the authenticated user's password.
     *
     * POST /api/v1/auth/change-password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'confirmed',
                Password::min(8)->mixedCase()->numbers()],
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        // Revoke all other tokens — force re-login on other devices
        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password updated successfully.']);
    }

    /**
     * Shared user payload structure returned on login and /me.
     */
    private function userPayload(User $user): array
    {
        $person = $user->person;

        // Load role assignments to determine user type
        $roleAssignments = $user->roleAssignments()
            ->with('role')
            ->get();

        $roles = $roleAssignments->map(fn ($ra) => [
            'code' => $ra->role->code,
            'label' => $ra->role->label,
            'scope_type' => $ra->role->scope_type,
        ])->toArray();

        // Determine primary role for routing.
        //
        // COMPATIBILITY FIELD — retained while consumers migrate to the
        // workspace model below. New code must read `workspaces` instead: a
        // binary admin/missionary flag cannot express a person who holds, for
        // example, only site-operational authority.
        $primaryRole = null;
        foreach ($roleAssignments as $ra) {
            if (in_array($ra->role->code, ['platform_admin', 'admin', 'union_director', 'conference_director'])) {
                $primaryRole = 'admin';
                break;
            }
        }
        // Only grant 'missionary' primary_role when the user has completed
        // onboarding: a linked Person record AND a MissionaryProfile. An
        // active role assignment alone is not sufficient — an account that
        // has merely registered (or had a manually inserted assignment) must
        // not be treated as a fully onboarded missionary.
        if (! $primaryRole && $roleAssignments->isNotEmpty() && $person && $person->missionaryProfile) {
            $primaryRole = 'missionary';
        }

        return [
            'id' => $user->id,
            'email' => $user->email,
            'status' => $user->status,
            'timezone' => $user->timezone,
            'last_login_at' => $user->last_login_at,
            'primary_role' => $primaryRole,
            'roles' => $roles,
            // Operational workspaces this person may enter, derived entirely
            // from their effective (active, valid, unrevoked) role assignments
            // and the authority each one actually carries. An empty array means
            // no operational workspace is available yet — the client must show
            // a neutral state rather than forcing one.
            //
            // This is the authoritative answer to "what may this person do and
            // where?"; `primary_role` above is a deprecated two-value summary
            // of the same facts.
            'workspaces' => $this->workspaces->resolve($user),
            // Effective capabilities resolved by the single authorization engine.
            //
            // `permissions` is the union of permission codes across all active
            // scopes. It is intended ONLY for UI affordances (which navigation
            // entries and controls to render) — backend resource access is
            // always re-checked against the requested resource's real scope.
            // `scopes` describes the active assignments so the client can show
            // context without treating a scoped permission as global.
            'permissions' => $this->auth->getEffectivePermissions($user),
            'scopes' => $this->auth->getEffectiveScopes($user),
            'person' => $person ? [
                'id' => $person->id,
                'first_name' => $person->first_name,
                'middle_name' => $person->middle_name,
                'last_name' => $person->last_name,
                'preferred_name' => $person->preferred_name,
                'full_name' => $person->full_name,
                'gender' => $person->gender,
                'date_of_birth' => $person->date_of_birth,
                'nationality' => $person->nationality,
                'phone' => $person->phone,
                'whatsapp_phone' => $person->whatsapp_phone,
                'alternative_phone' => $person->alternative_phone,
                'email' => $person->email,
                'preferred_contact_method' => $person->preferred_contact_method,
                'emergency_contact_name' => $person->emergency_contact_name,
                'emergency_contact_relationship' => $person->emergency_contact_relationship,
                'emergency_contact_phone' => $person->emergency_contact_phone,
                'occupation' => $person->occupation,
                'photo_path' => $person->photo_path,
                'addresses' => $person->addresses,
                'skills' => $person->skills,
                'languages' => $person->languages,
                'mission_experience' => $person->missionExperience,
                'training' => $person->training,
                'missionary_profile' => $person->missionaryProfile,
            ] : null,
        ];
    }
}
