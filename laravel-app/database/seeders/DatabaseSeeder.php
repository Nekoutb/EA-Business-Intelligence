<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Order matters: IndustrySeeder must run before TenancySeeder, because
     * the demo tenant created by TenancySeeder references the Retail
     * industry by key and would otherwise have a NULL industry_id.
     *
     * Both seeders are idempotent (updateOrCreate / firstOrCreate), so
     * running this on every deploy is safe and never duplicates rows.
     */
    public function run(): void
    {
        $this->call(IndustrySeeder::class);
        $this->call(TenancySeeder::class);
    }
}
