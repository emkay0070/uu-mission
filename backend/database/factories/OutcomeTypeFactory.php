<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\OutcomeType>
 */
class OutcomeTypeFactory extends Factory
{
    public function definition(): array
    {
        static $counter = 0;
        $counter++;
        return [
            'code'              => 'outcome_'.$counter.'_'.uniqid(),
            'label'             => $this->faker->words(3, true),
            'sort_order'        => $counter * 10,
            'triggers_follow_up'=> false,
            'include_in_stats'  => true,
            'is_active'         => true,
        ];
    }
}
