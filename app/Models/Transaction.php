<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'currency',
        'merchant',
        'category',
        'description',
        'transaction_date',
        'is_recurring',
        'is_flagged',
        'meta',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
        'meta' => 'array',
    ];
}
