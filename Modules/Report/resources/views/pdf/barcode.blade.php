@php
    /** @var array $cells */
    /** @var string $mode */
    /** @var string $paper */
    $paper = $paper ?? 'a4_multi';
    $showPrice = $mode === 'default' || $mode === 'online';
    $showStore = $mode === 'online';
    $fmtPrice = fn ($p) => $p !== null ? 'Rp' . number_format($p, 0, ',', '.') : '-';
    $perPage = in_array($paper, ['thermal_50x40', 'thermal_80x40', 'thermal_40x30', 'thermal_30x20', 'a4_single'], true);

    // Label menumpuk blok (toko + SKU + rak + harga) di ruang yang tingginya
    // tetap. height pada td HANYA memusatkan, tidak membatasi: konten yang
    // lebih tinggi memuaikan barisnya sampai melewati kertas dan memicu
    // halaman kosong. Jadi makin banyak blok pendamping, SKU makin dikecilkan.
    // Rak ikut dihitung karena hanya SKU yang sudah punya rak yang memuatnya.
    $extraBlocks = function (array $cell) use ($showStore, $showPrice): int {
        return ($showStore ? 1 : 0)
            + (($cell['bin'] ?? null) ? 1 : 0)
            + ($showPrice ? 1 : 0);
    };

    // SKU pendek dicetak besar (kebaca dari jarak jauh), SKU panjang mengecil
    // bertahap supaya tetap muat di label tanpa terpotong. Ambang dinaikkan
    // dari kalibrasi lama karena blok nama produk sudah tidak dicetak.
    $tiers = ['sku-lg', 'sku-md', 'sku-sm', 'sku-xs'];
    $skuClass = function (string $sku, int $extras) use ($tiers): string {
        $len = mb_strlen($sku);
        $i = $len <= 16 ? 0 : ($len <= 24 ? 1 : ($len <= 34 ? 2 : 3));

        return $tiers[min($i + min($extras, 2), count($tiers) - 1)];
    };

    // Pengaman SKU tidak wajar panjang, disesuaikan ukuran kertas: label kecil
    // jelas tidak sanggup menampung batas yang sama dengan label besar. Nilai
    // penuh tetap utuh di dalam QR, yang dipangkas hanya teks cetaknya.
    // Batas dipersempit dibanding kalibrasi lama untuk label terkecil: blok rak
    // memakai ruang yang dulu kosong, jadi SKU tidak boleh lagi memakai empat
    // baris penuh — begitu labelnya melar, dompdf melempar seluruh baris ke
    // halaman berikutnya dan lahir halaman kosong berselang-seling.
    $skuCaps = [
        'thermal_80x40' => 64,
        'thermal_50x40' => 44,
        'thermal_40x30' => 42,
        'thermal_30x20' => 28,
        'a4_single'     => 0,
        'a4_multi'      => 44,
    ];
    $skuCapBase = $skuCaps[$paper] ?? 44;

    $skuCapFor = function (int $extras) use ($skuCapBase): int {
        return $extras >= 2 && $skuCapBase > 0
            ? (int) round($skuCapBase * 0.85)
            : $skuCapBase;
    };

    // Pemotongan teks WAJIB di sini, bukan lewat CSS. dompdf tidak memotong
    // konten saat menghitung layout: dengan max-height + overflow hidden
    // teksnya hanya tersembunyi secara visual tapi tingginya tetap dihitung
    // penuh, sehingga label melar melewati kertas dan dompdf melempar seluruh
    // baris ke halaman berikutnya — inilah asal halaman kosong berselang-seling.
    $limits = [
        // Kode rak format bebas (mis. ZONA-BARIS-KOLOM-BOX), jadi batasnya
        // menyisakan ruang di atas kode terpanjang yang dipakai sekarang.
        'thermal_80x40' => ['bin' => 28, 'store' => 40],
        'thermal_50x40' => ['bin' => 24, 'store' => 26],
        'thermal_40x30' => ['bin' => 20, 'store' => 22],
        'thermal_30x20' => ['bin' => 16, 'store' => 24],
        'a4_single'     => ['bin' => 0,  'store' => 0],
        'a4_multi'      => ['bin' => 24, 'store' => 22],
    ];
    $limit = $limits[$paper] ?? $limits['a4_multi'];

    // $max = 0 berarti tanpa batas.
    $clip = function (?string $text, int $max): string {
        $text = trim((string) $text);

        if ($max <= 0 || mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    };
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Barcode Barang</title>
    <style>
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            color: #000;
        }
        .empty { text-align: center; padding: 40px; color: #666; font-size: 11px; }

        @if($perPage)
        .page {
            width: 100%;
            page-break-after: always;
        }
        .page:last-child { page-break-after: auto; }

        /* Jangan set width:100% di .label — dompdf tidak mendukung box-sizing,
           jadi width + padding akan menjumlah dan meluber keluar kertas.
           Div block sudah mengisi lebar kertas dengan sendirinya. */
        /* table-layout: fixed wajib — dengan auto layout dompdf melebarkan kolom
           teks mengikuti SKU terpanjang sehingga label meluber keluar kertas. */
        table.label-row { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.label-row td { vertical-align: middle; padding: 0; }
        td.qr-cell img { display: block; }
        td.text-cell { padding-left: 3mm; }
        .store {
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 1mm;
            overflow: hidden;
            white-space: nowrap;
        }
        .sku {
            font-weight: 700;
            line-height: 1.15;
            overflow-wrap: anywhere;
        }
        .bin { font-weight: 700; line-height: 1.15; margin-top: 1mm; overflow-wrap: anywhere; }
        .price { font-weight: 700; margin-top: 1mm; }
        @endif

        @if($paper === 'thermal_50x40')
        @page { margin: 0; }
        .label { padding: 2mm; }
        body { font-size: 7pt; }
        table.label-row td { height: 35mm; }
        td.qr-cell { width: 22mm; }
        td.qr-cell img { width: 21mm; height: 21mm; }
        td.text-cell { padding-left: 2mm; }
        .store { font-size: 4.5pt; }
        .sku-lg { font-size: 13pt; }
        .sku-md { font-size: 11pt; }
        .sku-sm { font-size: 8.5pt; }
        .sku-xs { font-size: 6.5pt; }
        .bin { font-size: 8pt; }
        .price { font-size: 8pt; }
        @endif

        @if($paper === 'thermal_80x40')
        @page { margin: 0; }
        .label { padding: 2mm; }
        body { font-size: 8pt; }
        table.label-row td { height: 35mm; }
        td.qr-cell { width: 30mm; }
        td.qr-cell img { width: 29mm; height: 29mm; }
        .store { font-size: 5.5pt; }
        .sku-lg { font-size: 15pt; }
        .sku-md { font-size: 12.5pt; }
        .sku-sm { font-size: 10pt; }
        .sku-xs { font-size: 8pt; }
        .bin { font-size: 10pt; }
        .price { font-size: 9pt; }
        @endif

        @if($paper === 'thermal_40x30')
        @page { margin: 0; }
        .label { padding: 1.5mm; }
        body { font-size: 6pt; }
        table.label-row td { height: 26mm; }
        td.qr-cell { width: 18mm; }
        td.qr-cell img { width: 17mm; height: 17mm; }
        td.text-cell { padding-left: 1.5mm; }
        .store { font-size: 4pt; }
        .sku-lg { font-size: 9pt; }
        .sku-md { font-size: 7.5pt; }
        .sku-sm { font-size: 6.2pt; }
        .sku-xs { font-size: 5.2pt; }
        .bin { font-size: 6pt; margin-top: 0.8mm; }
        .price { font-size: 6pt; }
        @endif

        @if($paper === 'thermal_30x20')
        @page { margin: 0; }
        .label { padding: 1mm; }
        body { font-size: 5pt; }
        table.label-row td { height: 16mm; }
        td.qr-cell { width: 12mm; }
        td.qr-cell img { width: 11.5mm; height: 11.5mm; }
        td.text-cell { padding-left: 1.2mm; }
        .store { font-size: 3.5pt; margin-bottom: 0.3mm; }
        .sku-lg { font-size: 7.5pt; }
        .sku-md { font-size: 6.5pt; }
        .sku-sm { font-size: 5.2pt; }
        .sku-xs { font-size: 4.4pt; }
        .bin { font-size: 5pt; margin-top: 0.5mm; }
        .price { font-size: 5pt; margin-top: 0.5mm; }
        @endif

        @if($paper === 'a4_single')
        @page { margin: 20mm; }
        body { font-size: 12pt; }
        td.qr-cell { width: 90mm; }
        td.qr-cell img { width: 85mm; height: 85mm; }
        .store { font-size: 12pt; }
        .sku { font-size: 22pt; letter-spacing: 1pt; }
        .bin { font-size: 16pt; margin-top: 4mm; }
        .price { font-size: 16pt; }
        td.text-cell { padding-left: 12mm; }
        @endif

        @if(!$perPage)
        @page { margin: 8mm; }
        table.grid { width: 100%; border-collapse: separate; border-spacing: 6px; table-layout: fixed; }
        table.grid > tbody > tr > td {
            width: 50%;
            vertical-align: top;
            padding: 0;
        }
        .label {
            border: 1px solid #000;
            background: #fff;
            padding: 6px 8px;
            height: 78px;
        }
        table.label-row { width: 100%; border-collapse: collapse; }
        table.label-row td { vertical-align: middle; padding: 0; }
        td.qr-cell { width: 72px; }
        td.qr-cell img { width: 66px; height: 66px; display: block; }
        td.text-cell { padding-left: 8px; }
        .store {
            font-size: 7px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.3px; margin-bottom: 2px;
            overflow: hidden; white-space: nowrap;
        }
        .sku { font-size: 12px; font-weight: 700; line-height: 1.15; word-break: break-all; }
        .bin { font-size: 10px; font-weight: 700; line-height: 1.15; margin-top: 4px; word-break: break-all; }
        .price { font-size: 9px; font-weight: 700; margin-top: 4px; }
        @endif
    </style>
</head>
<body>
    @if(count($cells) === 0)
        <div class="empty">Tidak ada label untuk dicetak. Pastikan produk terpilih memiliki
            {{ $mode === 'online' ? 'listing toko yang sudah tersinkron.' : 'SKU aktif.' }}</div>
    @elseif($perPage)
        @foreach($cells as $cell)
            @php($extras = $extraBlocks($cell))
            <div class="page">
                <div class="label">
                    <table class="label-row">
                        <tr>
                            <td class="qr-cell">
                                @if($cell['qr'])
                                    <img src="{{ $cell['qr'] }}" alt="QR">
                                @endif
                            </td>
                            <td class="text-cell">
                                <div class="sku {{ $skuClass($cell['sku'], $extras) }}">{{ $clip($cell['sku'], $skuCapFor($extras)) }}</div>
                                @if($showStore)
                                    <div class="store">{{ $clip($cell['store_name'], $limit['store']) ?: '—' }}</div>
                                @endif
                                @if($cell['bin'])
                                    <div class="bin">RAK {{ $clip($cell['bin'], $limit['bin']) }}</div>
                                @endif
                                @if($showPrice)
                                    <div class="price">{{ $fmtPrice($cell['price']) }}</div>
                                @endif
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        @endforeach
    @else
        <table class="grid">
            @foreach(array_chunk($cells, 2) as $rowCells)
                <tr>
                    @foreach($rowCells as $cell)
                        @php($extras = $extraBlocks($cell))
                        <td>
                            <div class="label">
                                <table class="label-row">
                                    <tr>
                                        <td class="qr-cell">
                                            @if($cell['qr'])
                                                <img src="{{ $cell['qr'] }}" alt="QR">
                                            @endif
                                        </td>
                                        <td class="text-cell">
                                            <div class="sku {{ $skuClass($cell['sku'], $extras) }}">{{ $clip($cell['sku'], $skuCapFor($extras)) }}</div>
                                            @if($showStore)
                                                <div class="store">{{ $clip($cell['store_name'], $limit['store']) ?: '—' }}</div>
                                            @endif
                                            @if($cell['bin'])
                                                <div class="bin">RAK {{ $clip($cell['bin'], $limit['bin']) }}</div>
                                            @endif
                                            @if($showPrice)
                                                <div class="price">{{ $fmtPrice($cell['price']) }}</div>
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                    @endforeach
                    @for($i = count($rowCells); $i < 2; $i++)
                        <td></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
