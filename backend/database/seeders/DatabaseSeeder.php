<?php

namespace Database\Seeders;

use App\Models\ActivityType;
use App\Models\Campaign;
use App\Models\FunctionalRoleType;
use App\Models\Language;
use App\Models\MissionaryProfile;
use App\Models\MissionSite;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Programme;
use App\Models\ProgrammeType;
use App\Models\ResourceCategory;
use App\Models\RolePermissionVersion;
use App\Models\SkillCategory;
use App\Models\SystemRole;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * UU Mission Database Seeder
 *
 * Seeds reference data, organizational hierarchy, and admin users.
 *
 * Data seeded:
 *  1. Org unit type taxonomy
 *  2. Org unit hierarchy: Uganda Union → Central Uganda Conference → Districts → Zones → Local Churches
 *  3. Programme types (all 5 ministries)
 *  4. Global Mission programme owned by Uganda Union
 *  5. RBAC: system roles and permissions
 *  6. Platform admin + key staff users with linked person records
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // ── 0. Reference data (seeded before campaign data) ─────────────────────
        $this->call(OutcomeTypeSeeder::class);
        $this->call(EncounterMethodSeeder::class);
        $this->call(EngagementStageSeeder::class);

        // ── 1. Skill categories ───────────────────────────────────────────────
        SkillCategory::firstOrCreate(['code' => 'evangelism'], ['label' => 'Evangelism',         'description' => 'Preaching, gospel presentations, personal witnessing', 'sort_order' => 10]);
        SkillCategory::firstOrCreate(['code' => 'music'], ['label' => 'Music',              'description' => 'Instrumental music, singing, choir leadership', 'sort_order' => 20]);
        SkillCategory::firstOrCreate(['code' => 'medical'], ['label' => 'Medical',            'description' => 'Healthcare, first aid, medical outreach', 'sort_order' => 30]);
        SkillCategory::firstOrCreate(['code' => 'construction'], ['label' => 'Construction',       'description' => 'Building, carpentry, masonry, maintenance', 'sort_order' => 40]);
        SkillCategory::firstOrCreate(['code' => 'teaching'], ['label' => 'Teaching',           'description' => 'Bible teaching, childrens ministry, education', 'sort_order' => 50]);
        SkillCategory::firstOrCreate(['code' => 'counselling'], ['label' => 'Counselling',        'description' => 'Pastoral counselling, spiritual guidance', 'sort_order' => 60]);
        SkillCategory::firstOrCreate(['code' => 'technical'], ['label' => 'Technical',          'description' => 'IT, audio/visual, electrical, technical support', 'sort_order' => 70]);
        SkillCategory::firstOrCreate(['code' => 'administrative'], ['label' => 'Administrative',     'description' => 'Office work, record keeping, coordination', 'sort_order' => 80]);
        SkillCategory::firstOrCreate(['code' => 'logistics'], ['label' => 'Logistics',          'description' => 'Transport, supplies management, logistics coordination', 'sort_order' => 90]);
        SkillCategory::firstOrCreate(['code' => 'translation'], ['label' => 'Translation',        'description' => 'Language translation, interpretation', 'sort_order' => 100]);
        SkillCategory::firstOrCreate(['code' => 'media'], ['label' => 'Media',              'description' => 'Photography, videography, social media', 'sort_order' => 110]);
        SkillCategory::firstOrCreate(['code' => 'youth_work'], ['label' => 'Youth Work',         'description' => 'Youth ministry, youth programmes', 'sort_order' => 120]);
        SkillCategory::firstOrCreate(['code' => 'childrens_work'], ['label' => "Children's Work",    'description' => "Children's ministry, VBS, kids programmes", 'sort_order' => 130]);

        // ── 2. Languages (Mission Translator integration) ─────────────────────
        Language::firstOrCreate(['code' => 'eng'], ['name' => 'English', 'native_name' => 'English', 'iso_639_1' => 'en', 'iso_639_3' => 'eng', 'supports_translation' => true, 'supports_stt' => true, 'supports_tts' => true, 'sort_order' => 10]);
        Language::firstOrCreate(['code' => 'lug'], ['name' => 'Luganda', 'native_name' => 'Luganda', 'iso_639_1' => 'lg', 'iso_639_3' => 'lug', 'supports_translation' => true, 'supports_stt' => true, 'supports_tts' => true, 'sort_order' => 20]);
        Language::firstOrCreate(['code' => 'ach'], ['name' => 'Acholi', 'native_name' => 'Acholi', 'iso_639_1' => null, 'iso_639_3' => 'ach', 'supports_translation' => true, 'supports_stt' => true, 'supports_tts' => true, 'sort_order' => 30]);
        Language::firstOrCreate(['code' => 'swa'], ['name' => 'Swahili', 'native_name' => 'Kiswahili', 'iso_639_1' => 'sw', 'iso_639_3' => 'swa', 'supports_translation' => true, 'supports_stt' => true, 'supports_tts' => true, 'sort_order' => 40]);
        Language::firstOrCreate(['code' => 'nyn'], ['name' => 'Runyankole', 'native_name' => 'Runyankole', 'iso_639_1' => null, 'iso_639_3' => 'nyn', 'supports_translation' => true, 'supports_stt' => true, 'supports_tts' => true, 'sort_order' => 50]);
        Language::firstOrCreate(['code' => 'laj'], ['name' => 'Lango', 'native_name' => 'Lango', 'iso_639_1' => null, 'iso_639_3' => 'laj', 'supports_translation' => true, 'supports_stt' => false, 'supports_tts' => false, 'sort_order' => 60]);

        // ── 3. Org unit type taxonomy ─────────────────────────────────────────
        $typeUnion = OrgUnitType::firstOrCreate(['code' => 'union'], ['label' => 'Union',              'sort_order' => 10, 'can_have_children' => true]);
        $typeConf = OrgUnitType::firstOrCreate(['code' => 'conference'], ['label' => 'Conference',          'sort_order' => 20, 'can_have_children' => true]);
        $typeMission = OrgUnitType::firstOrCreate(['code' => 'mission_field'], ['label' => 'Mission Field',       'sort_order' => 25, 'can_have_children' => true]);
        $typeDist = OrgUnitType::firstOrCreate(['code' => 'district'], ['label' => 'District',            'sort_order' => 30, 'can_have_children' => true]);
        $typeZone = OrgUnitType::firstOrCreate(['code' => 'zone'], ['label' => 'Zone',                'sort_order' => 35, 'can_have_children' => true]);
        $typeChurch = OrgUnitType::firstOrCreate(['code' => 'local_church'], ['label' => 'Local Church',        'sort_order' => 40, 'can_have_children' => false]);
        $typeClub = OrgUnitType::firstOrCreate(['code' => 'club'], ['label' => 'Club / Youth Group',  'sort_order' => 45, 'can_have_children' => false]);
        OrgUnitType::firstOrCreate(['code' => 'international_partner'], ['label' => 'International Partner', 'sort_order' => 50, 'can_have_children' => false]);

        // ── 4. Org unit hierarchy ─────────────────────────────────────────────
        $uu = OrgUnit::firstOrCreate(
            ['short_code' => 'UUM'],
            [
                'org_unit_type_id' => $typeUnion->id,
                'name' => 'Uganda Union Mission',
                'country_code' => 'UG',
            ]
        );

        $cuc = OrgUnit::firstOrCreate(
            ['short_code' => 'CUC'],
            [
                'org_unit_type_id' => $typeConf->id,
                'parent_id' => $uu->id,
                'name' => 'Central Uganda Conference',
                'country_code' => 'UG',
            ]
        );

        $liraDistrict = OrgUnit::firstOrCreate(
            ['short_code' => 'LDA'],
            [
                'org_unit_type_id' => $typeDist->id,
                'parent_id' => $cuc->id,
                'name' => 'Lira District',
                'country_code' => 'UG',
            ]
        );

        $katoDistrict = OrgUnit::firstOrCreate(
            ['short_code' => 'KDA'],
            [
                'org_unit_type_id' => $typeDist->id,
                'parent_id' => $cuc->id,
                'name' => 'Kato District',
                'country_code' => 'UG',
            ]
        );

        // ── East Kampala Zone Hierarchy ───────────────────────────────────────
        // Validate that CUC exists and is a Conference before creating children
        if (! $cuc || $cuc->org_unit_type_id !== $typeConf->id) {
            throw new \Exception('Central Uganda Conference (CUC) not found or not a Conference type. Cannot create East Kampala Zone hierarchy.');
        }

        // East Kampala Zone (EKZ) under CUC
        $ekz = OrgUnit::firstOrCreate(
            ['short_code' => 'EKZ'],
            [
                'org_unit_type_id' => $typeZone->id,
                'parent_id' => $cuc->id,
                'name' => 'East Kampala Zone',
                'country_code' => 'UG',
            ]
        );

        // Validate EKZ was created/reused correctly
        if ($ekz->parent_id !== $cuc->id || $ekz->org_unit_type_id !== $typeZone->id) {
            throw new \Exception('East Kampala Zone (EKZ) exists but has incorrect parent or type. Expected parent: CUC, type: Zone.');
        }

        // Kireka Town District (KTD) under EKZ
        $ktd = OrgUnit::firstOrCreate(
            ['short_code' => 'KTD'],
            [
                'org_unit_type_id' => $typeDist->id,
                'parent_id' => $ekz->id,
                'name' => 'Kireka Town District',
                'country_code' => 'UG',
            ]
        );

        // Validate KTD was created/reused correctly
        if ($ktd->parent_id !== $ekz->id || $ktd->org_unit_type_id !== $typeDist->id) {
            throw new \Exception('Kireka Town District (KTD) exists but has incorrect parent or type. Expected parent: EKZ, type: District.');
        }

        // Local Churches under KTD
        $churches = [
            ['short_code' => 'KTC', 'name' => 'Kireka Town SDA Church'],
            ['short_code' => 'NAA', 'name' => 'Naalya SDA Church'],
            ['short_code' => 'MBA', 'name' => 'Mbalwa SDA Church'],
            ['short_code' => 'KIR', 'name' => 'Kira SDA Church'],
            ['short_code' => 'NAM', 'name' => 'Namugongo SDA Church'],
            ['short_code' => 'KIT', 'name' => 'Kito SDA Church'],
            ['short_code' => 'NSA', 'name' => 'Nsasa SDA Church'],
            ['short_code' => 'KWL', 'name' => 'Kiwologoma SDA Church'],
        ];

        foreach ($churches as $churchData) {
            $church = OrgUnit::firstOrCreate(
                ['short_code' => $churchData['short_code']],
                [
                    'org_unit_type_id' => $typeChurch->id,
                    'parent_id' => $ktd->id,
                    'name' => $churchData['name'],
                    'country_code' => 'UG',
                ]
            );

            // Validate church was created/reused correctly
            if ($church->parent_id !== $ktd->id || $church->org_unit_type_id !== $typeChurch->id) {
                throw new \Exception("Church {$churchData['name']} ({$churchData['short_code']}) exists but has incorrect parent or type. Expected parent: KTD, type: Local Church.");
            }
        }

        // ── Bweyogerere District Hierarchy ─────────────────────────────────────
        // Bweyogerere District (BWD) under EKZ
        $bwd = OrgUnit::firstOrCreate(
            ['short_code' => 'BWD'],
            [
                'org_unit_type_id' => $typeDist->id,
                'parent_id' => $ekz->id,
                'name' => 'Bweyogerere District',
                'country_code' => 'UG',
            ]
        );

        // Validate BWD was created/reused correctly
        if ($bwd->parent_id !== $ekz->id || $bwd->org_unit_type_id !== $typeDist->id) {
            throw new \Exception('Bweyogerere District (BWD) exists but has incorrect parent or type. Expected parent: EKZ, type: District.');
        }

        // Local Churches under BWD
        $bweyogerereChurches = [
            ['short_code' => 'BWE', 'name' => 'Bweyogerere SDA Church'],
            ['short_code' => 'BKE', 'name' => 'Bukasa-English SDA Church'],
            ['short_code' => 'BKL', 'name' => 'Bukasa-Luganda SDA Church'],
            ['short_code' => 'KAZ', 'name' => 'Kazinga–Bweyogerere SDA Church'],
            ['short_code' => 'KRE', 'name' => 'Kireku SDA Church'],
            ['short_code' => 'KRY', 'name' => 'Kirinya–Bweyogerere SDA Church'],
            ['short_code' => 'NBL', 'name' => 'Namboole SDA Church'],
        ];

        foreach ($bweyogerereChurches as $churchData) {
            $church = OrgUnit::firstOrCreate(
                ['short_code' => $churchData['short_code']],
                [
                    'org_unit_type_id' => $typeChurch->id,
                    'parent_id' => $bwd->id,
                    'name' => $churchData['name'],
                    'country_code' => 'UG',
                ]
            );

            // Validate church was created/reused correctly
            if ($church->parent_id !== $bwd->id || $church->org_unit_type_id !== $typeChurch->id) {
                throw new \Exception("Church {$churchData['name']} ({$churchData['short_code']}) exists but has incorrect parent or type. Expected parent: BWD, type: Local Church.");
            }
        }

        // ── Luzira District Hierarchy ─────────────────────────────────────────
        // Luzira District (LUZ) under EKZ
        $luz = OrgUnit::firstOrCreate(
            ['short_code' => 'LUZ'],
            [
                'org_unit_type_id' => $typeDist->id,
                'parent_id' => $ekz->id,
                'name' => 'Luzira District',
                'country_code' => 'UG',
            ]
        );

        // Validate LUZ was created/reused correctly
        if ($luz->parent_id !== $ekz->id || $luz->org_unit_type_id !== $typeDist->id) {
            throw new \Exception('Luzira District (LUZ) exists but has incorrect parent or type. Expected parent: EKZ, type: District.');
        }

        // Local Churches under LUZ
        $luziraChurches = [
            ['short_code' => 'BRK', 'name' => 'Brooklyn SDA Church'],
            ['short_code' => 'KTL', 'name' => 'Kitintale SDA Church'],
            ['short_code' => 'NAJ', 'name' => 'Najjeera SDA Church'],
            ['short_code' => 'KWT', 'name' => 'Kiwatule SDA Church'],
            ['short_code' => 'KKG', 'name' => 'Kkungu SDA Church'],
            ['short_code' => 'BWT', 'name' => 'Buwaate SDA Church'],
            ['short_code' => 'NTD', 'name' => 'Ntinda SDA Church'],
            ['short_code' => 'NGR', 'name' => 'Naguru SDA Church'],
            ['short_code' => 'BGL', 'name' => 'Bugolobi SDA Church'],
            ['short_code' => 'NKW', 'name' => 'Nakawa SDA Church'],
            ['short_code' => 'MBU', 'name' => 'Mbuya SDA Church'],
            ['short_code' => 'KRM', 'name' => 'Kirombe SDA Church'],
        ];

        foreach ($luziraChurches as $churchData) {
            $church = OrgUnit::firstOrCreate(
                ['short_code' => $churchData['short_code']],
                [
                    'org_unit_type_id' => $typeChurch->id,
                    'parent_id' => $luz->id,
                    'name' => $churchData['name'],
                    'country_code' => 'UG',
                ]
            );

            // Validate church was created/reused correctly
            if ($church->parent_id !== $luz->id || $church->org_unit_type_id !== $typeChurch->id) {
                throw new \Exception("Church {$churchData['name']} ({$churchData['short_code']}) exists but has incorrect parent or type. Expected parent: LUZ, type: Local Church.");
            }
        }

        // ── Kanyanya District Hierarchy ────────────────────────────────────────
        // Kanyanya District (KAN) under EKZ
        $kan = OrgUnit::firstOrCreate(
            ['short_code' => 'KAN'],
            [
                'org_unit_type_id' => $typeDist->id,
                'parent_id' => $ekz->id,
                'name' => 'Kanyanya District',
                'country_code' => 'UG',
            ]
        );

        // Validate KAN was created/reused correctly
        if ($kan->parent_id !== $ekz->id || $kan->org_unit_type_id !== $typeDist->id) {
            throw new \Exception('Kanyanya District (KAN) exists but has incorrect parent or type. Expected parent: EKZ, type: District.');
        }

        // Kanyanya SDA Church under KAN
        $kanyanyaChurch = OrgUnit::firstOrCreate(
            ['short_code' => 'KNY'],
            [
                'org_unit_type_id' => $typeChurch->id,
                'parent_id' => $kan->id,
                'name' => 'Kanyanya SDA Church',
                'country_code' => 'UG',
            ]
        );

        // Validate church was created/reused correctly
        if ($kanyanyaChurch->parent_id !== $kan->id || $kanyanyaChurch->org_unit_type_id !== $typeChurch->id) {
            throw new \Exception('Kanyanya SDA Church (KNY) exists but has incorrect parent or type. Expected parent: KAN, type: Local Church.');
        }

        // ── Kireka District Hierarchy ───────────────────────────────────────────
        // Kireka District (KIR-D) under EKZ
        $kirDist = OrgUnit::firstOrCreate(
            ['short_code' => 'KIR-D'],
            [
                'org_unit_type_id' => $typeDist->id,
                'parent_id' => $ekz->id,
                'name' => 'Kireka District',
                'country_code' => 'UG',
            ]
        );

        // Validate KIR-D was created/reused correctly
        if ($kirDist->parent_id !== $ekz->id || $kirDist->org_unit_type_id !== $typeDist->id) {
            throw new \Exception('Kireka District (KIR-D) exists but has incorrect parent or type. Expected parent: EKZ, type: District.');
        }

        // Local Churches under Kireka District
        $kirekaChurches = [
            ['short_code' => 'KHI', 'name' => 'Kireka Hill SDA Church'],
            ['short_code' => 'BDK', 'name' => 'Banda–Kireka SDA Church'],
            ['short_code' => 'KTK', 'name' => 'Kito Kireka SDA Church'],
            ['short_code' => 'KNU', 'name' => 'Kinnawataka Upper SDA Church'],
            ['short_code' => 'KNL', 'name' => 'Kinawataka Lower SDA Church'],
            ['short_code' => 'KYB', 'name' => 'Kyambogo SDA Church'],
        ];

        foreach ($kirekaChurches as $churchData) {
            $church = OrgUnit::firstOrCreate(
                ['short_code' => $churchData['short_code']],
                [
                    'org_unit_type_id' => $typeChurch->id,
                    'parent_id' => $kirDist->id,
                    'name' => $churchData['name'],
                    'country_code' => 'UG',
                ]
            );

            // Validate church was created/reused correctly
            if ($church->parent_id !== $kirDist->id || $church->org_unit_type_id !== $typeChurch->id) {
                throw new \Exception("Church {$churchData['name']} ({$churchData['short_code']}) exists but has incorrect parent or type. Expected parent: KIR-D, type: Local Church.");
            }
        }

        // ── Kyanja District Hierarchy ─────────────────────────────────────────
        // Kyanja District (KYD) under EKZ
        $kyd = OrgUnit::firstOrCreate(
            ['short_code' => 'KYD'],
            [
                'org_unit_type_id' => $typeDist->id,
                'parent_id' => $ekz->id,
                'name' => 'Kyanja District',
                'country_code' => 'UG',
            ]
        );

        // Validate KYD was created/reused correctly
        if ($kyd->parent_id !== $ekz->id || $kyd->org_unit_type_id !== $typeDist->id) {
            throw new \Exception('Kyanja District (KYD) exists but has incorrect parent or type. Expected parent: EKZ, type: District.');
        }

        // Local Churches under Kyanja District
        $kyanjaChurches = [
            ['short_code' => 'KYA', 'name' => 'Kyanja SDA Church'],
            ['short_code' => 'BUK', 'name' => 'Bulamu–Kyanja SDA Church'],
        ];

        foreach ($kyanjaChurches as $churchData) {
            $church = OrgUnit::firstOrCreate(
                ['short_code' => $churchData['short_code']],
                [
                    'org_unit_type_id' => $typeChurch->id,
                    'parent_id' => $kyd->id,
                    'name' => $churchData['name'],
                    'country_code' => 'UG',
                ]
            );

            // Validate church was created/reused correctly
            if ($church->parent_id !== $kyd->id || $church->org_unit_type_id !== $typeChurch->id) {
                throw new \Exception("Church {$churchData['name']} ({$churchData['short_code']}) exists but has incorrect parent or type. Expected parent: KYD, type: Local Church.");
            }
        }

        // ── Gayaza District Hierarchy ─────────────────────────────────────────
        // Gayaza District (GAY) under EKZ
        $gay = OrgUnit::firstOrCreate(
            ['short_code' => 'GAY'],
            [
                'org_unit_type_id' => $typeDist->id,
                'parent_id' => $ekz->id,
                'name' => 'Gayaza District',
                'country_code' => 'UG',
            ]
        );

        // Validate GAY was created/reused correctly
        if ($gay->parent_id !== $ekz->id || $gay->org_unit_type_id !== $typeDist->id) {
            throw new \Exception('Gayaza District (GAY) exists but has incorrect parent or type. Expected parent: EKZ, type: District.');
        }

        // Local Churches under Gayaza District
        $gayazaChurches = [
            ['short_code' => 'GYZ', 'name' => 'Gayaza SDA Church'],
            ['short_code' => 'GGU', 'name' => 'Gguluddene SDA Church'],
            ['short_code' => 'GIT', 'name' => 'Gitta SDA Church'],
            ['short_code' => 'MAN', 'name' => 'Manyangwa SDA Church'],
            ['short_code' => 'KIK', 'name' => 'Kikoko SDA Church'],
            ['short_code' => 'KAS', 'name' => 'Kasozi SDA Church'],
            ['short_code' => 'KJB', 'name' => 'Kijabijo SDA Church'],
            ['short_code' => 'NAL', 'name' => 'Nalusugga SDA Church'],
            ['short_code' => 'NMY', 'name' => 'Namayina SDA Church'],
            ['short_code' => 'NML', 'name' => 'Namulonge SDA Church'],
        ];

        foreach ($gayazaChurches as $churchData) {
            $church = OrgUnit::firstOrCreate(
                ['short_code' => $churchData['short_code']],
                [
                    'org_unit_type_id' => $typeChurch->id,
                    'parent_id' => $gay->id,
                    'name' => $churchData['name'],
                    'country_code' => 'UG',
                ]
            );

            // Validate church was created/reused correctly
            if ($church->parent_id !== $gay->id || $church->org_unit_type_id !== $typeChurch->id) {
                throw new \Exception("Church {$churchData['name']} ({$churchData['short_code']}) exists but has incorrect parent or type. Expected parent: GAY, type: Local Church.");
            }
        }

        // ── 5. Programme types ────────────────────────────────────────────────
        $ptGlobal = ProgrammeType::firstOrCreate(['code' => 'global_mission'], ['label' => 'Global Mission',          'ministry_owner' => 'Ministerial / General Field']);
        $ptYfc = ProgrammeType::firstOrCreate(['code' => 'youth_for_christ'], ['label' => 'Youth for Christ',        'ministry_owner' => 'Youth Ministries']);
        $ptWomens = ProgrammeType::firstOrCreate(['code' => 'womens_thrust'], ['label' => "Women's Thrust",          'ministry_owner' => "Women's Ministries"]);
        $ptMens = ProgrammeType::firstOrCreate(['code' => 'mens_thrust'], ['label' => "Men's Thrust",            'ministry_owner' => "Men's Ministries"]);
        $ptChildren = ProgrammeType::firstOrCreate(['code' => 'children_for_christ'], ['label' => 'Children for Christ',   'ministry_owner' => "Children's Ministries"]);

        // ── 6. Programme ──────────────────────────────────────────────────────
        $programme = Programme::firstOrCreate(
            ['name' => 'Uganda Union Global Mission Initiative'],
            [
                'programme_type_id' => $ptGlobal->id,
                'owning_org_unit_id' => $uu->id,
                'description' => 'The standing mandate to reach unreached and under-reached communities through coordinated, multi-conference evangelistic campaigns.',
                'status' => 'active',
            ]
        );

        // ── 6b. Demo campaign and mission site ────────────────────────────────
        // A campaign with one mission site so the deployment and site-scoped
        // workflows can be exercised out of the box. Idempotent, so re-seeding
        // never creates duplicates.
        $demoCampaign = Campaign::firstOrCreate(
            ['name' => 'Central Uganda Conference Evangelistic Campaign'],
            [
                'programme_id'      => $programme->id,
                'lead_org_unit_id'  => $cuc->id,
                'description'       => 'Demonstration campaign for the Central Uganda Conference.',
                'status'            => 'active',
            ]
        );

        $demoSite = MissionSite::firstOrCreate(
            [
                'campaign_id' => $demoCampaign->id,
                'name'        => 'Lira Town Mission Site',
            ],
            [
                'description' => 'Demonstration mission site.',
                'status'      => 'active',
            ]
        );

        // ── 7. RBAC — roles and permissions ───────────────────────────────────
        $roles = $this->seedRolesAndPermissions();

        // ── 8. Users and persons ──────────────────────────────────────────────
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@uumission.org'],
            [
                'password' => Hash::make('Admin@2026!'),
                'status' => 'active',
            ]
        );
        Person::firstOrCreate(
            ['user_id' => $adminUser->id],
            [
                'first_name' => 'Platform',
                'last_name' => 'Administrator',
                'gender' => 'unspecified',
            ]
        );

        $directorUser = User::firstOrCreate(
            ['email' => 'director@uumission.org'],
            [
                'password' => Hash::make('Director@2026!'),
                'status' => 'active',
            ]
        );
        $directorPerson = Person::firstOrCreate(
            ['user_id' => $directorUser->id],
            [
                'first_name' => 'Emmanuel',
                'last_name' => 'Okelomole',
                'gender' => 'male',
            ]
        );

        $coordUser = User::firstOrCreate(
            ['email' => 'lira.coordinator@uumission.org'],
            [
                'password' => Hash::make('Coord@2026!'),
                'status' => 'active',
            ]
        );
        $coordPerson = Person::firstOrCreate(
            ['user_id' => $coordUser->id],
            [
                'first_name' => 'Grace',
                'last_name' => 'Acen',
                'gender' => 'female',
            ]
        );

        $secUser = User::firstOrCreate(
            ['email' => 'secretary@uumission.org'],
            [
                'password' => Hash::make('Secretary@2026!'),
                'status' => 'active',
            ]
        );
        $secPerson = Person::firstOrCreate(
            ['user_id' => $secUser->id],
            [
                'first_name' => 'Joseph',
                'last_name' => 'Okello',
                'gender' => 'male',
            ]
        );

        // Missionary profiles for staff users
        foreach ([$directorPerson, $coordPerson, $secPerson] as $person) {
            MissionaryProfile::firstOrCreate(
                ['person_id' => $person->id],
                [
                    'home_org_unit_id' => $cuc->id,
                    'deployment_status' => 'available',
                ]
            );
        }

        // ── 9. Activity types ────────────────────────────────────────────────
        ActivityType::firstOrCreate(['code' => 'evangelistic_meeting'], ['label' => 'Evangelistic Meeting',    'category' => 'outreach',  'requires_attendance_register' => true,  'expected_duration_minutes' => 90]);
        ActivityType::firstOrCreate(['code' => 'bible_study'], ['label' => 'Bible Study Group',       'category' => 'outreach',  'requires_attendance_register' => true,  'expected_duration_minutes' => 60]);
        ActivityType::firstOrCreate(['code' => 'prayer_walk'], ['label' => 'Prayer Walk',             'category' => 'outreach',  'requires_attendance_register' => false, 'expected_duration_minutes' => 60]);
        ActivityType::firstOrCreate(['code' => 'health_camp'], ['label' => 'Health Camp',             'category' => 'welfare',   'requires_attendance_register' => true,  'expected_duration_minutes' => 180]);
        ActivityType::firstOrCreate(['code' => 'childrens_programme'], ['label' => "Children's Programme",    'category' => 'outreach',  'requires_attendance_register' => true,  'expected_duration_minutes' => 60]);
        ActivityType::firstOrCreate(['code' => 'community_service'], ['label' => 'Community Service',       'category' => 'welfare',   'requires_attendance_register' => false, 'expected_duration_minutes' => 120]);
        ActivityType::firstOrCreate(['code' => 'follow_up_visit'], ['label' => 'Follow-up Visit',         'category' => 'outreach',  'requires_attendance_register' => false, 'expected_duration_minutes' => 45]);
        ActivityType::firstOrCreate(['code' => 'worship_service'], ['label' => 'Worship Service',         'category' => 'worship',   'requires_attendance_register' => true,  'expected_duration_minutes' => 90]);
        ActivityType::firstOrCreate(['code' => 'media_outreach'], ['label' => 'Media / Open-Air Audio',  'category' => 'outreach',  'requires_attendance_register' => false, 'expected_duration_minutes' => 60]);
        ActivityType::firstOrCreate(['code' => 'coordinator_briefing'], ['label' => 'Coordinator Briefing',    'category' => 'administration', 'requires_attendance_register' => true, 'expected_duration_minutes' => 30]);

        // ── 10. Functional role types ─────────────────────────────────────────
        FunctionalRoleType::firstOrCreate(['code' => 'site_coordinator'], ['label' => 'Site Coordinator',  'description' => 'Overall site leadership and coordination']);
        FunctionalRoleType::firstOrCreate(['code' => 'preacher'], ['label' => 'Preacher',           'description' => 'Primary preaching and evangelism']);
        FunctionalRoleType::firstOrCreate(['code' => 'bible_worker'], ['label' => 'Bible Worker',       'description' => 'Bible study and discipleship']);
        FunctionalRoleType::firstOrCreate(['code' => 'translator'], ['label' => 'Translator',         'description' => 'Language translation for meetings']);
        FunctionalRoleType::firstOrCreate(['code' => 'music_team'], ['label' => 'Music Team',         'description' => 'Music and worship leadership']);
        FunctionalRoleType::firstOrCreate(['code' => 'prayer_team'], ['label' => 'Prayer Team',        'description' => 'Prayer ministry and intercession']);
        FunctionalRoleType::firstOrCreate(['code' => 'health_team'], ['label' => 'Health Team',        'description' => 'Medical outreach and health education']);
        FunctionalRoleType::firstOrCreate(['code' => 'childrens_worker'], ['label' => "Children's Worker",  'description' => "Children's ministry and programmes"]);
        FunctionalRoleType::firstOrCreate(['code' => 'youth_worker'], ['label' => 'Youth Worker',       'description' => 'Youth ministry and outreach']);
        FunctionalRoleType::firstOrCreate(['code' => 'logistics'], ['label' => 'Logistics',          'description' => 'Site logistics and supplies management']);

        // ── 10b. Resource categories ─────────────────────────────────────────
        ResourceCategory::firstOrCreate(['code' => 'bible_study'], ['label' => 'Bible Study', 'description' => 'Bible study and discipleship materials', 'sort_order' => 10, 'is_active' => true]);
        ResourceCategory::firstOrCreate(['code' => 'evangelism'], ['label' => 'Evangelism', 'description' => 'Outreach and evangelism resources', 'sort_order' => 20, 'is_active' => true]);
        ResourceCategory::firstOrCreate(['code' => 'mission_training'], ['label' => 'Mission Training', 'description' => 'Missionary training and orientation materials', 'sort_order' => 30, 'is_active' => true]);
        ResourceCategory::firstOrCreate(['code' => 'worship'], ['label' => 'Worship', 'description' => 'Worship services, music, and liturgy resources', 'sort_order' => 40, 'is_active' => true]);
        ResourceCategory::firstOrCreate(['code' => 'administration'], ['label' => 'Administration', 'description' => 'Organisational administration and policy documents', 'sort_order' => 50, 'is_active' => true]);
        ResourceCategory::firstOrCreate(['code' => 'childrens_ministry'], ['label' => "Children's Ministry", 'description' => "Children's ministry curriculum and activities", 'sort_order' => 60, 'is_active' => true]);
        ResourceCategory::firstOrCreate(['code' => 'youth_ministry'], ['label' => 'Youth Ministry', 'description' => 'Youth ministry programmes and resources', 'sort_order' => 70, 'is_active' => true]);
        ResourceCategory::firstOrCreate(['code' => 'health_welfare'], ['label' => 'Health & Welfare', 'description' => 'Medical outreach and welfare programme materials', 'sort_order' => 80, 'is_active' => true]);
        ResourceCategory::firstOrCreate(['code' => 'prayer'], ['label' => 'Prayer', 'description' => 'Prayer guides and intercession resources', 'sort_order' => 90, 'is_active' => true]);
        ResourceCategory::firstOrCreate(['code' => 'general'], ['label' => 'General', 'description' => 'General mission resources and references', 'sort_order' => 100, 'is_active' => true]);

        // ── 11. Role assignments for admin users ─────────────────────────────────
        // Admin gets platform_admin role (global)
        UserRoleAssignment::updateOrCreate(
            [
                'user_id' => $adminUser->id,
                'system_role_id' => $roles['platform_admin']->id,
            ],
            [
                'scope_type' => 'global',
                'valid_from' => null,
                'valid_until' => null,
                'granted_by_user_id' => $adminUser->id,
            ]
        );

        // Director gets union_director role (scoped to Uganda Union)
        UserRoleAssignment::updateOrCreate(
            [
                'user_id' => $directorUser->id,
                'system_role_id' => $roles['union_director']->id,
                'scoped_org_unit_id' => $uu->id,
            ],
            [
                'scope_type' => 'org_unit',
                'valid_from' => null,
                'valid_until' => null,
                'granted_by_user_id' => $adminUser->id,
            ]
        );

        // Coordinator gets union_director role (scoped to Central Uganda Conference)
        UserRoleAssignment::updateOrCreate(
            [
                'user_id' => $coordUser->id,
                'system_role_id' => $roles['union_director']->id,
                'scoped_org_unit_id' => $cuc->id,
            ],
            [
                'scope_type' => 'org_unit',
                'valid_from' => null,
                'valid_until' => null,
                'granted_by_user_id' => $adminUser->id,
            ]
        );

        // Secretary gets the site_coordinator role, scoped to the demo SITE.
        // The scope is drawn from the role's own scope_type ('site'), so a
        // site-scoped role must point at a mission site, never at an org unit.
        UserRoleAssignment::updateOrCreate(
            [
                'user_id' => $secUser->id,
                'system_role_id' => $roles['site_coordinator']->id,
                'scoped_site_id' => $demoSite->id,
            ],
            [
                'scope_type' => 'site',
                'valid_from' => null,
                'valid_until' => null,
                'granted_by_user_id' => $adminUser->id,
            ]
        );

        $this->command->info('✅ UU Mission database seeded:');
        $this->command->info('   • Org hierarchy: Uganda Union → Conferences → Zones → Districts → Local Churches');
        $this->command->info('   • '.Person::count().' persons in database');
        $this->command->info('   • '.User::count().' user accounts');
        $this->command->info('   • '.ActivityType::count().' activity types');
        $this->command->info('   • '.SystemRole::count().' system roles, '.Permission::count().' permissions');
        $this->command->info('   • '.UserRoleAssignment::count().' role assignments');
    }

    // ── RBAC ──────────────────────────────────────────────────────────────────

    private function seedRolesAndPermissions(): array
    {
        // Permissions — grouped by module
        $permissions = [
            // admin module
            ['code' => 'admin.users.manage',      'label' => 'Manage user accounts',             'module' => 'admin'],
            ['code' => 'admin.roles.manage',       'label' => 'Manage roles and permissions',     'module' => 'admin'],
            ['code' => 'admin.org.manage',         'label' => 'Manage org unit hierarchy',         'module' => 'admin'],

            // org module
            ['code' => 'org.view',                 'label' => 'View organisational structure',   'module' => 'org'],
            ['code' => 'org.manage',               'label' => 'Manage organisational structure', 'module' => 'org'],

            // campaigns module
            ['code' => 'campaigns.create',         'label' => 'Create campaigns',                 'module' => 'campaigns'],
            ['code' => 'campaigns.edit',           'label' => 'Edit campaign details',            'module' => 'campaigns'],
            ['code' => 'campaigns.view',           'label' => 'View campaigns',                   'module' => 'campaigns'],
            ['code' => 'campaigns.archive',        'label' => 'Archive completed campaigns',       'module' => 'campaigns'],

            // sites module
            ['code' => 'sites.create',             'label' => 'Create mission sites',             'module' => 'sites'],
            ['code' => 'sites.edit',               'label' => 'Edit site details',                'module' => 'sites'],
            ['code' => 'sites.view',               'label' => 'View mission sites',               'module' => 'sites'],

            // assignments module
            ['code' => 'assignments.manage',       'label' => 'Manage missionary assignments',    'module' => 'assignments'],
            ['code' => 'assignments.view',         'label' => 'View assignments',                 'module' => 'assignments'],

            // activities module (scheduled field events)
            ['code' => 'activities.view',          'label' => 'View scheduled activities',        'module' => 'activities'],
            ['code' => 'activities.create',        'label' => 'Create scheduled activities',      'module' => 'activities'],
            ['code' => 'activities.update',        'label' => 'Edit scheduled activities',        'module' => 'activities'],
            ['code' => 'activities.delete',        'label' => 'Remove scheduled activities',      'module' => 'activities'],

            // attendance module
            ['code' => 'attendance.submit',        'label' => 'Submit attendance registers',      'module' => 'attendance'],
            ['code' => 'attendance.verify',        'label' => 'Verify attendance registers',      'module' => 'attendance'],
            ['code' => 'attendance.view',          'label' => 'View attendance data',             'module' => 'attendance'],

            // reporting module
            ['code' => 'report.submit',            'label' => 'Submit field reports',             'module' => 'reporting'],
            ['code' => 'report.review',            'label' => 'Review and approve reports',       'module' => 'reporting'],
            ['code' => 'report.view',              'label' => 'View field reports',               'module' => 'reporting'],
            // Narrow, team-scoped visibility of the DEVELOPING report. Granted
            // only to roles appointed at team scope, so joining a team never
            // confers the broad official report.view permission.
            ['code' => 'report.view_team',         'label' => 'View the team developing report',  'module' => 'reporting'],

            // outcomes module
            ['code' => 'outcomes.record',          'label' => 'Record mission outcomes',          'module' => 'outcomes'],
            ['code' => 'outcomes.view',            'label' => 'View mission outcomes',            'module' => 'outcomes'],
            ['code' => 'outcomes.manage_followup', 'label' => 'Manage follow-up actions',         'module' => 'outcomes'],

            // people module (permanent identity management - admin/staff only)
            ['code' => 'people.create',            'label' => 'Register new persons',             'module' => 'people'],
            ['code' => 'people.edit',              'label' => 'Edit person profiles',             'module' => 'people'],
            ['code' => 'people.view',              'label' => 'View person profiles',             'module' => 'people'],

            // outreach-contacts module (outreach journey tracking - missionaries)
            ['code' => 'outreach-contacts.create', 'label' => 'Register outreach contacts',      'module' => 'outreach-contacts'],
            ['code' => 'outreach-contacts.edit',   'label' => 'Edit outreach contacts',          'module' => 'outreach-contacts'],
            ['code' => 'outreach-contacts.view',   'label' => 'View outreach contacts',          'module' => 'outreach-contacts'],

            // resources module (missionary resource library)
            ['code' => 'resources.view',          'label' => 'View missionary resources',       'module' => 'resources'],
            ['code' => 'resources.create',        'label' => 'Create resource drafts',          'module' => 'resources'],
            ['code' => 'resources.manage',        'label' => 'Manage resource metadata',         'module' => 'resources'],
            ['code' => 'resources.distribute',    'label' => 'Submit resources for distribution', 'module' => 'resources'],
            ['code' => 'resources.approve_distribute', 'label' => 'Approve resource distribution', 'module' => 'resources'],
            ['code' => 'resources.publish',       'label' => 'Approve and publish resources',   'module' => 'resources'],
            ['code' => 'resources.archive',       'label' => 'Archive resources',               'module' => 'resources'],

            // communications module (mission communication centre)
            ['code' => 'communications.view',     'label' => 'View mission communications',     'module' => 'communications'],
            ['code' => 'communications.publish',  'label' => 'Publish mission communications',  'module' => 'communications'],
            ['code' => 'communications.acknowledge', 'label' => 'Acknowledge mission instructions', 'module' => 'communications'],
            ['code' => 'communications.revoke',   'label' => 'Revoke mission communications',   'module' => 'communications'],
        ];

        $permMap = [];
        foreach ($permissions as $pData) {
            $permMap[$pData['code']] = Permission::firstOrCreate(
                ['code' => $pData['code']],
                ['label' => $pData['label'], 'module' => $pData['module']]
            );
        }

        // Roles with permission sets
        $roleData = [
            [
                'code' => 'platform_admin',
                'label' => 'Platform Administrator',
                'scope_type' => 'global',
                'perms' => array_keys($permMap), // all permissions
            ],
            [
                'code' => 'union_director',
                'label' => 'Union Mission Director',
                'scope_type' => 'org_unit',
                'perms' => ['campaigns.create', 'campaigns.edit', 'campaigns.view', 'campaigns.archive',
                    'sites.create', 'sites.edit', 'sites.view',
                    'assignments.manage', 'assignments.view',
                    'activities.view',
                    'report.review', 'report.view',
                    'org.view', 'org.manage',
                    'resources.view', 'resources.create', 'resources.manage', 'resources.publish', 'resources.archive',
                    'resources.distribute', 'resources.approve_distribute',
                    'communications.view', 'communications.publish', 'communications.acknowledge', 'communications.revoke'],
            ],
            [
                'code' => 'campaign_leader',
                'label' => 'Campaign Leader',
                'scope_type' => 'campaign',
                'perms' => ['campaigns.view', 'campaigns.edit',
                    'sites.create', 'sites.edit', 'sites.view',
                    'assignments.manage', 'assignments.view',
                    'activities.view', 'activities.create', 'activities.update', 'activities.delete',
                    'report.review', 'report.view',
                    'outcomes.record', 'outcomes.view',
                    'resources.view', 'resources.create', 'resources.manage', 'resources.publish', 'resources.archive',
                    'resources.distribute', 'resources.approve_distribute',
                    'communications.view', 'communications.publish', 'communications.acknowledge'],
            ],
            [
                'code' => 'site_secretary',
                'label' => 'Site Secretary',
                'scope_type' => 'site',
                'perms' => ['sites.view', 'assignments.view',
                    'activities.view',
                    'attendance.submit', 'attendance.view',
                    'report.submit', 'report.review', 'report.view',
                    'outcomes.record', 'outcomes.view',
                    'people.create', 'people.edit', 'people.view',
                    'outreach-contacts.create', 'outreach-contacts.edit', 'outreach-contacts.view',
                    'resources.view',
                    'resources.distribute', 'resources.approve_distribute',
                    'communications.view', 'communications.publish', 'communications.acknowledge'],
            ],
            [
                'code' => 'site_coordinator',
                'label' => 'Site Coordinator',
                'scope_type' => 'site',
                'perms' => ['sites.view', 'sites.edit',
                    'assignments.view', 'assignments.manage',
                    'activities.view', 'activities.create', 'activities.update', 'activities.delete',
                    'attendance.submit', 'attendance.verify', 'attendance.view',
                    'report.submit', 'report.review', 'report.view',
                    'outcomes.record', 'outcomes.view', 'outcomes.manage_followup',
                    'people.create', 'people.edit', 'people.view',
                    'outreach-contacts.create', 'outreach-contacts.edit', 'outreach-contacts.view',
                    'resources.view', 'resources.create', 'resources.manage',
                    'resources.distribute', 'resources.approve_distribute',
                    'communications.view', 'communications.publish', 'communications.acknowledge'],
            ],
            [
                'code' => 'team_leader',
                'label' => 'Team Leader',
                'scope_type' => 'team',
                'perms' => ['assignments.view',
                    'activities.view',
                    'attendance.submit', 'attendance.view',
                    'report.submit', 'report.review', 'report.view_team',
                    'outcomes.record', 'outcomes.view',
                    'outreach-contacts.view',
                    'resources.view',
                    'resources.distribute',
                    'communications.view', 'communications.publish', 'communications.acknowledge'],
            ],
            [
                'code' => 'team_secretary',
                'label' => 'Team Secretary',
                'scope_type' => 'team',
                'perms' => ['assignments.view',
                    'activities.view',
                    'attendance.submit', 'attendance.verify', 'attendance.view',
                    'report.submit', 'report.view_team',
                    'outcomes.record', 'outcomes.view',
                    'outreach-contacts.create', 'outreach-contacts.edit', 'outreach-contacts.view',
                    'resources.view',
                    'resources.distribute',
                    'communications.view', 'communications.publish', 'communications.acknowledge'],
            ],
            [
                'code' => 'missionary',
                'label' => 'Missionary / Field Worker',
                'scope_type' => 'site',
                'perms' => ['activities.view',
                    'attendance.submit', 'outcomes.record',
                    'outreach-contacts.create', 'outreach-contacts.edit', 'outreach-contacts.view',
                    'resources.view', 'resources.create',
                    'resources.distribute',
                    'communications.view', 'communications.acknowledge'],
            ],
        ];

        $roles = [];
        foreach ($roleData as $rData) {
            $role = SystemRole::firstOrCreate(
                ['code' => $rData['code']],
                [
                    'label' => $rData['label'],
                    'scope_type' => $rData['scope_type'],
                    'is_active' => true,
                ]
            );
            $permIds = collect($rData['perms'])->map(fn ($code) => (int) $permMap[$code]->id)->unique()->sort()->values()->all();
            $currentIds = $role->currentPermissionVersion?->permissions()->pluck('permissions.id')->map(fn ($id) => (int) $id)->sort()->values()->all() ?? [];
            if ($permIds !== $currentIds) {
                $versionNumber = (int) $role->permissionVersions()->max('version') + 1;
                $version = RolePermissionVersion::create([
                    'system_role_id' => $role->id,
                    'version' => $versionNumber,
                    'change_reason' => 'Published from the UU Mission system role defaults',
                    'published_at' => null,
                ]);
                $version->permissions()->sync($permIds);
                $version->update(['published_at' => now()]);
                $role->update(['current_permission_version_id' => $version->id]);
            }
            // Retain the old pivot as an administration display projection only;
            // AuthorizationService reads the immutable version pinned to each assignment.
            $role->permissions()->sync($permIds);
            $roles[$rData['code']] = $role;
        }

        // Baseline global delegations: UserRoleAssignmentPolicy requires every
        // permission carried by a role to have an active delegation covering
        // the assignment scope. Without these rows, assigning any system role
        // (e.g. Site Coordinator from Campaign Deployments) fails with 403.
        foreach ($permMap as $permission) {
            $exists = DB::table('permission_delegations')
                ->where('permission_id', $permission->id)
                ->where('scope_type', 'global')
                ->where('is_active', true)
                ->exists();

            if ($exists) {
                continue;
            }

            $delegationId = DB::table('permission_delegations')->insertGetId([
                'permission_id' => $permission->id,
                'scope_type' => 'global',
                'is_active' => true,
                'reason' => 'Baseline global delegation for system role defaults',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (Schema::hasTable('permission_delegation_events')) {
                DB::table('permission_delegation_events')->insert([
                    'permission_delegation_id' => $delegationId,
                    'action' => 'created',
                    'actor_user_id' => null,
                    'reason' => 'Baseline global delegation for system role defaults',
                    'created_at' => now(),
                ]);
            }
        }

        return $roles;
    }
}
