<?php

namespace Modules\IssueTracker\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IssueTrackerCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Bug / Error', 'platform' => null, 'sort_order' => 1],
            ['name' => 'UI/UX Issue', 'platform' => null, 'sort_order' => 2],
            ['name' => 'Performance', 'platform' => null, 'sort_order' => 3],
            ['name' => 'Fitur Tidak Sesuai', 'platform' => null, 'sort_order' => 4],
            ['name' => 'Request Fitur Baru', 'platform' => null, 'sort_order' => 5],
            ['name' => 'Data Tidak Akurat', 'platform' => null, 'sort_order' => 6],
            ['name' => 'Lainnya', 'platform' => null, 'sort_order' => 99],
        ];

        foreach ($categories as $cat) {
            DB::table('issue_tracker_categories')->updateOrInsert(
                ['name' => $cat['name']],
                [...$cat, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
