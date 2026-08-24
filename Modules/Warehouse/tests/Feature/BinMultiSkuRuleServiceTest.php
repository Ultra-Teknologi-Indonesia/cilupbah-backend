<?php

namespace Modules\Warehouse\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Warehouse\Models\BinMultiSkuRule;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\BinMultiSkuRuleService;
use Tests\TestCase;

class BinMultiSkuRuleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create();
        BinMultiSkuRuleService::flushPatternCache();
    }

    private function makeLocation(bool $isSmall = true): Location
    {
        return Location::factory()->create([
            'location_code' => 'WH-TEST-' . Str::random(4),
            'is_warehouse' => true,
            'is_small_warehouse' => $isSmall,
        ]);
    }

    private function makeBin(Location $loc, string $code): LocationBin
    {
        return LocationBin::factory()->create([
            'location_id' => $loc->id,
            'bin_final_code' => $code,
            'is_inbound' => false,
            'is_stock_acknowledged' => true,
        ]);
    }

    private function addRule(Location $loc, string $pattern, bool $active = true): BinMultiSkuRule
    {
        return BinMultiSkuRule::create([
            'location_id' => $loc->id,
            'pattern' => $pattern,
            'is_active' => $active,
        ]);
    }

    public function test_prefix_pattern_matches_only_its_own_group(): void
    {
        $loc = $this->makeLocation();
        $this->addRule($loc, 'GK-*');

        $gk = $this->makeBin($loc, 'GK-14-K1-B1');
        $shelf = $this->makeBin($loc, 'O-A1-K1-X1');

        $service = app(BinMultiSkuRuleService::class);

        $this->assertTrue($service->allowsMultiSku($gk));
        $this->assertFalse($service->allowsMultiSku($shelf));
    }

    public function test_mid_string_pattern_separates_bins_sharing_a_prefix(): void
    {
        $loc = $this->makeLocation();
        $this->addRule($loc, 'O-LX-KX-*');

        $kantor = $this->makeBin($loc, 'O-LX-KX-KANTOR');
        $refund = $this->makeBin($loc, 'O-LX-KX-REFUND');
        $shelf = $this->makeBin($loc, 'O-A1-K1-X1');

        $service = app(BinMultiSkuRuleService::class);

        $this->assertTrue($service->allowsMultiSku($kantor));
        $this->assertTrue($service->allowsMultiSku($refund));
        $this->assertFalse($service->allowsMultiSku($shelf));
    }

    public function test_pattern_matching_is_case_insensitive(): void
    {
        $loc = $this->makeLocation();
        $this->addRule($loc, 'gk-*');

        $bin = $this->makeBin($loc, 'GK-14-K1-B1');

        $this->assertTrue(app(BinMultiSkuRuleService::class)->allowsMultiSku($bin));
        $this->assertSame(1, app(BinMultiSkuRuleService::class)->countMatching($loc->id, 'gk-*'));
    }

    public function test_underscore_is_literal_not_a_sql_wildcard(): void
    {
        $loc = $this->makeLocation();

        $this->makeBin($loc, 'GK_1');
        $this->makeBin($loc, 'GKX1');

        $service = app(BinMultiSkuRuleService::class);

        $this->assertSame(1, $service->countMatching($loc->id, 'GK_1'));
        $this->assertSame(['GK_1'], $service->sampleMatching($loc->id, 'GK_1'));
    }

    public function test_percent_is_literal_not_a_sql_wildcard(): void
    {
        $loc = $this->makeLocation();

        $this->makeBin($loc, 'GK%1');
        $this->makeBin($loc, 'GK-99');

        $this->assertSame(1, app(BinMultiSkuRuleService::class)->countMatching($loc->id, 'GK%1'));
    }

    public function test_question_mark_is_literal_in_both_engines(): void
    {
        $loc = $this->makeLocation();
        $this->addRule($loc, 'GK?1');

        $literal = $this->makeBin($loc, 'GK?1');
        $singleChar = $this->makeBin($loc, 'GKA1');

        $service = app(BinMultiSkuRuleService::class);

        $this->assertTrue($service->allowsMultiSku($literal));
        $this->assertFalse($service->allowsMultiSku($singleChar));
        $this->assertSame(1, $service->countMatching($loc->id, 'GK?1'));
    }

    public function test_inactive_rule_is_ignored(): void
    {
        $loc = $this->makeLocation();
        $this->addRule($loc, 'GK-*', false);

        $bin = $this->makeBin($loc, 'GK-14-K1-B1');

        $this->assertFalse(app(BinMultiSkuRuleService::class)->allowsMultiSku($bin));
    }

    public function test_rule_is_scoped_to_its_location(): void
    {
        $kecil = $this->makeLocation();
        $pusat = $this->makeLocation(false);

        $this->addRule($kecil, 'GK-*');

        $binPusat = $this->makeBin($pusat, 'GK-14-K1-B1');

        $this->assertFalse(app(BinMultiSkuRuleService::class)->allowsMultiSku($binPusat));
    }

    public function test_no_rules_means_nothing_allows_multi_sku(): void
    {
        $loc = $this->makeLocation();
        $bin = $this->makeBin($loc, 'GK-14-K1-B1');

        $this->assertFalse(app(BinMultiSkuRuleService::class)->allowsMultiSku($bin));
    }

    public function test_cache_is_flushed_when_a_rule_is_saved_or_deleted(): void
    {
        $loc = $this->makeLocation();
        $bin = $this->makeBin($loc, 'GK-14-K1-B1');
        $service = app(BinMultiSkuRuleService::class);

        $this->assertFalse($service->allowsMultiSku($bin));

        $rule = $this->addRule($loc, 'GK-*');
        $this->assertTrue($service->allowsMultiSku($bin));

        $rule->delete();
        $this->assertFalse($service->allowsMultiSku($bin));
    }
}
