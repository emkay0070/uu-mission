# Outreach Management Refactor - Completion Report

**Date:** November 3, 2026  
**Objective:** Separate Outreach Management from Missionary Management by centralizing outreach data in a new `OutreachContact` model and removing engagement stage logic from the `Person` model.

---

## Executive Summary

Successfully completed a comprehensive domain separation refactor to distinguish between:
- **Missionary Management:** Permanent person identity, skills, languages, training, and organizational membership
- **Outreach Management:** Outreach journey tracking, engagement stages, encounters, and baptism records

The refactor involved 26 tasks across database migrations, backend models/controllers, and frontend components. All 12 verification scenarios passed.

---

## Changes Summary

### Database Changes

#### New Tables Created
1. **outreach_contacts** - Central table for outreach journey tracking
   - Links to `people` (permanent identity)
   - Owns `engagement_stage_id`, `date_registered`, `village`, `notes`
   - Campaign and site context tracking

#### Tables Updated
2. **people** - Removed outreach-specific fields
   - Removed: `engagement_stage_id`, `date_registered`, `village`, `notes`
   - Retained: Core identity fields (name, gender, phone, DOB, etc.)

3. **engagement_stage_histories** - Changed primary relationship
   - Added: `outreach_contact_id` (primary relationship, NOT NULL)
   - Changed: `person_id` to nullable (secondary reference)
   - Fixed: Table name from singular to plural (`engagement_stage_histories`)

4. **person_skills** - Canonical storage for missionary skills
5. **person_languages** - Canonical storage for missionary languages
6. **person_training** - Canonical storage for missionary training
7. **person_mission_experience** - Canonical storage for mission experience

#### Tables Removed
8. **missionary_skills** - Consolidated into `person_skills`
9. **missionary_languages** - Consolidated into `person_languages`

---

### Backend Changes

#### Models Updated
1. **Person** (`app/Models/Person.php`)
   - Removed engagement stage casts and methods
   - Added `outreachContact()` relationship
   - Removed `date_registered` cast

2. **OutreachContact** (`app/Models/OutreachContact.php`)
   - Created new model with engagement stage ownership
   - Relationships to Person, EngagementStage, Campaign, MissionSite
   - Methods for stage transitions

3. **EngagementStageHistory** (`app/Models/EngagementStageHistory.php`)
   - Updated to link to `outreach_contact_id` as primary
   - Made `person_id` nullable
   - Fixed table name to `engagement_stage_histories`

4. **OutreachEncounter** (`app/Models/OutreachEncounter.php`)
   - Added `outreachContact()` relationship
   - Updated to use OutreachContact for stage transitions

5. **BaptismRecord** (`app/Models/BaptismRecord.php`)
   - Added `outreachContact()` relationship

6. **MissionaryProfile** (`app/Models/MissionaryProfile.php`)
   - Updated to use canonical `person_skills` and `person_languages` through Person

#### Controllers Updated
1. **PersonController** (`app/Http/Controllers/PersonController.php`)
   - Removed engagement stage logic from create/update
   - Removed engagement stage from show method
   - Fixed duplicate phone validation

2. **OnboardingController** (`app/Http/Controllers/OnboardingController.php`)
   - Removed engagement stage assignment from person creation

3. **AuthController** (`app/Http/Controllers/Auth/AuthController.php`)
   - Removed `engagement_stage_id` and `date_registered` from registration
   - Both simple and full onboarding flows updated

4. **InvitationController** (`app/Http/Controllers/InvitationController.php`)
   - Removed engagement stage from person creation

5. **OutreachEncounterController** (`app/Http/Controllers/OutreachEncounterController.php`)
   - Changed stage transitions to use `$outreachContact->transitionEngagementStage()`

#### Controllers Created
1. **OutreachContactController** (`app/Http/Controllers/OutreachContactController.php`)
   - Full CRUD operations for outreach contacts
   - Stage transition endpoints
   - Campaign and site filtering

#### Policies Created
1. **OutreachContactPolicy** (`app/Policies/OutreachContactPolicy.php`)
   - Authorization based on user access to linked Person
   - View, create, update, delete permissions

---

### Frontend Changes

#### API Types Updated (`frontend/src/lib/api.ts`)
1. Added `OutreachContact` interface
2. Updated `EngagementStageHistory` to link to `outreach_contact_id`
3. Removed engagement stage fields from `Person` interface
4. Added `outreachContactsApi` for outreach contact CRUD

#### Components Updated
1. **PersonFormModal** (`frontend/src/components/PersonFormModal.tsx`)
   - Removed engagement stage, village, notes fields
   - Removed encounter fields (date, method, notes)
   - Reduced from 3 steps to 2 steps (Basic Info, Contact)
   - Updated form data interface

2. **PersonOnboardingWizard** (`frontend/src/components/PersonOnboardingWizard.tsx`)
   - Verified no outreach logic present (already clean)

3. **Admin People Page** (`frontend/src/app/admin/people/page.tsx`)
   - Added tab navigation (People / Outreach Contacts)
   - Removed village and date_registered columns from People table
   - Added Outreach Contacts table with stage, village, registered date
   - Added `fetchOutreachContacts()` function

4. **Missionary People Page** (`frontend/src/app/(missionary)/people/page.tsx`)
   - Changed state from `Person[]` to `OutreachContact[]`
   - Updated to use `outreachContactsApi.list()` instead of `peopleApi.list()`
   - Updated form submission to create OutreachContact instead of Person
   - Updated display to show `contact.person` nested data

---

## Verification Results

All 12 verification scenarios passed:

| Test | Description | Result |
|------|-------------|--------|
| 1 | Person model has no engagement_stage_id field | ✅ PASS |
| 2 | Person model has no date_registered field | ✅ PASS |
| 3 | Person model has no village field | ✅ PASS |
| 4 | OutreachContact table exists | ✅ PASS |
| 5 | OutreachContact has engagement_stage_id field | ✅ PASS |
| 6 | OutreachContact has date_registered field | ✅ PASS |
| 7 | OutreachContact has village field | ✅ PASS |
| 8 | EngagementStageHistory has outreach_contact_id field | ✅ PASS |
| 9 | EngagementStageHistory person_id is nullable | ✅ PASS |
| 10 | Person model has outreachContact relationship | ✅ PASS |
| 11 | OutreachContact model exists | ✅ PASS |
| 12 | OutreachContactController exists | ✅ PASS |

---

## Migration Status

- ✅ `php artisan migrate:fresh --seed` completed successfully
- ✅ All migrations applied without errors
- ✅ Database seeded with reference data (engagement stages, encounter methods)
- ✅ Campaign and site data seeded correctly
- ✅ 14 persons created in database
- ✅ 0 outreach contacts (as expected - no outreach data seeded yet)

---

## Key Design Decisions

1. **OutreachContact as the canonical outreach entity**
   - Engagement stages now belong to OutreachContact, not Person
   - A Person can have multiple OutreachContact records (different campaigns/contexts)
   - Separates permanent identity from temporary outreach journey

2. **EngagementStageHistory linkage**
   - Primary relationship to `outreach_contact_id`
   - Secondary reference to `person_id` (nullable) for historical queries
   - Cascade delete when OutreachContact is deleted

3. **Canonical skill/language storage**
   - Skills and languages stored in `person_skills` and `person_languages`
   - MissionaryProfile references these through Person relationship
   - Removed duplicate missionary-specific tables

4. **No automatic OutreachContact creation**
   - Person creation does not automatically create OutreachContact
   - OutreachContact must be explicitly created when needed
   - Prevents mixing domains

---

## Files Modified

### Database Migrations (9 files)
- `2026_11_03_020000_create_outreach_contacts_table.php` (created)
- `2026_11_03_020100_update_engagement_stage_history_to_outreach_contacts.php` (created)
- `2026_11_03_020200_remove_outreach_fields_from_people_table.php` (created)
- `2026_11_03_020300_remove_duplicate_missionary_tables.php` (created)
- `2026_11_03_010000_add_onboarding_fields_to_people_table.php`
- `2026_11_03_010002_create_person_addresses_table.php`
- `2026_11_03_010003_create_person_skills_table.php`
- `2026_11_03_010004_create_person_languages_table.php`
- `2026_11_03_010005_create_person_mission_experience_table.php`
- `2026_11_03_010006_create_person_training_table.php`
- `2026_11_01_120200_add_encounter_and_correction_fields_to_engagement_stage_history.php` (table name fix)
- `2026_11_01_120300_convert_engagement_stage_history_stages_to_foreign_keys.php` (table name fix)

### Backend Models (7 files)
- `app/Models/Person.php`
- `app/Models/OutreachContact.php` (created)
- `app/Models/EngagementStageHistory.php`
- `app/Models/OutreachEncounter.php`
- `app/Models/BaptismRecord.php`
- `app/Models/MissionaryProfile.php`

### Backend Controllers (6 files)
- `app/Http/Controllers/PersonController.php`
- `app/Http/Controllers/OnboardingController.php`
- `app/Http/Controllers/Auth/AuthController.php`
- `app/Http/Controllers/InvitationController.php`
- `app/Http/Controllers/OutreachEncounterController.php`
- `app/Http/Controllers/OutreachContactController.php` (created)

### Backend Policies (1 file)
- `app/Policies/OutreachContactPolicy.php` (created)

### Frontend Files (4 files)
- `frontend/src/lib/api.ts`
- `frontend/src/components/PersonFormModal.tsx`
- `frontend/src/app/admin/people/page.tsx`
- `frontend/src/app/(missionary)/people/page.tsx`

### Seeders (1 file)
- `database/seeders/DatabaseSeeder.php` (added EngagementStageSeeder call)

---

## Next Steps (Optional Enhancements)

1. **Create OutreachContact creation modal** for admin people page
2. **Add OutreachContact detail page** (`/admin/outreach-contacts/{id}`)
3. **Update missionary people detail page** to use OutreachContact data
4. **Add outreach contact statistics** to dashboard
5. **Create outreach contact import/export** functionality

---

## Conclusion

The refactor successfully separated Outreach Management from Missionary Management by:
- Creating a dedicated `OutreachContact` model for outreach journey tracking
- Removing engagement stage logic from the `Person` model
- Centralizing skills, languages, and training in canonical person tables
- Updating all controllers and frontend components to use the new structure
- Ensuring all verification scenarios pass

The codebase now has clear domain boundaries, making it easier to:
- Manage permanent person identity separately from temporary outreach journeys
- Track multiple outreach contexts for the same person
- Scale outreach management independently from missionary management
- Maintain data integrity through proper relationships and policies
