<?php

namespace Database\Seeders;

use App\Models\Industry;
use App\Models\IndustryModule;
use Illuminate\Database\Seeder;

class IndustrySeeder extends Seeder
{
    public function run(): void
    {
        $config = config('industries');
        $bundles = $config['bundles'] ?? [];

        foreach ($config['industries'] as $industryData) {
            $industry = Industry::updateOrCreate(
                ['key' => $industryData['key']],
                $industryData
            );

            $bundle = $bundles[$industryData['key']] ?? [];
            $modules = $bundle['modules'] ?? [];

            foreach ($modules as $moduleKey => $settings) {
                IndustryModule::updateOrCreate(
                    ['industry_id' => $industry->id, 'module_key' => $moduleKey],
                    [
                        'enabled_by_default' => $settings['default'] ?? false,
                        'required'           => $settings['required'] ?? false,
                    ]
                );
            }
        }
    }
}
