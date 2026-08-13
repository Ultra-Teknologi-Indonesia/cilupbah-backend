<?php

namespace Modules\Outbound\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Modules\Outbound\Support\InstantOrderClassifier;
use Tests\TestCase;

class ManualDriverDispatchTest extends TestCase
{
    public static function butuhPanggilDriver(): array
    {
        return [
            'gosend' => ['Gosend Instant'],
            'gosend sameday' => ['GoSend Same Day'],
            'grab' => ['GrabExpress Instant'],
            'grab sameday' => ['GrabExpress Sameday'],
            'gojek' => ['Gojek'],
            'payung instant' => ['Instant'],
            'payung instant prioritas' => ['Instant Prioritas'],
        ];
    }

    public static function tidakButuhPanggilDriver(): array
    {
        return [
            'spx instant' => ['SPX Instant'],
            'spx instant prioritas' => ['SPX Instant Prioritas'],
            'spx sameday' => ['SPX Sameday'],
            'spx hemat' => ['SPX Hemat'],
            'bluebird' => ['Bluebird Kirim'],
            'lazada express' => ['LEX ID'],
            'jne' => ['JNE YES'],
        ];
    }

    #[DataProvider('butuhPanggilDriver')]
    public function test_kurir_yang_butuh_panggil_driver(string $courier): void
    {
        $this->assertTrue(
            InstantOrderClassifier::needsManualDriverDispatch($courier),
            "{$courier} tidak punya label dari marketplace, kurirnya dipanggil manual.",
        );
    }

    #[DataProvider('tidakButuhPanggilDriver')]
    public function test_kurir_yang_resinya_ditarik_normal(string $courier): void
    {
        $this->assertFalse(
            InstantOrderClassifier::needsManualDriverDispatch($courier),
            "{$courier} punya resi dari marketplace, jangan diblokir dari penarikan normal.",
        );
    }

    public function test_shipping_type_instant_tidak_menjaring_kurir_spx(): void
    {
        $this->assertFalse(
            InstantOrderClassifier::needsManualDriverDispatch('SPX Instant', 'SPX Instant', 'INSTANT'),
            'Mapper menulis shipping_type=INSTANT untuk semua kanal instan. Kalau kata "instant" '
                .'dicocokkan ke kolom itu, seluruh pesanan SPX Instant ikut terjaring salah.',
        );
    }

    public function test_payung_instant_tetap_terjaring_lewat_shipping_provider(): void
    {
        $this->assertTrue(
            InstantOrderClassifier::needsManualDriverDispatch(null, 'Instant Prioritas', 'INSTANT'),
            'Shopee memakai "Instant" dan "Instant Prioritas" sebagai nama payung; di baliknya '
                .'Gojek atau Grab yang mengantar, jadi butuh panggil driver.',
        );
    }
}
