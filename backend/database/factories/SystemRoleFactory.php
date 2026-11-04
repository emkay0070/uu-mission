<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\SystemRole>
 */
class SystemRoleFactory extends Factory
{
    public function definition(): array
    {
        static $counter = 0;
        $counter++;
        return [
            'code'       => 'role_'.$counter.'_'.uniqid(),
            'label'      => $this->faker->words(2, true),
            'scope_type' => $this->faker->randomElement(['global', 'org_unit', 'campaign', 'site']),
            'is_active'  => true,
        ];
    }
}
