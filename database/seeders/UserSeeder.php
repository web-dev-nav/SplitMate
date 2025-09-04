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
            ['name' => 'Navjot', 'email' => 'navjot@splitmate.local'],
            ['name' => 'Sapna', 'email' => 'sapna@splitmate.local'],
            ['name' => 'Anu', 'email' => 'anu@splitmate.local'],
        ];

        foreach ($users as $userData) {
            \App\Models\User::firstOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'password' => \Illuminate\Support\Facades\Hash::make('password'),
                ]
            );
        }
    }
}
