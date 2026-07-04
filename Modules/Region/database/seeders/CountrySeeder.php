<?php

namespace Modules\Region\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CountrySeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('database/data/countries.json');
        if (!File::exists($path)) {
            $this->command->error("File $path not found.");
            return;
        }

        $rows = json_decode(File::get($path), true);
        if (!is_array($rows)) {
            $this->command->error("countries.json is not a valid JSON array.");
            return;
        }

        $now = now()->toDateTimeString();
        $payload = [];
        foreach ($rows as $row) {
            if (!isset($row['id'], $row['alpha2'], $row['alpha3'], $row['name'])) {
                continue;
            }
            $payload[] = [
                'id'         => (int) $row['id'],
                'alpha2'     => strtoupper($row['alpha2']),
                'alpha3'     => strtoupper($row['alpha3']),
                'name'       => $row['name'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($payload)) {
            $this->command->warn("countries.json contained no valid rows.");
            return;
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            DB::table('countries')->upsert($chunk, ['id'], ['alpha2', 'alpha3', 'name', 'updated_at']);
        }

        $this->command->info(sprintf("Seeded %d countries.", count($payload)));
    }
}
