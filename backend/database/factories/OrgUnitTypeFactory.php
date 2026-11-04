<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\OrgUnitType>
 */
class OrgUnitTypeFactory extends Factory
{
    public function definition(): array
    {
        static $counter = 0;
        $counter++;
        return [
            'code'              => 'type_'.$counter.'_'.uniqid(),
            'label'             => $this->faker->words(2, true),
            'sort_order'        => $counter * 10,
            'can_have_children' => true,
            'is_active'         => true,
        ];
    }
}
