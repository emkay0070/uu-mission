<?php

namespace Database\Seeders;

use App\Models\EncounterMethod;
use Illuminate\Database\Seeder;

class EncounterMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            [
                'code'        => 'door_to_door',
                'label'       => 'Door-to-door',
                'description' => 'Systematic visitation of homes in a village or area.',
                'sort_order'  => 10,
            ],
            [
                'code'        => 'personal_conversation',
                'label'       => 'Personal conversation',
                'description' => 'One-on-one conversation during daily activities or chance meeting.',
                'sort_order'  => 20,
            ],
            [
                'code'        => 'public_preaching',
                'label'       => 'Public preaching or evening meeting',
                'description' => 'Evangelistic meeting, crusade, or public preaching event.',
                'sort_order'  => 30,
            ],
            [
                'code'        => 'bible_study',
                'label'       => 'Bible study',
                'description' => 'Small group Bible study or discipleship session.',
                'sort_order'  => 40,
            ],
            [
                'code'        => 'health_outreach',
                'label'       => 'Health outreach',
                'description' => 'Community health screening, medical camp, or wellness visit.',
                'sort_order'  => 50,
            ],
            [
                'code'        => 'youth_programme',
                'label'       => 'Youth or children\'s programme',
                'description' => 'Youth meeting, children\'s Sabbath school, or youth activity.',
                'sort_order'  => 60,
            ],
            [
                'code'        => 'referral',
                'label'       => 'Referral',
                'description' => 'Introduced by another person or member.',
                'sort_order'  => 70,
            ],
            [
                'code'        => 'other',
                'label'       => 'Other',
                'description' => 'Any other method of encounter not listed above.',
                'sort_order'  => 80,
            ],
        ];

        foreach ($methods as $method) {
            EncounterMethod::updateOrCreate(['code' => $method['code']], $method + ['is_active' => true]);
        }
    }
}
