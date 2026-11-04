<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the canonical engagement-stage vocabulary.
 *
 * The stages are reference data, not user data: `code` is the stable key and
 * `engagement_stages.code` carries a unique constraint. Seeding therefore uses
 * an upsert keyed on `code` so the seeder is safe to re-run — re-running must
 * neither duplicate rows nor fail on the unique constraint, and it may correct
 * a label/colour/order that has drifted. Ids are deliberately left untouched so
 * existing people.engagement_stage_id and history rows keep their meaning.
 */
class EngagementStageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $stages = [
            ['code' => 'new_contact', 'label' => 'New Contact', 'color_key' => 'blue', 'sort_order' => 1],
            ['code' => 'interested', 'label' => 'Interested', 'color_key' => 'cyan', 'sort_order' => 2],
            ['code' => 'bible_studies_ongoing', 'label' => 'Bible Studies Ongoing', 'color_key' => 'purple', 'sort_order' => 3],
            ['code' => 'decision_for_christ', 'label' => 'Decision for Christ', 'color_key' => 'amber', 'sort_order' => 4],
            ['code' => 'preparing_for_baptism', 'label' => 'Preparing for Baptism', 'color_key' => 'emerald', 'sort_order' => 5],
            ['code' => 'baptized', 'label' => 'Baptized', 'color_key' => 'green', 'sort_order' => 6],
            ['code' => 'church_member', 'label' => 'Church Member', 'color_key' => 'indigo', 'sort_order' => 7],
            ['code' => 'follow_up_needed', 'label' => 'Follow-up Needed', 'color_key' => 'orange', 'sort_order' => 8],
            ['code' => 'temporarily_inactive', 'label' => 'Temporarily Inactive', 'color_key' => 'slate', 'sort_order' => 9],
            ['code' => 'unable_to_contact', 'label' => 'Unable to Contact', 'color_key' => 'red', 'sort_order' => 10],
            ['code' => 'declined_further_contact', 'label' => 'Declined Further Contact', 'color_key' => 'gray', 'sort_order' => 11],
        ];

        // Upsert keyed on `code`: creates missing stages, refreshes the
        // descriptive columns of existing ones, and never touches their ids.
        DB::table('engagement_stages')->upsert(
            $stages,
            ['code'],
            ['label', 'color_key', 'sort_order']
        );
    }
}
