<?php

namespace Database\Factories;

use App\Models\ResourceDocumentProcessing;
use App\Models\ResourceVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceDocumentProcessing>
 */
class ResourceDocumentProcessingFactory extends Factory
{
    protected $model = ResourceDocumentProcessing::class;

    public function definition(): array
    {
        return [
            'resource_version_id' => ResourceVersion::factory(),
            'status' => fake()->randomElement(['pending', 'processing', 'completed', 'failed']),
            'total_pages' => fake()->numberBetween(1, 100),
            'extracted_pages' => fake()->numberBetween(0, 100),
            'processing_metadata' => [
                'extraction_method' => 'pdf_parser',
                'chunk_size' => 500,
                'overlap' => 50,
            ],
            'started_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'completed_at' => fake()->optional()->dateTimeBetween('-1 month', 'now'),
            'retry_count' => fake()->numberBetween(0, 5),
            'last_retry_at' => fake()->optional()->dateTimeBetween('-1 month', 'now'),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'completed_at' => now(),
            'processing_metadata' => [
                'error' => 'PDF parsing failed',
            ],
        ]);
    }
}
