<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\Category;

class CategoryAttributeSeeder extends Seeder
{
    public function run(): void
    {
        $categories = Category::all();
        $attributes = Attribute::all();

        if ($categories->isEmpty() || $attributes->isEmpty()) {
            return;
        }

        // Attach all attributes to all top level categories for now (as sample data)
        // In real PIM, this would be specific mappings based on Jubelio
        foreach ($categories as $category) {
            if (is_null($category->parent_id)) {
                // Attach a random subset or all. Let's attach all for demo
                $category->attributes()->syncWithoutDetaching(
                    $attributes->pluck('id')->toArray()
                );
            }
        }
    }
}
