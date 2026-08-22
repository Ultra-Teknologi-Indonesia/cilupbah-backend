<?php

namespace Modules\Warehouse\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Warehouse\Models\BinMultiSkuRule;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\BinMultiSkuRuleService;
use Tests\TestCase;

class BinMultiSkuRuleApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
        BinMultiSkuRuleService::flushPatternCache();
    }

    private function kecil(): Location
    {
        return Location::where('location_code', Location::SYSTEM_KECIL_CODE)->first()
            ?? Location::factory()->create([
                'location_code' => Location::SYSTEM_KECIL_CODE,
                'is_small_warehouse' => true,
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

    public function test_can_create_and_list_rules_with_matched_count(): void
    {
        $loc = $this->kecil();
        $this->makeBin($loc, 'GK-14-K1-B1');
        $this->makeBin($loc, 'GK-14-K1-B2');
        $this->makeBin($loc, 'O-A1-K1-X1');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/locations/{$loc->id}/multi-sku-rules", [
                'pattern' => 'GK-*',
                'note' => 'Slow moving',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.pattern', 'GK-*')
            ->assertJsonPath('data.matched_count', 2);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/locations/{$loc->id}/multi-sku-rules")
            ->assertStatus(200)
            ->assertJsonPath('data.0.pattern', 'GK-*')
            ->assertJsonPath('data.0.note', 'Slow moving')
            ->assertJsonPath('data.0.matched_count', 2);
    }

    private function suggestionMap(Location $loc): array
    {
        $data = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/locations/{$loc->id}/multi-sku-rules/suggestions")
            ->assertStatus(200)
            ->json('data');

        return collect($data)->pluck('matched_count', 'pattern')->all();
    }

    public function test_suggestions_offer_top_level_prefixes_with_counts(): void
    {
        $loc = $this->kecil();
        $this->makeBin($loc, 'GK-14-K1-B1');
        $this->makeBin($loc, 'GK-14-K1-B2');
        $this->makeBin($loc, 'O-A1-K1-X1');

        $map = $this->suggestionMap($loc);

        $this->assertSame(2, $map['GK-*'] ?? null);
        $this->assertArrayHasKey('O-*', $map);
    }

    public function test_suggestions_include_a_deep_prefix_that_narrows_its_parent(): void
    {
        $loc = $this->kecil();
        $this->makeBin($loc, 'O-LX-KX-KANTOR');
        $this->makeBin($loc, 'O-LX-KX-REFUND');
        for ($i = 1; $i <= 5; $i++) {
            $this->makeBin($loc, "O-A1-K1-X{$i}");
        }

        $map = $this->suggestionMap($loc);

        $this->assertSame(2, $map['O-LX-*'] ?? null);
        $this->assertSame(7, $map['O-*'] ?? null);
    }

    public function test_suggestions_drop_deeper_prefixes_that_add_nothing(): void
    {
        $loc = $this->kecil();
        $this->makeBin($loc, 'LX-BX-KX-TRANS');
        $this->makeBin($loc, 'LX-BX-KX-ADJST');

        $map = $this->suggestionMap($loc);

        $this->assertSame(2, $map['LX-*'] ?? null);
        $this->assertArrayNotHasKey('LX-BX-*', $map);
        $this->assertArrayNotHasKey('LX-BX-KX-*', $map);
    }

    public function test_suggestions_skip_large_deep_groups(): void
    {
        $loc = $this->kecil();
        for ($i = 1; $i <= 60; $i++) {
            $this->makeBin($loc, sprintf('O-A1-K1-X%03d', $i));
        }
        $this->makeBin($loc, 'O-B2-K1-X1');

        $map = $this->suggestionMap($loc);

        $this->assertSame(61, $map['O-*'] ?? null);
        $this->assertArrayNotHasKey('O-A1-*', $map);
        $this->assertSame(1, $map['O-B2-*'] ?? null);
    }

    public function test_code_without_a_separator_is_not_suggested(): void
    {
        $loc = $this->kecil();
        $this->makeBin($loc, 'TANPAPEMISAH');
        $this->makeBin($loc, 'GK-15-K1-B1');

        $map = $this->suggestionMap($loc);

        $this->assertArrayNotHasKey('TANPAPEMISAH-*', $map);
        $this->assertSame(1, $map['GK-*'] ?? null);
    }

    public function test_suggestions_carry_sample_bin_codes(): void
    {
        $loc = $this->kecil();
        $this->makeBin($loc, 'GK-14-K1-B1');

        $data = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/locations/{$loc->id}/multi-sku-rules/suggestions")
            ->assertStatus(200)
            ->json('data');

        $gk = collect($data)->firstWhere('pattern', 'GK-*');

        $this->assertSame(['GK-14-K1-B1'], $gk['samples']);
    }

    public function test_duplicate_pattern_is_rejected(): void
    {
        $loc = $this->kecil();

        $payload = ['pattern' => 'GK-*'];

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/locations/{$loc->id}/multi-sku-rules", $payload)
            ->assertStatus(201);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/locations/{$loc->id}/multi-sku-rules", $payload)
            ->assertStatus(422);
    }

    public function test_pattern_is_required(): void
    {
        $loc = $this->kecil();

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/locations/{$loc->id}/multi-sku-rules", ['pattern' => ''])
            ->assertStatus(422);
    }

    public function test_rules_are_rejected_on_non_strict_location(): void
    {
        $pusat = Location::factory()->create([
            'location_code' => Location::SYSTEM_PUSAT_CODE,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/locations/{$pusat->id}/multi-sku-rules")
            ->assertStatus(422);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/locations/{$pusat->id}/multi-sku-rules", ['pattern' => 'GK-*'])
            ->assertStatus(422);
    }

    public function test_can_update_and_delete_rule(): void
    {
        $loc = $this->kecil();
        $this->makeBin($loc, 'GK-14-K1-B1');
        $this->makeBin($loc, 'O-LX-KX-KANTOR');

        $rule = BinMultiSkuRule::create([
            'location_id' => $loc->id,
            'pattern' => 'GK-*',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/locations/{$loc->id}/multi-sku-rules/{$rule->id}", [
                'pattern' => 'O-LX-KX-*',
                'note' => 'Elektronik kantor',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.pattern', 'O-LX-KX-*')
            ->assertJsonPath('data.matched_count', 1);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/locations/{$loc->id}/multi-sku-rules/{$rule->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('bin_multi_sku_rules', ['id' => $rule->id]);
    }

    public function test_unknown_rule_returns_404(): void
    {
        $loc = $this->kecil();
        $missing = \Illuminate\Support\Str::uuid()->toString();

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/locations/{$loc->id}/multi-sku-rules/{$missing}")
            ->assertStatus(404);
    }

    public function test_bin_resource_exposes_allows_multi_sku(): void
    {
        $loc = $this->kecil();
        $this->makeBin($loc, 'GK-14-K1-B1');
        $this->makeBin($loc, 'O-A1-K1-X1');

        BinMultiSkuRule::create(['location_id' => $loc->id, 'pattern' => 'GK-*']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/locations/{$loc->id}/bins?per_page=50")
            ->assertStatus(200);

        $byCode = collect($response->json('data'))->keyBy('bin_final_code');

        $this->assertTrue($byCode['GK-14-K1-B1']['allows_multi_sku']);
        $this->assertFalse($byCode['O-A1-K1-X1']['allows_multi_sku']);
    }
}
