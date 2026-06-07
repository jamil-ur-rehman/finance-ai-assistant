<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, email: string}>
     */
    private array $users = [
        ['name' => 'John Doe', 'email' => 'john@example.com'],
        ['name' => 'Sarah Khan', 'email' => 'sarah@example.com'],
        ['name' => 'Ali Ahmed', 'email' => 'ali@example.com'],
    ];

    public function run(): void
    {
        foreach ($this->users as $attributes) {
            User::query()->updateOrCreate(
                ['email' => $attributes['email']],
                [
                    'name' => $attributes['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
