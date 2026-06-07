<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Database\Seeder;

class UserMemorySeeder extends Seeder
{
    /**
     * @var array<string, array<int, array{key: string, value: string}>>
     */
    private array $memories = [
        'john@example.com' => [
            ['key' => 'ignore_category', 'value' => 'rent'],
            ['key' => 'salary_date', 'value' => '1st'],
            ['key' => 'note', 'value' => 'do not count personal transfers'],
        ],
        'sarah@example.com' => [
            ['key' => 'ignore_category', 'value' => 'subscriptions'],
        ],
    ];

    public function run(): void
    {
        foreach ($this->memories as $email => $entries) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                continue;
            }

            foreach ($entries as $entry) {
                UserMemory::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'key' => $entry['key'],
                    ],
                    [
                        'value' => $entry['value'],
                    ]
                );
            }
        }
    }
}
