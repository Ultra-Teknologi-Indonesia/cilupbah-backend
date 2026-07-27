<?php

namespace Modules\Supplier\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Supplier\Models\Supplier;

/**
 * Import pemasok dari file CSV yang berada di server (path argumen).
 *
 * PENTING: perintah ini GENERIK dan TIDAK memuat data asli apa pun.
 * Data sensitif hanya ada di file CSV yang disalin ke server (gitignored),
 * bukan di dalam kode ini. Jangan pernah hard-code data pemasok di sini.
 *
 * Header CSV yang dikenali (gaya export Jubelio):
 *   contact_name, contact_position, email, phone, mobile, fax, npwp,
 *   payment_term, notes, shipping_address, shipping_area, shipping_city,
 *   shipping_province, shipping_post_code, billing_address, billing_area,
 *   billing_city, billing_province, billing_post_code, is_dropshipper,
 *   is_reseller, contact_source, source_detail
 *
 * Idempoten: dipetakan ke kolom tabel `suppliers` lalu di-upsert dengan
 * `code` deterministik (turunan dari nama), sehingga aman dijalankan ulang.
 */
class ImportSuppliers extends Command
{
    protected $signature = 'suppliers:import
        {path : Path file CSV di server (mis. storage/app/imports/data-pemasok.csv)}
        {--dry-run : Hanya tampilkan ringkasan, tidak menulis apa pun ke database}';

    protected $description = 'Impor/migrasi pemasok dari CSV di server (idempoten, upsert, tanpa data di kode).';

    public function handle(): int
    {
        $path   = $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

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
        // Buang BOM UTF-8 pada sel header pertama, normalkan nama kolom.
        $header = array_map(
            fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))),
            $header
        );

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $line    = 1; // baris header

        if ($dryRun) {
            $this->warn('DRY-RUN: tidak ada perubahan yang ditulis.');
        }

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
                $line++;

                // Lewati baris kosong total.
                if ($row === [null] || count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }

                // Selaraskan jumlah kolom dengan header.
                $row = array_pad(array_slice($row, 0, count($header)), count($header), null);
                $data = array_combine($header, $row);

                $name = $this->clean($data['contact_name'] ?? null);
                if ($name === null) {
                    $this->warn("Baris {$line}: contact_name kosong, dilewati.");
                    $skipped++;
                    continue;
                }

                $attributes = $this->mapRow($name, $data);
                $code = $attributes['code'];

                if ($dryRun) {
                    $exists = Supplier::where('code', $code)->exists();
                    $exists ? $updated++ : $created++;
                    continue;
                }

                $existing = Supplier::where('code', $code)->first();
                if ($existing) {
                    $existing->fill($attributes)->save();
                    $updated++;
                } else {
                    Supplier::create($attributes);
                    $created++;
                }
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($fh);
            $this->error("Gagal di sekitar baris {$line}: {$e->getMessage()}");
            return self::FAILURE;
        }

        fclose($fh);

        $this->info(sprintf(
            '%sSelesai. Baris baru: %d, diperbarui: %d, dilewati: %d.',
            $dryRun ? '[DRY-RUN] ' : '',
            $created,
            $updated,
            $skipped
        ));

        return self::SUCCESS;
    }

    /**
     * Petakan satu baris CSV ke kolom tabel `suppliers`.
     */
    private function mapRow(string $name, array $r): array
    {
        $phone = $this->clean($r['phone'] ?? null) ?? $this->clean($r['mobile'] ?? null);

        $address = $this->clean($r['shipping_address'] ?? null)
            ?? $this->clean($r['billing_address'] ?? null);

        $city = $this->clean($r['shipping_city'] ?? null)
            ?? $this->clean($r['billing_city'] ?? null);

        return [
            // Kunci deterministik → idempoten & dedupe nama yang sama.
            'code'           => 'SUP-' . strtoupper(substr(sha1(mb_strtolower($name)), 0, 8)),
            'name'           => $name,
            'company_name'   => null,
            'email'          => $this->clean($r['email'] ?? null),
            'phone'          => $phone,
            'address'        => $address,
            'city'           => $city,
            'tax_id'         => $this->clean($r['npwp'] ?? null),
            'contact_person' => $this->clean($r['contact_position'] ?? null),
            'payment_term'   => $this->clean($r['payment_term'] ?? null) ?? 'NET30',
            'notes'          => $this->buildNotes($r),
            'status'         => Supplier::STATUS_ACTIVE,
        ];
    }

    /**
     * Gabungkan `notes` asli + kolom yang tidak punya kolom sendiri di schema,
     * supaya tidak ada data yang hilang diam-diam. Kolom kosong diabaikan.
     */
    private function buildNotes(array $r): ?string
    {
        $lines = [];

        if ($base = $this->clean($r['notes'] ?? null)) {
            $lines[] = $base;
        }

        $extras = [
            'Fax'            => $r['fax'] ?? null,
            'HP'             => $r['mobile'] ?? null,
            'Area kirim'     => $r['shipping_area'] ?? null,
            'Provinsi kirim' => $r['shipping_province'] ?? null,
            'Kode pos kirim' => $r['shipping_post_code'] ?? null,
            'Alamat tagihan' => $r['billing_address'] ?? null,
            'Area tagihan'   => $r['billing_area'] ?? null,
            'Kota tagihan'   => $r['billing_city'] ?? null,
            'Provinsi tagihan' => $r['billing_province'] ?? null,
            'Kode pos tagihan' => $r['billing_post_code'] ?? null,
            'Dropshipper'    => $r['is_dropshipper'] ?? null,
            'Reseller'       => $r['is_reseller'] ?? null,
            'Sumber kontak'  => $r['contact_source'] ?? null,
            'Detail sumber'  => $r['source_detail'] ?? null,
        ];

        foreach ($extras as $label => $value) {
            if (($v = $this->clean($value)) !== null) {
                $lines[] = "{$label}: {$v}";
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * Rapikan nilai sel: trim, dan anggap string kosong atau placeholder "."
     * sebagai null (data CSV ini banyak berisi "." sebagai isian kosong).
     */
    private function clean($value): ?string
    {
        $v = trim((string) ($value ?? ''));
        if ($v === '' || $v === '.') {
            return null;
        }
        return $v;
    }
}
