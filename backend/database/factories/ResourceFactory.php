<?php

namespace Database\Factories;

use App\Models\Resource;
use App\Models\ResourceCategory;
use App\Models\ResourceVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResourceFactory extends Factory
{
    protected $model = Resource::class;

    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'category_id' => ResourceCategory::factory(),
            'language' => 'en',
            'intended_audience' => fake()->word(),
            'publisher' => fake()->company(),
            'publication_date' => fake()->date(),
            'version_number' => '1.0',
            'status' => 'draft',
            'origin_assignment_id' => null,
            'current_version_id' => null, // Set in afterCreating
            'thumbnail_storage_disk' => null,
            'thumbnail_storage_path' => null,
            'published_at' => null,
            'archived_at' => null,
            'published_by_user_id' => null,
            'created_by_user_id' => User::factory(),
            'updated_by_user_id' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Resource $resource) {
            // Create an initial version if none exists
            if (! $resource->current_version_id) {
                $version = ResourceVersion::factory()->create([
                    'resource_id' => $resource->id,
                    'version_number' => $resource->version_number,
                ]);
                $resource->current_version_id = $version->id;
                $resource->saveQuietly();
            }
        });
    }
}
