<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\ProgrammeType>
 */
class ProgrammeTypeFactory extends Factory
{
    public function definition(): array
    {
        static $counter = 0;
        $counter++;
        return [
            'code'            => 'prog_'.$counter.'_'.uniqid(),
            'label'           => $this->faker->words(2, true),
            'ministry_owner'  => 'General Field',
            'is_active'       => true,
        ];
    }
}
