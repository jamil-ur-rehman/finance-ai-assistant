<?php

namespace App\Services\Finance;

class InsightService
{
    /**
     * @return array<string, mixed>
     */
    public function generateInsights(int $userId): array
    {
        return [
            'insights' => [],
            'message' => 'Insight generation is not yet implemented.',
        ];
    }
}
