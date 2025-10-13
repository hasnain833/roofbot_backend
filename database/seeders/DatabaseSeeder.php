<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantAgent;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Superadmin',
            'role' => 'superadmin',
            'email' => 'superadmin@roofbot.com',
            'password' => Hash::make('password')
        ]);

        $tenant = Tenant::create([
            'user_id' => $user->id
        ]);

        TenantAgent::create([
            'tenant_id' => $tenant->id,
            'name' => 'Chatbot',
            'description' => 'Description for Agent 1',
            'type' => 'n8n',
            'status' => 'active'
        ]);
    }
}
