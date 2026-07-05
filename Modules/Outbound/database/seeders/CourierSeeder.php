<?php

namespace Modules\Outbound\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Outbound\Models\Courier;

/**
 * Production-safe seeder untuk master data Courier (kurir/ekspedisi).
 *
 * Karakteristik:
 * - Idempotent: aman dijalankan berkali-kali. Baris yang sudah ada (match by code)
 *   tidak akan disentuh; baris baru diinsert.
 * - Global (tenant_id = null): kurir muncul di list global. Untuk skema per-tenant,
 *   duplikasi record per tenant harus dilakukan dengan seeder lain.
 * - Kode di-generate deterministik dari nama (slug uppercase). Bentrok
 *   diselesaikan dengan suffix `_2`, `_3`, dst.
 * - Daftar ini sudah dikonsolidasi (lihat PLANNING-KONSOLIDASI-KURIR.md di root
 *   repo & `ConsolidateCouriersCommand`): varian per-layanan channel (mis.
 *   "JNE REG"/"JNE YES"/"JNE OKE", "SPX Instant"/"SPX Standard") sudah digabung
 *   jadi satu nama kanonik per kurir (JNE, SPX, dst), supaya seed di environment
 *   baru tidak menghasilkan lagi daftar kurir yang pecah/berlebihan.
 *
 * Cara jalan (production):
 *   php artisan module:seed Outbound --class=CourierSeeder
 * atau via DB seeder root:
 *   php artisan db:seed --class="Modules\\Outbound\\Database\\Seeders\\CourierSeeder"
 */
class CourierSeeder extends Seeder
{
    public function run(): void
    {
        $names = $this->courierNames();

        DB::transaction(function () use ($names) {
            foreach ($names as $name) {
                $normalized = trim($name);
                if ($normalized === '') {
                    continue;
                }

                $code = $this->uniqueCodeFor($normalized);

                Courier::firstOrCreate(
                    ['code' => $code],
                    [
                        'name'                     => $normalized,
                        'tracking_url'             => null,
                        'logo_url'                 => null,
                        'is_active'                => true,
                        'supported_shipment_types' => null,
                        'metadata'                 => null,
                        'tenant_id'                => null,
                    ]
                );
            }
        });
    }

    private function uniqueCodeFor(string $name): string
    {
        $base = $this->makeCode($name);
        $code = $base;
        $i = 2;

        while (
            Courier::where('code', $code)
                ->where('name', '!=', $name)
                ->exists()
        ) {
            $code = $base . '_' . $i;
            $i++;
        }

        return $code;
    }

    private function makeCode(string $name): string
    {
        $slug = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($slug === false || $slug === '') {
            $slug = $name;
        }

        $slug = strtoupper($slug);
        $slug = preg_replace('/[^A-Z0-9]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        if ($slug === '') {
            $slug = 'COURIER_' . strtoupper(substr(md5($name), 0, 8));
        }

        if (strlen($slug) > 120) {
            $slug = substr($slug, 0, 100) . '_' . strtoupper(substr(md5($name), 0, 8));
        }

        return $slug;
    }

    /**
     * @return string[]
     */
    private function courierNames(): array
    {
        return [
            '21 Express',
            'ABC TRANSPORT',
            'ACC CARGO',
            'ACC LOGISTIK',
            'ADAM JAYA SAKTI',
            'AGUNG PRIMA',
            'AJU-EXPRESS',
            'ALKEN LINES',
            'AMAN SAMUDERA',
            'AMAN SURYA NUSA',
            'AN Putra Transport',
            'ANEKA LOGISTIK',
            'ANGKASA DIRGAMANDIRI',
            'ANTARIKSA ANTARNUSA',
            'AR KARYATI',
            'ARIFIN DIRGANTARA',
            'ARMADA JAYA',
            'ASM EKSPEDISI',
            'ATLAS',
            'ATOM MULTI A',
            'Acm',
            'Adam',
            'Agung',
            'Agung Mabes',
            'Al-Kafii Cargo',
            'Amalindo Indonesia',
            'Ambil Sendiri',
            'Ambil Sendiri di PT Behaestex (Khusus Gresik)',
            'Ambil ke pabrik',
            'Ambil ke toko',
            'Ambra Logistik',
            'Angkunas',
            'AnterAja',
            'Anugerah Selamat Sejahtera',
            'Armada Pabrik',
            'Armada Pratama',
            'Auto Cargo',
            'BALI PRIMA',
            'BANYUWANGI CEPAT',
            'BARAKA SARANA TAMA',
            'BERKAH CAHAYA TRANS',
            'BERKAT SEJATI',
            'BINA USAHA',
            'BINTANG MAS',
            'BNE',
            'BUANA LANGGENG JAYA',
            'BUANA RAYA EKSPRES',
            'BUANA TRAVEL',
            'Baraka',
            'Bariz',
            'Berkat',
            'Berkat Abadi Jaya',
            'Berkat Maju Jaya',
            'Berkat Maju Sentosa',
            'Blitz Marketplace',
            'Blitz-ID INSTANT',
            'Buana Raya Express',
            'Bus',
            'CANDRA DEWI',
            'CATUR MANDIRITAMA',
            'CKB CARGO',
            'CMC',
            'CMC Express',
            'CNT Cargo',
            'CV. Alwi Jaya Transport',
            'Cargo',
            'Charter Pick-Up',
            'Cinta Saudara',
            'Cipta Maju',
            'Citra Express',
            'Cosmos Pusat',
            'Custom Courier',
            'Custom Logistik Service Normal',
            'C²',
            'D\'SEA LOGISTIC',
            'DAKOGA BUANA SEMESTA',
            'DAMAI',
            'DELTA',
            'DEPO SPILL',
            'DEPO TEMAS',
            'DEWATA LINTAS JAYA',
            'DIKIRIM PENJUAL',
            'DINOYO BARU BERSATU',
            'DINOYO PUTRA',
            'DMX Cargo',
            'DRATRANS',
            'DSS',
            'DUTA TRANS',
            'Dakota Cargo',
            'Deliveree',
            'Den Logistik',
            'Depo Samas Surabaya',
            'Dot Ekspres',
            'Duta Cargo',
            'EMS KERETA API',
            'EMS TRUCK',
            'Ekspedisi Cokro',
            'Ekspedisi Lintas Jawa-Sumatra',
            'Ekspedisi Margomulyo Baru',
            'Ekspedisi Transpapua',
            'Etobee',
            'Exp Sindo',
            'Express Jaya Gemilang',
            'FAJAR',
            'FIRST LOGISTIK',
            'FREE',
            'GARUDA LINTAS',
            'GARUDA LINTAS BORNEO',
            'GENDHIS',
            'GLOBAL',
            'GOJEK',
            'Gemilang Asri Maju',
            'GoSend',
            'GoTo Logistics GTL Standard',
            'Gocar',
            'GrabExpress',
            'Graha Mas Kapuk',
            'HARAPAN JAYA',
            'HASIL BUNI',
            'HD-Express',
            'HERONA',
            'HImeji',
            'HWU',
            'Herona Express',
            'Hira',
            'Hira Logistic',
            'Hyra',
            'ID DFOD',
            'ID Express',
            'INTERNAL',
            'Indah Cargo',
            'J&T',
            'J-Express',
            'JAGO EXPRESS',
            'JAVA EXPRESS',
            'JAYA MAS SURABAYA',
            'JAYA MULYA',
            'JNE',
            'JX',
            'JX Standart',
            'Jago Jaya Express',
            'Jago Pack',
            'Jaya Alam Laksana',
            'Jaya Express',
            'KALIMAS',
            'KALOG created',
            'KARTIKA',
            'KARYATI',
            'KBI - EXPEDISI KERETA',
            'KIA Cargo',
            'KM INDAH',
            'KRESNA',
            'KRISNA JAYA',
            'KS',
            'KWK',
            'Karuna Travel',
            'Karunia Cipta Logistic',
            'Karunia Indah 8 (KI8)',
            'Karya Agung',
            'Kedaton Jaya',
            'Kereta',
            'Kerry Express',
            'Kirim Sendiri',
            'Kobra',
            'Kresna Jaya Ekspedisi',
            'Kurir Rekomendasi',
            'Kurir Toko',
            'Kurir Toko ST-Toms',
            'LAKUSIX NEW',
            'LEL EXPRESS',
            'LESTARI',
            'LEX MP',
            'LIMA JAYA',
            'LIOON - Regpack',
            'LJR',
            'LNP',
            'LSJ Express',
            'Lalamove',
            'Laris Cargo',
            'Lintas Abadi',
            'Lion Parcel',
            'MEDIA LOGISTIK',
            'MEGA EXPRESS',
            'MEKAR JAYA',
            'MERDEKA',
            'MEX',
            'MKS',
            'MKS BERKAT MAJU JAYA',
            'MPS',
            'MULIO CITRA ANGKASA',
            'MULTI JAYA',
            'MULYA BAKTI',
            'MUTIARA',
            'Mail Express',
            'Maju Bersama',
            'Maju Express',
            'Malaysia',
            'Maxim',
            'Merah Jaya',
            'Merpati',
            'Merpati Travel',
            'Meta Baru Kapuk',
            'Mex Berlian',
            'Micro',
            'Mitra Wibowo',
            'Muat Cargo',
            'Mulio Angkasa',
            'Multi Express',
            'NASRU',
            'NN Mandiri',
            'NUSANTARA EXPRESS',
            'NWF AJH',
            'Naga Lintas',
            'Naga Lintas Wahid',
            'New Putra Duta',
            'Ninja Xpress',
            'Nirmala Sunter',
            'Nusantara',
            'OEXPRESS',
            'Olympic Abadi (PT. Angkasa Dirga Mandiri)',
            'PAHALA KEBCANA',
            'PANDUSIWI',
            'PCP Regular',
            'PERSADA MULIA',
            'PEXPRESS',
            'PODO HASIL',
            'PRIMA JASA ABADI',
            'PRISMA CARGO',
            'PT. Angkutan Utama Perkasa',
            'PT. Arthalapan Strategi Logistik',
            'PT. Soekini',
            'Panca Jaya',
            'Papandayan Cargo',
            'Pasific Jaya Express',
            'Paxel',
            'Pegasus',
            'Pelunasan Shopee',
            'Pickup in Pusat',
            'Pickup: Gojek, Delivery: Go-Jek MP',
            'Pickup: LEX ID, Delivery: LEX ID',
            'Pos Indonesia',
            'Putra Bengawan',
            'RAX CARGO',
            'RAYSPEED',
            'RAYSPEED - MY',
            'RAYSPEED - SG (REG)',
            'RCL (Red Carpet Logistics)',
            'REX',
            'RIZKI AGUNG',
            'RM Ekspress',
            'RM Express',
            'RPX PAS Economy',
            'RPX PAS Heavy Weight',
            'RPX PAS NextDay',
            'RPX PAS Regular',
            'RPX PAS SameDay',
            'Rahayu Express',
            'Raya Kita',
            'Rayspeed Asia',
            'Red Ex',
            'Riona Cargo',
            'SAHABAT BELITUNG EXPRESS',
            'SAKURA EXPRES',
            'SAP Express',
            'SARANA UTAMA CARGO',
            'SDR',
            'SEGARA',
            'SHP Cargo',
            'SINAR JAYA',
            'SPX',
            'SRIWIJAYA PRIMA',
            'STAR CARGO',
            'SUBOKA PRATAMA',
            'SULUNG',
            'SUMBER AGUNG',
            'SUMBER BARU',
            'SUMBER SARI',
            'SUMBER URIP CARGO',
            'Sajira',
            'Samas Express',
            'Sentral Cargo',
            'Sentral Cargo Darat',
            'Sentral Cargo Udara',
            'SiCepat',
            'Sinar Dagang',
            'Sinar Jaya Ekspedisi',
            'Sinar Mandiri Cepat',
            'Sinar Shuttle',
            'Sinar Trans Express',
            'Speedy',
            'Sumber Urip',
            'TIKI',
            'TLX Rajawali',
            'TRIJAYA',
            'TRIJAYA LINTAS',
            'TRIWARNO',
            'Tam Cargo',
            'Teba',
            'Teman Express',
            'Terang Bersaudara Kapuk',
            'Timbul Jaya',
            'Titian Mas',
            'Travel',
            'Tri Tunggal',
            'Triadhipa Logistics',
            'Trijasa Jaya Utama',
            'Tritunggal Jakarta',
            'Trobos Ekspedisi',
            'UBZR',
            'Virgotrans Surabaya',
            'Wahana',
            'Warrior Cargo',
            'Warrior Express',
            'Wira Agung',
            'YUDA PERKASA',
            'kurir 4848',
        ];
    }
}
