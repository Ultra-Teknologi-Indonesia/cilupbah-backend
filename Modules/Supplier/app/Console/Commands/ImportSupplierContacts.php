<?php

namespace Modules\Supplier\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Supplier\Models\Contact;

class ImportSupplierContacts extends Command
{
    protected $signature = 'contacts:import-suppliers
        {path : Path file CSV di server (mis. storage/app/imports/data-pemasok.csv)}
        {--dry-run : Hanya tampilkan ringkasan, tidak menulis apa pun ke database}
        {--purge : Hapus pemasok lama (type=SUPPLIER, tak terreferensi, bukan is_system) SEBELUM import}';

    protected $description = 'Impor pemasok dari CSV di server ke tabel contacts (idempoten, upsert, type=SUPPLIER).';

    public function handle(): int
    {
        $path   = $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');
        $purge  = (bool) $this->option('purge');

        if (! is_readable($path)) {
            $this->error("File tidak terbaca: {$path}");
            return self::FAILURE;
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            $this->error("Gagal membuka file: {$path}");
            return self::FAILURE;
        }

        $header = fgetcsv($fh, null, ',', '"', '');
        if ($header === false || $header === null) {
            $this->error('File CSV kosong / tanpa header.');
            fclose($fh);
            return self::FAILURE;
        }

        $header = array_map(
            fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))),
            $header
        );

        if ($dryRun) {
            $this->warn('DRY-RUN: tidak ada perubahan yang ditulis.');
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $purged  = 0;
        $purgeSkipped = 0;
        $line    = 1;

        DB::beginTransaction();
        try {
            if ($purge) {
                [$purged, $purgeSkipped] = $this->purgeSuppliers($dryRun);
            }

            while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
                $line++;

                if ($row === [null] || count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }

                $row  = array_pad(array_slice($row, 0, count($header)), count($header), null);
                $data = array_combine($header, $row);

                $name = $this->clean($data['contact_name'] ?? null);
                if ($name === null) {
                    $this->warn("Baris {$line}: contact_name kosong, dilewati.");
                    $skipped++;
                    continue;
                }

                $attributes = $this->mapRow($name, $data);
                $code       = $attributes['code'];

                if ($dryRun) {
                    Contact::where('code', $code)->exists() ? $updated++ : $created++;
                    continue;
                }

                $existing = Contact::where('code', $code)->first();
                if ($existing) {
                    $existing->fill($attributes)->save();
                    $updated++;
                } else {
                    Contact::create($attributes);
                    $created++;
                }
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($fh);
            $this->error("Gagal di sekitar baris {$line}: {$e->getMessage()}");
            return self::FAILURE;
        }

        fclose($fh);

        if ($purge) {
            $this->info(sprintf(
                '%sPurge pemasok lama: dihapus %d, dilewati (terreferensi/sistem) %d.',
                $dryRun ? '[DRY-RUN] ' : '',
                $purged,
                $purgeSkipped
            ));
        }

        $this->info(sprintf(
            '%sSelesai. Baru: %d, diperbarui: %d, dilewati: %d.',
            $dryRun ? '[DRY-RUN] ' : '',
            $created,
            $updated,
            $skipped
        ));

        return self::SUCCESS;
    }

    private function purgeSuppliers(bool $dryRun): array
    {
        $referenced = collect();
        foreach ([['purchase_orders', 'contact_id'], ['purchase_bills', 'contact_id'], ['purchase_returns', 'supplier_id']] as [$table, $col]) {
            if (DB::getSchemaBuilder()->hasColumn($table, $col)) {
                $referenced = $referenced->merge(DB::table($table)->whereNotNull($col)->pluck($col));
            }
        }
        $referenced = $referenced->unique()->filter()->values();

        $base = Contact::query()
            ->where('type', Contact::TYPE_SUPPLIER)
            ->where(function ($q) {
                $q->where('is_system', false)->orWhereNull('is_system');
            });

        $deletable   = (clone $base)->whereNotIn('id', $referenced->all());
        $notDeletable = (clone $base)->whereIn('id', $referenced->all())->count();

        if (! $dryRun) {
            $rows = (clone $deletable)->get();
            if ($rows->isNotEmpty()) {
                $dir = storage_path('app/backups');
                if (! is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $file = $dir . '/contacts-suppliers-purge-' . date('Ymd-His') . '.json';
                file_put_contents($file, $rows->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->line("Backup pemasok yang akan dihapus: {$file}");
            }
        }

        $count = (clone $deletable)->count();
        if (! $dryRun) {
            (clone $deletable)->delete();
        }

        return [$count, $notDeletable];
    }

    private function mapRow(string $name, array $r): array
    {
        $phone = $this->clean($r['phone'] ?? null) ?? $this->clean($r['mobile'] ?? null);

        return [
            'code'             => 'SUP-' . strtoupper(substr(sha1(mb_strtolower($name)), 0, 8)),
            'name'             => $name,
            'company_name'     => null,
            'email'            => $this->clean($r['email'] ?? null),
            'phone'            => $phone,
            'mobile'           => $this->clean($r['mobile'] ?? null),
            'fax'              => $this->clean($r['fax'] ?? null),
            'address'          => $this->clean($r['billing_address'] ?? null) ?? $this->clean($r['shipping_address'] ?? null),
            'city'             => $this->clean($r['billing_city'] ?? null) ?? $this->clean($r['shipping_city'] ?? null),
            'province'         => $this->clean($r['billing_province'] ?? null) ?? $this->clean($r['shipping_province'] ?? null),
            'postal_code'      => $this->clean($r['billing_post_code'] ?? null) ?? $this->clean($r['shipping_post_code'] ?? null),
            'shipping_address' => $this->clean($r['shipping_address'] ?? null),
            'shipping_province' => $this->clean($r['shipping_province'] ?? null),
            'shipping_postal_code' => $this->clean($r['shipping_post_code'] ?? null),
            'tax_id'           => $this->clean($r['npwp'] ?? null),
            'contact_person'   => $this->clean($r['contact_position'] ?? null),
            'payment_term'     => null,
            'is_dropshipper'   => $this->toBool($r['is_dropshipper'] ?? null),
            'is_reseller'      => $this->toBool($r['is_reseller'] ?? null),
            'source'           => $this->clean($r['contact_source'] ?? null),
            'notes'            => $this->buildNotes($r),
            'status'           => Contact::STATUS_ACTIVE,
            'type'             => Contact::TYPE_SUPPLIER,
        ];
    }

    private function buildNotes(array $r): ?string
    {
        $lines = [];

        if ($base = $this->clean($r['notes'] ?? null)) {
            $lines[] = $base;
        }

        $extras = [
            'Area kirim'       => $r['shipping_area'] ?? null,
            'Area tagihan'     => $r['billing_area'] ?? null,
            'Detail sumber'    => $r['source_detail'] ?? null,
        ];

        foreach ($extras as $label => $value) {
            if (($v = $this->clean($value)) !== null) {
                $lines[] = "{$label}: {$v}";
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    private function toBool($value): bool
    {
        $v = strtolower(trim((string) ($value ?? '')));
        return in_array($v, ['1', 'true', 'ya', 'yes', 'y'], true);
    }

    private function clean($value): ?string
    {
        $v = trim((string) ($value ?? ''));
        if ($v === '' || $v === '.') {
            return null;
        }
        return $v;
    }
}
