<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\PersonAddress;
use App\Models\PersonSkill;
use App\Models\PersonLanguage;
use App\Models\PersonMissionExperience;
use App\Models\PersonTraining;
use App\Models\OrgMembership;
use App\Models\OrgUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OnboardingController extends Controller
{
    /**
     * Register a person with complete onboarding information.
     *
     * POST /api/v1/onboarding/register
     *
     * Modes:
     * - 'missionary': Full onboarding with MissionaryProfile (default for self-onboarding)
     * - 'person': Basic person registration without MissionaryProfile
     * - 'upgrade': Add MissionaryProfile to existing person
     */
    public function register(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $mode = $request->input('mode', 'missionary');
            $personId = $request->input('person_id');

            $request->validate([
                'personal' => ['required', 'array'],
                'contact_residence' => ['sometimes', 'array'],
                'church_affiliation' => ['sometimes', 'array'],
                'ministry_profile' => ['sometimes', 'array'],
                'readiness' => ['sometimes', 'array'],
            ]);

            // Section 1: Personal Details
            $personalData = $request->validate([
                'personal.first_name' => ['required', 'string'],
                'personal.middle_name' => ['sometimes', 'string', 'nullable'],
                'personal.last_name' => ['required', 'string'],
                'personal.preferred_name' => ['sometimes', 'string', 'nullable'],
                'personal.gender' => ['sometimes', 'in:male,female,unspecified'],
                'personal.date_of_birth' => ['sometimes', 'date', 'before:today'],
                'personal.nationality' => ['sometimes', 'string'],
            ])['personal'];

            // Determine person to update/create
            if ($mode === 'upgrade' && $personId) {
                // Upgrade mode: load existing person by ID
                $person = Person::findOrFail($personId);
                $person->update([
                    'first_name' => $personalData['first_name'],
                    'middle_name' => $personalData['middle_name'] ?? null,
                    'last_name' => $personalData['last_name'],
                    'preferred_name' => $personalData['preferred_name'] ?? null,
                    'gender' => $personalData['gender'] ?? null,
                    'date_of_birth' => $personalData['date_of_birth'] ?? null,
                    'nationality' => $personalData['nationality'] ?? null,
                ]);
            } else {
                // Self-onboarding or new person registration
                $person = $request->user()->person;
                if ($person) {
                    // Update existing person (self-onboarding)
                    $person->update([
                        'first_name' => $personalData['first_name'],
                        'middle_name' => $personalData['middle_name'] ?? null,
                        'last_name' => $personalData['last_name'],
                        'preferred_name' => $personalData['preferred_name'] ?? null,
                        'gender' => $personalData['gender'] ?? null,
                        'date_of_birth' => $personalData['date_of_birth'] ?? null,
                        'nationality' => $personalData['nationality'] ?? null,
                    ]);
                } else {
                    // Create new person (admin registering someone else)
                    $person = Person::create([
                        'first_name' => $personalData['first_name'],
                        'middle_name' => $personalData['middle_name'] ?? null,
                        'last_name' => $personalData['last_name'],
                        'preferred_name' => $personalData['preferred_name'] ?? null,
                        'gender' => $personalData['gender'] ?? null,
                        'date_of_birth' => $personalData['date_of_birth'] ?? null,
                        'nationality' => $personalData['nationality'] ?? null,
                        'registered_by_user_id' => $request->user()->id,
                    ]);
                }
            }

            // Section 2: Contact & Residence
            if ($request->filled('contact_residence')) {
                $this->handleContactResidence($request->contact_residence, $person);
            }

            // Section 3: Church Affiliation
            if ($request->filled('church_affiliation')) {
                $this->handleChurchAffiliation($request->church_affiliation, $person, $request->user());
            }

            // Section 4: Ministry Profile (only for missionary mode)
            if ($mode === 'missionary' && $request->filled('ministry_profile')) {
                $this->handleMinistryProfile($request->ministry_profile, $person);
            }

            // Section 5: Readiness (only for missionary mode)
            if ($mode === 'missionary' && $request->filled('readiness')) {
                $this->handleReadiness($request->readiness, $person);
            }

            // Create MissionaryProfile for missionary or upgrade mode
            if ($mode === 'missionary' || $mode === 'upgrade') {
                if (!$person->missionaryProfile) {
                    \App\Models\MissionaryProfile::create([
                        'person_id' => $person->id,
                        'deployment_status' => 'available',
                    ]);
                }
            }

            return response()->json([
                'person_id' => $person->id,
                'message' => $mode === 'person' ? 'Person registered successfully.' : 'Missionary registered successfully.',
                'person' => $person->load(['addresses', 'orgMemberships', 'skills', 'languages', 'missionExperience', 'training', 'missionaryProfile']),
            ], 201);
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
        if (!empty($residenceData)) {
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
    private function handleChurchAffiliation(array $data, Person $person, $user): void
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
            return; // Skip if already has membership
        }

        // Create simple church affiliation record
        OrgMembership::create([
            'person_id' => $person->id,
            'org_unit_id' => $data['local_church_id'],
            'membership_type' => 'member',
            'joined_on' => now()->toDateString(),
            'approval_status' => 'approved',
        ]);
    }

    /**
     * Handle ministry profile section.
     */
    private function handleMinistryProfile(array $data, Person $person): void
    {
        // Skills
        if (!empty($data['skills'])) {
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
        if (!empty($data['languages'])) {
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
        if (!empty($data['mission_experience'])) {
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
        if (!empty($data['training'])) {
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
        if (!empty($data['readiness_notes'])) {
            $person->update([
                'notes' => ($person->notes ?? '') . "\n\nReadiness: " . $data['readiness_notes'],
            ]);
        }
    }

    /**
     * Update person onboarding information.
     *
     * PATCH /api/v1/onboarding/people/{person}
     */
    public function update(Request $request, Person $person): JsonResponse
    {
        $request->validate([
            'section' => ['required', 'in:personal,contact_residence,church_affiliation,ministry_profile,readiness'],
            'data' => ['required', 'array'],
        ]);

        return DB::transaction(function () use ($request, $person) {
            $section = $request->section;
            $data = $request->data;

            switch ($section) {
                case 'personal':
                    $this->updatePersonal($data, $person);
                    break;
                case 'contact_residence':
                    $this->handleContactResidence($data, $person);
                    break;
                case 'church_affiliation':
                    $this->handleChurchAffiliation($data, $person, $request->user());
                    break;
                case 'ministry_profile':
                    $this->handleMinistryProfile($data, $person);
                    break;
                case 'readiness':
                    $this->handleReadiness($data, $person);
                    break;
            }

            return response()->json([
                'message' => 'Person updated successfully.',
                'person' => $person->load(['addresses', 'orgMemberships', 'skills', 'languages', 'missionExperience', 'training']),
            ]);
        });
    }

    /**
     * Update personal details.
     */
    private function updatePersonal(array $data, Person $person): void
    {
        $person->update([
            'first_name' => $data['first_name'] ?? $person->first_name,
            'middle_name' => $data['middle_name'] ?? $person->middle_name,
            'last_name' => $data['last_name'] ?? $person->last_name,
            'preferred_name' => $data['preferred_name'] ?? $person->preferred_name,
            'gender' => $data['gender'] ?? $person->gender,
            'date_of_birth' => $data['date_of_birth'] ?? $person->date_of_birth,
            'nationality' => $data['nationality'] ?? $person->nationality,
        ]);
    }
}
