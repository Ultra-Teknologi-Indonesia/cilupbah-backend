<?php

namespace Modules\Region\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RegionDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        $this->seedProvinces();
        $this->seedCities();
        $this->seedDistricts();
        $this->seedVillages();
        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
    }

    private function seedProvinces()
    {
        DB::table('provinces')->delete();
        $path = storage_path('app/data/provinsi.json');
        if (!File::exists($path)) {
            $this->command->error("File $path not found.");
            return;
        }

        $json = File::get($path);
        $data = json_decode($json, true);

        $insertData = [];
        $now = now();

        foreach ($data as $item) {
            $insertData[] = [
                'id' => $item['id'],
                'nama' => $item['nama'],
                'latitude' => $item['latitude'] ?? null,
                'longitude' => $item['longitude'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('provinces')->insert($insertData);
        $this->command->info(count($insertData) . " Provinces inserted.");
    }

    private function seedCities()
    {
        DB::table('cities')->delete();
        $insertData = [];
        $now = now();
        
        $kabupatenPath = storage_path('app/data/kabupaten');
        
        $files = array_filter(
            File::exists($kabupatenPath) ? File::allFiles($kabupatenPath) : [],
            function($file) {
                return $file->getExtension() === 'json' && strlen(pathinfo($file->getFilename(), PATHINFO_FILENAME)) === 2;
            }
        );

        foreach ($files as $file) {
            $json = File::get($file->getPathname());
            $data = json_decode($json, true);
            $provinceId = pathinfo($file->getFilename(), PATHINFO_FILENAME);

            foreach ($data as $item) {
                if (is_string($item)) {
                    $this->command->error("File {$file->getFilename()} contains string instead of object: " . json_encode($item));
                    continue;
                }
                $insertData[] = [
                    'id' => $item['id'],
                    'province_id' => substr($item['id'], 0, 2), // First 2 digits are province_id
                    'nama' => $item['nama'],
                    'latitude' => $item['latitude'] ?? null,
                    'longitude' => $item['longitude'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($insertData, 1000) as $chunk) {
            DB::table('cities')->insert($chunk);
        }
        $this->command->info(count($insertData) . " Cities inserted.");
    }

    private function seedDistricts()
    {
        DB::table('districts')->delete();
        $path = storage_path('app/data/kecamatan');
        if (!File::exists($path)) {
            $this->command->error("Directory $path not found.");
            return;
        }

        $files = array_filter(File::allFiles($path), function($file) {
            return $file->getExtension() === 'json' && strlen(pathinfo($file->getFilename(), PATHINFO_FILENAME)) === 4;
        });
        $insertData = [];
        $now = now();

        foreach ($files as $file) {
            $json = File::get($file->getPathname());
            $data = json_decode($json, true);

            foreach ($data as $item) {
                if (is_string($item)) {
                    $this->command->error("File {$file->getFilename()} contains string instead of object: " . json_encode($item));
                    continue;
                }
                $insertData[] = [
                    'id' => $item['id'],
                    'city_id' => substr($item['id'], 0, 4), // First 4 digits are city_id
                    'nama' => $item['nama'],
                    'latitude' => $item['latitude'] ?? null,
                    'longitude' => $item['longitude'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($insertData, 1000) as $chunk) {
            DB::table('districts')->insert($chunk);
        }
        $this->command->info(count($insertData) . " Districts inserted.");
    }

    private function seedVillages()
    {
        DB::table('villages')->delete();
        $path = storage_path('app/data/kelurahan');
        if (!File::exists($path)) {
            $this->command->error("Directory $path not found.");
            return;
        }

        $files = glob($path . '/??????.json');
        $insertData = [];
        $now = now();
        $totalInserted = 0;

        foreach ($files as $file) {
            $json = File::get($file);
            $data = json_decode($json, true);

            foreach ($data as $item) {
                if (is_string($item)) {
                    $this->command->error("File " . basename($file) . " contains string instead of object: " . json_encode($item));
                    continue;
                }
                $insertData[] = [
                    'id' => $item['id'],
                    'district_id' => substr($item['id'], 0, 6), // First 6 digits are district_id
                    'nama' => $item['nama'],
                    'latitude' => $item['latitude'] ?? null,
                    'longitude' => $item['longitude'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($insertData) >= 2000) {
                    DB::table('villages')->insert($insertData);
                    $totalInserted += count($insertData);
                    $insertData = [];
                }
            }
        }

        if (count($insertData) > 0) {
            DB::table('villages')->insert($insertData);
            $totalInserted += count($insertData);
        }
        
        $this->command->info($totalInserted . " Villages inserted.");
    }
}
