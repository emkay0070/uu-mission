<?php

namespace Database\Factories;

use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\Programme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Campaign>
 */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'programme_id'     => Programme::factory(),
            'lead_org_unit_id' => OrgUnit::factory(),
            'name'             => $this->faker->words(3, true).' Campaign',
            'description'      => $this->faker->sentence(),
            'status'           => 'planning',
            'start_date'       => now()->toDateString(),
            'end_date'         => now()->addDays(14)->toDateString(),
        ];
    }
}
