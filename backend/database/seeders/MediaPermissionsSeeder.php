<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Permission;

class MediaPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            [
                'code' => 'media.upload',
                'label' => 'Upload Media',
                'module' => 'media',
            ],
            [
                'code' => 'media.view',
                'label' => 'View Media',
                'module' => 'media',
            ],
            [
                'code' => 'media.update',
                'label' => 'Update Media',
                'module' => 'media',
            ],
            [
                'code' => 'media.delete',
                'label' => 'Delete Media',
                'module' => 'media',
            ],
            [
                'code' => 'media.attach',
                'label' => 'Attach Media to Entities',
                'module' => 'media',
            ],
            [
                'code' => 'media.detach',
                'label' => 'Detach Media from Entities',
                'module' => 'media',
            ],
            [
                'code' => 'media.manage_collections',
                'label' => 'Manage Media Collections',
                'module' => 'media',
            ],
            [
                'code' => 'media.publish',
                'label' => 'Publish Media (Change Visibility)',
                'module' => 'media',
            ],
            [
                'code' => 'media.manage_consents',
                'label' => 'Manage Media Consents',
                'module' => 'media',
            ],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['code' => $permission['code']],
                [
                    'label' => $permission['label'],
                    'module' => $permission['module'],
                ]
            );
        }

        $this->command->info('Media permissions seeded successfully.');
    }
}
