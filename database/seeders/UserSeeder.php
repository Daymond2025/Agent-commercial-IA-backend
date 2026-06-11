<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@daymondboutique.com'],
            [
                'name'           => 'Admin Daymond',
                'password'       => Hash::make('Daymond@2026!'),
                'role'           => 'admin',
                'whatsapp_phone' => '+22501000001',
                'is_active'      => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'coord1@daymondboutique.com'],
            [
                'name'           => 'Adjoua Konan',
                'password'       => Hash::make('Coord1@2024!'),
                'role'           => 'coordinator',
                'whatsapp_phone' => '+22507111222',
                'is_active'      => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'coord2@daymondboutique.com'],
            [
                'name'           => 'Bamba Coulibaly',
                'password'       => Hash::make('Coord2@2024!'),
                'role'           => 'coordinator',
                'whatsapp_phone' => '+22507333444',
                'is_active'      => true,
            ]
        );
    }
}