<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class LazadaSyncCategoryAttributesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $channelCategoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
        config([
            'services.lazada.app_key' => 'k',
            'services.lazada.app_secret' => 's',
            'services.lazada.base_url' => 'https://api.lazada.co.id/rest',
        ]);

        $channel = Channel::create(['code' => 'lazada', 'name' => 'Lazada']);

        ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'LZ1',
            'shop_name' => 'Toko',
            'access_token' => 'tok',
            'is_active' => true,
        ]);

        $this->channelCategoryId = Uuid::uuid7()->toString();
        DB::table('channel_categories')->insert([
            'id' => $this->channelCategoryId,
            'channel_id' => $channel->id,
            'external_id' => 'CAT-100',
            'parent_external_id' => '0',
            'name' => 'Handphone',
            'is_leaf' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fakeAttributes(): void
    {
        Http::fake([
            '*category/attributes/get*' => Http::response(['data' => [
                ['name' => 'brand', 'label' => 'Brand', 'is_mandatory' => 1, 'input_type' => 'singleSelect',
                    'options' => [['name' => 'Nike'], ['name' => 'Adidas']]],
                ['name' => 'warranty_type', 'label' => 'Warranty Type', 'is_mandatory' => 0, 'input_type' => 'multiSelect',
                    'options' => [['name' => 'No Warranty']]],
                ['name' => 'short_description', 'label' => 'Short Description', 'is_mandatory' => 1, 'input_type' => 'text',
                    'options' => []],
            ]], 200),
        ]);
    }

    public function test_sync_persists_attributes_and_options(): void
    {
        $this->fakeAttributes();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/sync/category-attributes', ['shop_id' => 'LZ1', 'category_id' => 'CAT-100'])
            ->assertOk()
            ->assertJsonPath('data.synced', 3);

        $this->assertDatabaseHas('channel_attributes', [
            'channel_category_id' => $this->channelCategoryId,
            'external_id' => 'brand',
            'name' => 'Brand',
            'is_required' => true,
        ]);

        $this->assertDatabaseHas('channel_attributes', [
            'external_id' => 'warranty_type',
            'is_required' => false,
            'is_multiple' => true,
        ]);

        $brand = DB::table('channel_attributes')->where('external_id', 'brand')->first();
        $this->assertDatabaseHas('channel_attribute_options', [
            'channel_attribute_id' => $brand->id,
            'external_id' => 'Nike',
        ]);
        $this->assertEquals(2, DB::table('channel_attribute_options')->where('channel_attribute_id', $brand->id)->count());
    }

    public function test_sync_is_idempotent(): void
    {
        $this->fakeAttributes();

        $payload = ['shop_id' => 'LZ1', 'category_id' => 'CAT-100'];
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/lazada/sync/category-attributes', $payload)->assertOk();
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/lazada/sync/category-attributes', $payload)->assertOk();

        $this->assertEquals(3, DB::table('channel_attributes')->where('channel_category_id', $this->channelCategoryId)->count());
        $brand = DB::table('channel_attributes')->where('external_id', 'brand')->first();
        $this->assertEquals(2, DB::table('channel_attribute_options')->where('channel_attribute_id', $brand->id)->count());
    }

    public function test_unsynced_category_returns_422(): void
    {
        $this->fakeAttributes();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/sync/category-attributes', ['shop_id' => 'LZ1', 'category_id' => 'NOPE'])
            ->assertStatus(422);
    }
}
