<?php

namespace Database\Factories;

use App\Models\OrgUnit;
use App\Models\ProgrammeType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Programme>
 */
class ProgrammeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'programme_type_id'  => ProgrammeType::factory(),
            'owning_org_unit_id' => OrgUnit::factory(),
            'name'               => $this->faker->words(3, true).' Programme',
            'description'        => $this->faker->sentence(),
            'status'             => 'active',
        ];
    }
}
