<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantAgent;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
            ServiceTypeSeeder::class,
        ]);
        // ──────────────────────────────────────────────────────────────
        // 1. Create the **company owner** – full access
        // ──────────────────────────────────────────────────────────────
        $owner = User::factory()->create([
            'first_name'          => 'Griffin',
            'last_name'           => 'B',
            'role'                => 'superadmin',
            'email'               => 'griffinb@invictusconnect.com',
            'password'            => Hash::make('password'),  
            'plan_id'             => 2,         
            'subscription_status' => 'active',
            'current_period_end'  => null,       
            'stripe_customer_id'  => null,
        ]);

        $tenant = Tenant::create([
            'user_id' => $owner->id,   
        ]);

        TenantAgent::create([
            'tenant_id'   => $tenant->id,
            'name'        => 'Chatbot',
            'description' => 'Default n8n chatbot',
            'type'        => 'n8n',
            'status'      => 'active',
        ]);
    }
}