<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Support\ShopeeErrorCatalog;
use PHPUnit\Framework\TestCase;

class ShopeeErrorCatalogTest extends TestCase
{
    public function test_real_token_code_classified_as_token(): void
    {
        $r = ShopeeErrorCatalog::resolve('error_token_expired', 'access token expired');
        $this->assertSame(ShopeeErrorCatalog::TOKEN, $r['category']);
    }

    public function test_error_auth_without_token_message_is_not_token(): void
    {
        $r = ShopeeErrorCatalog::resolve('error_auth', 'Total stock must be more than reserved stock.');
        $this->assertSame(ShopeeErrorCatalog::USER_FIXABLE, $r['category']);
        $this->assertStringContainsString('dipesan', mb_strtolower($r['message']));
    }

    public function test_error_auth_empty_message_falls_back_to_token(): void
    {
        $r = ShopeeErrorCatalog::resolve('error_auth', '');
        $this->assertSame(ShopeeErrorCatalog::TOKEN, $r['category']);
    }

    public function test_error_auth_holiday_message_is_fatal(): void
    {
        $r = ShopeeErrorCatalog::resolve('error_auth', 'Please wait for the holiday mode set then to edit item.');
        $this->assertSame(ShopeeErrorCatalog::FATAL, $r['category']);
    }

    public function test_transient_codes_are_retryable(): void
    {
        foreach (['error_network', 'error_server', 'error_system_busy', 'error_inner'] as $code) {
            $this->assertSame(ShopeeErrorCatalog::RETRYABLE, ShopeeErrorCatalog::resolve($code, 'x')['category'], $code);
        }
    }

    public function test_inner_stock_location_is_user_fixable(): void
    {
        $r = ShopeeErrorCatalog::resolve('error_inner', 'Invalid stock location ID');
        $this->assertSame(ShopeeErrorCatalog::USER_FIXABLE, $r['category']);
    }

    public function test_data_codes_are_user_fixable(): void
    {
        foreach (['error_invalid_category', 'error_invalid_brand', 'error_invalid_price', 'error_name_length_limit'] as $code) {
            $this->assertSame(ShopeeErrorCatalog::USER_FIXABLE, ShopeeErrorCatalog::resolve($code)['category'], $code);
        }
    }

    public function test_promotion_lock_is_fatal(): void
    {
        $r = ShopeeErrorCatalog::resolve('error_cannt_delete_in_promotion');
        $this->assertSame(ShopeeErrorCatalog::FATAL, $r['category']);
    }

    public function test_detail_placeholder_filled_from_error_info(): void
    {
        $r = ShopeeErrorCatalog::resolve('error_param', 'Wrong parameters', 'weight is required');
        $this->assertStringContainsString('weight is required', $r['message']);
    }

    public function test_dotted_and_spaced_codes_normalize(): void
    {
        $this->assertSame(ShopeeErrorCatalog::USER_FIXABLE, ShopeeErrorCatalog::resolve('error.param', 'x')['category']);
        $this->assertSame(ShopeeErrorCatalog::USER_FIXABLE, ShopeeErrorCatalog::resolve('error attribute')['category']);
    }

    public function test_product_error_busi_gtin(): void
    {
        $r = ShopeeErrorCatalog::resolve('product.error_busi', 'The GTIN code is mandatory, please check and upload again.');
        $this->assertSame(ShopeeErrorCatalog::USER_FIXABLE, $r['category']);
        $this->assertStringContainsString('GTIN', $r['message']);
    }

    public function test_unknown_code_defaults_to_fatal(): void
    {
        $r = ShopeeErrorCatalog::resolve('error_some_brand_new_code', 'weird');
        $this->assertSame(ShopeeErrorCatalog::FATAL, $r['category']);
    }

    public function test_product_prefixed_server_code_is_retryable(): void
    {
        $r = ShopeeErrorCatalog::resolve('product.error_server', 'server internal error');
        $this->assertSame(ShopeeErrorCatalog::RETRYABLE, $r['category']);
    }

    public function test_error_server_fbs_message_is_user_fixable(): void
    {
        $r = ShopeeErrorCatalog::resolve('error_server', 'The current item belong to the full FBS shop, so normal stock must be equal to 0');
        $this->assertSame(ShopeeErrorCatalog::USER_FIXABLE, $r['category']);
    }

    public function test_error_auth_phone_and_upgrading_are_fatal(): void
    {
        $this->assertSame(ShopeeErrorCatalog::FATAL, ShopeeErrorCatalog::resolve('error_auth', 'The registered phone number of your shop is abnormal')['category']);
        $this->assertSame(ShopeeErrorCatalog::FATAL, ShopeeErrorCatalog::resolve('error_auth', 'shop is upgrading , can not operate')['category']);
    }

    public function test_tier_variation_codes_classified(): void
    {
        $this->assertSame(ShopeeErrorCatalog::USER_FIXABLE, ShopeeErrorCatalog::resolve('error_tier_var_too_many')['category']);
        $this->assertSame(ShopeeErrorCatalog::USER_FIXABLE, ShopeeErrorCatalog::resolve('error_model_count_over_limit')['category']);
        $this->assertSame(ShopeeErrorCatalog::FATAL, ShopeeErrorCatalog::resolve('error_cannt_init_tier_in_promotion')['category']);
    }

    public function test_update_price_stock_codes_classified(): void
    {
        $this->assertSame(ShopeeErrorCatalog::RETRYABLE, ShopeeErrorCatalog::resolve('error_update_price_fail')['category']);
        $this->assertSame(ShopeeErrorCatalog::USER_FIXABLE, ShopeeErrorCatalog::resolve('error_price_out_of_range')['category']);
        $this->assertSame(ShopeeErrorCatalog::USER_FIXABLE, ShopeeErrorCatalog::resolve('error_busi_update_stock_failed')['category']);
        $this->assertSame(ShopeeErrorCatalog::FATAL, ShopeeErrorCatalog::resolve('error_wms_shop_block_upate_stock')['category']);
        $this->assertSame(ShopeeErrorCatalog::FATAL, ShopeeErrorCatalog::resolve('error_item_not_belong_shop')['category']);
    }

    public function test_unlist_and_boost_codes_classified(): void
    {
        $this->assertSame(ShopeeErrorCatalog::RETRYABLE, ShopeeErrorCatalog::resolve('error_boost_item_failed')['category']);
        $this->assertSame(ShopeeErrorCatalog::FATAL, ShopeeErrorCatalog::resolve('error_busi_cannot_delist_reviewing_or_banned_item')['category']);
        $this->assertSame(ShopeeErrorCatalog::USER_FIXABLE, ShopeeErrorCatalog::resolve('error_set_normal_unlisted_item')['category']);
    }
}
