<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            ['name' => 'Navjot'],
            ['name' => 'Sapna'],
            ['name' => 'Anu'],
        ];

        foreach ($users as $userData) {
            \App\Models\User::firstOrCreate(
                ['name' => $userData['name']],
                [
                    'password' => \Illuminate\Support\Facades\Hash::make('password'),
                    'is_active' => true,
                ]
            );
        }
    }
}
