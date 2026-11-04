<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Team>
 */
class TeamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id'     => Campaign::factory(),
            'mission_site_id' => null,
            'name'            => $this->faker->words(2, true).' Team',
            'description'     => $this->faker->sentence(),
        ];
    }
}
