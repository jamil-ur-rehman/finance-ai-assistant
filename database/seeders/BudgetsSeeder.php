<?php

namespace Database\Seeders;

use App\Models\Budget;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class BudgetsSeeder extends Seeder
{
    /**
     * @var array<string, array<string, float>>
     */
    private array $budgetProfiles = [
        'john@example.com' => [
            'food' => 30000,
            'transport' => 15000,
            'shopping' => 20000,
        ],
        'sarah@example.com' => [
            'food' => 22000,
            'transport' => 12000,
            'shopping' => 18000,
            'subscriptions' => 8000,
        ],
        'ali@example.com' => [
            'food' => 18000,
            'transport' => 20000,
            'shopping' => 10000,
            'utilities' => 12000,
        ],
    ];

    public function run(): void
    {
        $months = [
            Carbon::now()->subMonths(2)->format('Y-m'),
            Carbon::now()->subMonth()->format('Y-m'),
            Carbon::now()->format('Y-m'),
        ];

        foreach ($this->budgetProfiles as $email => $categories) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                continue;
            }

            foreach ($months as $month) {
                foreach ($categories as $category => $limitAmount) {
                    Budget::query()->updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'category' => $category,
                            'month' => $month,
                        ],
                        [
                            'limit_amount' => $limitAmount,
                        ]
                    );
                }
            }
        }
    }
}
