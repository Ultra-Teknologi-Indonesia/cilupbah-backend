<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Support\TikTokErrorCatalog;
use PHPUnit\Framework\TestCase;

class TikTokErrorCatalogTest extends TestCase
{
    public function test_token_codes_classified_as_token(): void
    {
        foreach ([40100, 40102, 40103] as $code) {
            $this->assertSame(TikTokErrorCatalog::TOKEN, TikTokErrorCatalog::resolve($code, 'expired')['category'], (string) $code);
        }
    }

    public function test_accepts_string_and_int_codes(): void
    {
        $a = TikTokErrorCatalog::resolve(12052073);
        $b = TikTokErrorCatalog::resolve('12052073');
        $this->assertSame($a['category'], $b['category']);
        $this->assertSame(TikTokErrorCatalog::USER_FIXABLE, $a['category']);
    }

    public function test_rate_limit_and_internal_are_retryable(): void
    {
        foreach (['36009002', '36009003', '12001000', '33001002', '12052109'] as $code) {
            $this->assertSame(TikTokErrorCatalog::RETRYABLE, TikTokErrorCatalog::resolve($code)['category'], $code);
        }
    }

    public function test_validation_codes_are_user_fixable(): void
    {
        foreach (['12052015', '12052073', '12019013', '12052023', '12052550', '12038005'] as $code) {
            $this->assertSame(TikTokErrorCatalog::USER_FIXABLE, TikTokErrorCatalog::resolve($code)['category'], $code);
        }
    }

    public function test_state_and_permission_codes_are_fatal(): void
    {
        foreach (['12052032', '12052034', '12052048', '12052700', '12052038', '12052901'] as $code) {
            $this->assertSame(TikTokErrorCatalog::FATAL, TikTokErrorCatalog::resolve($code)['category'], $code);
        }
    }

    public function test_detail_placeholder_filled_from_message(): void
    {
        $r = TikTokErrorCatalog::resolve('12038014', 'Allowed dimensions: 300x300 to 4000x4000');
        $this->assertStringContainsString('300x300', $r['message']);
    }

    public function test_non_detail_template_ignores_message(): void
    {
        $r = TikTokErrorCatalog::resolve('12052015', 'The product description is required');
        $this->assertSame('Deskripsi produk wajib diisi.', $r['message']);
    }

    public function test_unknown_code_defaults_to_fatal_with_passthrough(): void
    {
        $r = TikTokErrorCatalog::resolve('99999999', 'weird tiktok error');
        $this->assertSame(TikTokErrorCatalog::FATAL, $r['category']);
        $this->assertStringContainsString('weird tiktok error', $r['message']);
    }

    public function test_messages_are_indonesian_user_facing(): void
    {

        $r = TikTokErrorCatalog::resolve('12052034');
        $this->assertSame('Produk ini bukan milik toko Anda.', $r['message']);
    }
}
