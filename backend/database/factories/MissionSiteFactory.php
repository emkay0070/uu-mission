<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\MissionSite>
 */
class MissionSiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id'  => Campaign::factory(),
            'name'         => $this->faker->city().' Site',
            'description'  => $this->faker->sentence(),
            'status'       => 'active',
            'latitude'     => $this->faker->latitude(0, 5),
            'longitude'    => $this->faker->longitude(29, 35),
        ];
    }
}
