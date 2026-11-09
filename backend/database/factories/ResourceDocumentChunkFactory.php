<?php

namespace Database\Factories;

use App\Models\ResourceDocumentChunk;
use App\Models\ResourceDocumentProcessing;
use App\Models\ResourceVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceDocumentChunk>
 */
class ResourceDocumentChunkFactory extends Factory
{
    protected $model = ResourceDocumentChunk::class;

    public function definition(): array
    {
        return [
            'resource_version_id' => ResourceVersion::factory(),
            'processing_id' => ResourceDocumentProcessing::factory(),
            'page_number' => fake()->numberBetween(1, 100),
            'chapter' => fake()->optional()->sentence(3),
            'heading' => fake()->optional()->sentence(5),
            'chunk_order' => fake()->numberBetween(0, 10),
            'extracted_text' => fake()->paragraphs(3, true),
            'position_data' => [
                'x' => fake()->numberBetween(0, 500),
                'y' => fake()->numberBetween(0, 700),
                'width' => fake()->numberBetween(100, 500),
                'height' => fake()->numberBetween(50, 200),
            ],
            'embedding_model' => fake()->optional()->word(),
            'embedding_dimensions' => fake()->optional()->numberBetween(128, 1536),
            'embedding_version' => fake()->optional()->word(),
            'source_text_hash' => fake()->optional()->sha256(),
        ];
    }
}
