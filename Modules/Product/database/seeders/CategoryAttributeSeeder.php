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

        foreach ($categories as $category) {
            $hasChildren = $categories->contains(fn ($c) => $c->parent_id === $category->id);
            if (! $hasChildren) {
                $category->attributes()->syncWithoutDetaching(
                    $attributes->pluck('id')->toArray()
                );
            }
        }
    }
}
