<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\MissionSite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\FieldReport>
 */
class FieldReportFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->subDays(rand(1, 7));
        return [
            'campaign_id'        => Campaign::factory(),
            'mission_site_id'    => null, // must be set explicitly in tests
            'report_period_type' => 'daily',
            'period_start'       => $start->toDateString(),
            'period_end'         => $start->toDateString(),
            'status'             => 'draft',
            'narrative'          => $this->faker->paragraph(),
        ];
    }
}
