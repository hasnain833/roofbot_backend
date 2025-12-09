<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run()
    {
        Plan::updateOrCreate(
            ['slug' => 'starter'],
            [
                'name' => 'Starter Plan',
                'monthly_price' => 350.00,
                'setup_fee' => 1000.00,
                'stripe_monthly_price_id' => 'price_1SKTx7CO2IEPgqT4dR2eJ0f5',
                'stripe_setup_fee_price_id' => 'price_1SKTx7CO2IEPgqT4h6gphGko', 
                'description' => 'AI chat + CRM + SMS',
                'is_popular' => false,
            ]
        );

        Plan::updateOrCreate(
            ['slug' => 'pro'],
            [
                'name' => 'Pro Plan',
                'yearly_price' => 3942.00,
                'setup_fee' => 0.00,
                'stripe_yearly_price_id' => 'price_1SLnfNCO2IEPgqT4npnWBlK9', 
                'description' => 'No setup fee + priority support',
                'is_popular' => true,
            ]
        );
    }
}