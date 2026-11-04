<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\MissionOutcome>
 */
class MissionOutcomeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id'      => Campaign::factory(),
            'mission_site_id'  => null, // must be set explicitly in tests
            'outcome_type'     => 'decision',
            'outcome_date'     => now()->toDateString(),
            'visitor_name'     => $this->faker->name(),
            'follow_up_status' => 'pending',
        ];
    }
}
