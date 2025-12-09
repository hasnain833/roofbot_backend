<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'name', 
        'slug', 
        'monthly_price', 
        'yearly_price', 
        'setup_fee',
        'stripe_monthly_price_id',
        'stripe_yearly_price_id',
        'stripe_setup_fee_price_id',
        'description', 
        'is_popular',
    ];

    protected $casts = [
        'monthly_price' => 'decimal:2',
        'yearly_price' => 'decimal:2',
        'setup_fee' => 'decimal:2',
    ];
}