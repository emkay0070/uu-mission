<?php

namespace Database\Factories;

use App\Models\Resource;
use App\Models\ResourceVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResourceVersionFactory extends Factory
{
    protected $model = ResourceVersion::class;

    public function definition(): array
    {
        return [
            'resource_id' => Resource::factory(),
            'version_number' => '1.0',
            'storage_disk' => 'local',
            'storage_path' => 'resources/files/pdf/'.fake()->date('Y/m').'/'.fake()->uuid().'.pdf',
            'original_filename' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'media_type' => 'pdf',
            'file_size' => fake()->numberBetween(1000, 10000000),
            'change_reason' => fake()->sentence(),
            'replaced_by_user_id' => null,
            'created_at' => now(),
        ];
    }
}
