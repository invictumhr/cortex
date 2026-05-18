<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@cortex.test'],
            [
                'name' => 'Cortex Admin',
                'password' => 'password',
                'email_verified_at' => now(),
            ]
        );

        $this->call([
            AiProviderSeeder::class,
            AiModelSeeder::class,
            PersonaSeeder::class,
            PersonaModelSeeder::class,
            PowerShellPermissionSeeder::class,
        ]);
    }
}
