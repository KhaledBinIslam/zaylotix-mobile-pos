<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use Illuminate\Database\Seeder;

class BusinessTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('business_types') as $slug => $def) {
            BusinessType::updateOrCreate(
                ['slug' => $slug],
                [
                    'label_bn' => $def['label_bn'],
                    'label_en' => $def['label_en'],
                    'fields' => $def['fields'] ?? [],
                    'is_active' => true,
                ]
            );
        }
    }
}
