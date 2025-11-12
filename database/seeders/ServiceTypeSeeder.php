<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ServiceType;

class ServiceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Roof Inspection', 'description' => 'Check the condition of the roof'],
            ['name' => 'Gutter Cleaning', 'description' => 'Clean and unclog gutters'],
            ['name' => 'Repair', 'description' => 'Fix roof or gutter damage'],
            ['name' => 'Siding', 'description' => 'Siding service'],
            ['name' => 'Windows', 'description' => 'Windows service'],
            ['name' => 'Others', 'description' => 'Other services'],
        ];

        foreach ($types as $type) {
            ServiceType::updateOrCreate(['name' => $type['name']], $type);
        }
    }
}
