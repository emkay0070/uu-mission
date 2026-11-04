<?php

namespace Database\Factories;

use App\Models\OrgUnitType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\OrgUnit>
 */
class OrgUnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'org_unit_type_id' => OrgUnitType::factory(),
            'parent_id'        => null,
            'name'             => $this->faker->company(),
            'short_code'       => strtoupper($this->faker->lexify('???')).rand(10, 99),
            'country_code'     => 'UG',
            'is_international' => false,
        ];
    }
}
