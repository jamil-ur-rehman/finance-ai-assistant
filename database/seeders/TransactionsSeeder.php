<?php

namespace Database\Seeders;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TransactionsSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = [
            'john@example.com' => [
                'count' => 48,
                'multiplier' => 1.15,
                'extra_categories' => ['shopping' => 8, 'food' => 4],
            ],
            'sarah@example.com' => [
                'count' => 42,
                'multiplier' => 0.95,
                'extra_categories' => ['subscriptions' => 6, 'shopping' => 5],
            ],
            'ali@example.com' => [
                'count' => 35,
                'multiplier' => 0.75,
                'extra_categories' => ['transport' => 8, 'utilities' => 3],
            ],
        ];

        foreach ($profiles as $email => $profile) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                continue;
            }

            $this->seedTransactionsForUser($user->id, $profile);
        }
    }

    /**
     * @param  array{count: int, multiplier: float, extra_categories: array<string, int>}  $profile
     */
    private function seedTransactionsForUser(int $userId, array $profile): void
    {
        $transactions = [];
        $months = [
            Carbon::now()->subMonths(2)->startOfMonth(),
            Carbon::now()->subMonth()->startOfMonth(),
            Carbon::now()->startOfMonth(),
        ];

        foreach ($months as $monthStart) {
            $transactions = array_merge(
                $transactions,
                $this->monthlyRecurringTransactions($userId, $monthStart, $profile['multiplier'])
            );
        }

        $variableTemplates = $this->variableTemplates($profile['multiplier']);

        foreach ($profile['extra_categories'] as $category => $extraCount) {
            for ($i = 0; $i < $extraCount; $i++) {
                $template = fake()->randomElement($variableTemplates[$category]);
                $transactions[] = $this->buildTransaction(
                    $userId,
                    $category,
                    $template,
                    $this->randomDateWithinLastThreeMonths()
                );
            }
        }

        while (count($transactions) < $profile['count']) {
            $category = fake()->randomElement(array_keys($variableTemplates));
            $template = fake()->randomElement($variableTemplates[$category]);
            $transactions[] = $this->buildTransaction(
                $userId,
                $category,
                $template,
                $this->randomDateWithinLastThreeMonths()
            );
        }

        foreach ($transactions as $transaction) {
            Transaction::query()->create($transaction);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function monthlyRecurringTransactions(int $userId, Carbon $monthStart, float $multiplier): array
    {
        $date = $monthStart->copy()->day(min(28, 1))->setTime(9, 0);

        return [
            $this->buildTransaction($userId, 'rent', [
                'merchant' => 'City Apartments',
                'amount' => $this->amount(28000, 32000, $multiplier),
                'description' => 'Monthly rent payment',
                'is_recurring' => true,
            ], $date->copy()->day(1)),
            $this->buildTransaction($userId, 'subscriptions', [
                'merchant' => 'Netflix',
                'amount' => $this->amount(1200, 1400, $multiplier),
                'description' => 'Streaming subscription',
                'is_recurring' => true,
            ], $date->copy()->day(3)),
            $this->buildTransaction($userId, 'subscriptions', [
                'merchant' => 'Spotify',
                'amount' => $this->amount(550, 750, $multiplier),
                'description' => 'Music subscription',
                'is_recurring' => true,
            ], $date->copy()->day(3)),
            $this->buildTransaction($userId, 'utilities', [
                'merchant' => 'Electric Company',
                'amount' => $this->amount(3500, 6200, $multiplier),
                'description' => 'Electricity bill',
                'is_recurring' => true,
            ], $date->copy()->day(8)),
            $this->buildTransaction($userId, 'utilities', [
                'merchant' => 'FiberNet ISP',
                'amount' => $this->amount(2500, 3800, $multiplier),
                'description' => 'Internet bill',
                'is_recurring' => true,
            ], $date->copy()->day(10)),
        ];
    }

    /**
     * @return array<string, array<int, array{merchant: string, min: float, max: float, description?: string, is_flagged?: bool}>>
     */
    private function variableTemplates(float $multiplier): array
    {
        return [
            'food' => [
                ['merchant' => 'McDonalds', 'min' => 650 * $multiplier, 'max' => 1800 * $multiplier, 'description' => 'Fast food'],
                ['merchant' => 'KFC', 'min' => 900 * $multiplier, 'max' => 2400 * $multiplier, 'description' => 'Fast food'],
                ['merchant' => 'Fresh Mart', 'min' => 2200 * $multiplier, 'max' => 8500 * $multiplier, 'description' => 'Grocery shopping'],
                ['merchant' => 'Daily Groceries', 'min' => 1500 * $multiplier, 'max' => 5200 * $multiplier, 'description' => 'Weekly groceries'],
                ['merchant' => 'Artisan Bakery', 'min' => 400 * $multiplier, 'max' => 1200 * $multiplier, 'description' => 'Bakery'],
            ],
            'transport' => [
                ['merchant' => 'Uber', 'min' => 350 * $multiplier, 'max' => 1800 * $multiplier, 'description' => 'Ride hailing'],
                ['merchant' => 'Careem', 'min' => 400 * $multiplier, 'max' => 2100 * $multiplier, 'description' => 'Ride hailing'],
                ['merchant' => 'Shell Fuel Station', 'min' => 2500 * $multiplier, 'max' => 6500 * $multiplier, 'description' => 'Fuel refill'],
                ['merchant' => 'Total Energies', 'min' => 2200 * $multiplier, 'max' => 5800 * $multiplier, 'description' => 'Fuel refill'],
            ],
            'shopping' => [
                ['merchant' => 'Amazon', 'min' => 1800 * $multiplier, 'max' => 12000 * $multiplier, 'description' => 'Online shopping'],
                ['merchant' => 'Metro Store', 'min' => 1200 * $multiplier, 'max' => 7600 * $multiplier, 'description' => 'Local store purchase'],
                ['merchant' => 'Fashion Hub', 'min' => 2500 * $multiplier, 'max' => 9800 * $multiplier, 'description' => 'Clothing purchase'],
            ],
            'subscriptions' => [
                ['merchant' => 'Adobe Creative Cloud', 'min' => 1800 * $multiplier, 'max' => 2400 * $multiplier, 'description' => 'Software subscription'],
                ['merchant' => 'YouTube Premium', 'min' => 700 * $multiplier, 'max' => 900 * $multiplier, 'description' => 'Streaming subscription'],
            ],
            'utilities' => [
                ['merchant' => 'Water Authority', 'min' => 900 * $multiplier, 'max' => 1800 * $multiplier, 'description' => 'Water bill'],
            ],
        ];
    }

    /**
     * @param  array{merchant: string, min?: float, max?: float, amount?: float, description?: string, is_recurring?: bool, is_flagged?: bool}  $template
     * @return array<string, mixed>
     */
    private function buildTransaction(int $userId, string $category, array $template, Carbon $date): array
    {
        $amount = isset($template['amount'])
            ? round((float) $template['amount'], 2)
            : round(fake()->randomFloat(2, (float) $template['min'], (float) $template['max']), 2);

        return [
            'user_id' => $userId,
            'amount' => $amount,
            'currency' => 'USD',
            'merchant' => $template['merchant'],
            'category' => $category,
            'description' => $template['description'] ?? null,
            'transaction_date' => $date,
            'is_recurring' => $template['is_recurring'] ?? false,
            'is_flagged' => $template['is_flagged'] ?? false,
            'meta' => null,
        ];
    }

    private function amount(float $min, float $max, float $multiplier): float
    {
        return round(fake()->randomFloat(2, $min * $multiplier, $max * $multiplier), 2);
    }

    private function randomDateWithinLastThreeMonths(): Carbon
    {
        return Carbon::now()
            ->subMonths(3)
            ->addDays(fake()->numberBetween(0, 89))
            ->setTime(fake()->numberBetween(8, 21), fake()->numberBetween(0, 59));
    }
}
