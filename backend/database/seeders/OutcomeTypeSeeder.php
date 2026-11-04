<?php

namespace Database\Seeders;

use App\Models\OutcomeType;
use Illuminate\Database\Seeder;

class OutcomeTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'code'              => 'decision',
                'label'             => 'Decision for Christ',
                'description'       => 'Person accepted Christ at an evangelistic meeting or outreach activity.',
                'sort_order'        => 10,
                'triggers_follow_up'=> true,
                'include_in_stats'  => true,
            ],
            [
                'code'              => 'baptism',
                'label'             => 'Baptism',
                'description'       => 'Person was baptised during the campaign.',
                'sort_order'        => 20,
                'triggers_follow_up'=> false,
                'include_in_stats'  => true,
            ],
            [
                'code'              => 'bible_study_req',
                'label'             => 'Bible Study Request',
                'description'       => 'Person requested Bible study follow-up sessions.',
                'sort_order'        => 30,
                'triggers_follow_up'=> true,
                'include_in_stats'  => true,
            ],
            [
                'code'              => 'church_joined',
                'label'             => 'Church Membership',
                'description'       => 'Person formally joined a local church fellowship.',
                'sort_order'        => 40,
                'triggers_follow_up'=> false,
                'include_in_stats'  => true,
            ],
            [
                'code'              => 'follow_up_needed',
                'label'             => 'Follow-up Needed',
                'description'       => 'Person flagged for follow-up — no other outcome recorded yet.',
                'sort_order'        => 50,
                'triggers_follow_up'=> true,
                'include_in_stats'  => false,
            ],
        ];

        foreach ($types as $type) {
            OutcomeType::updateOrCreate(['code' => $type['code']], $type + ['is_active' => true]);
        }
    }
}
