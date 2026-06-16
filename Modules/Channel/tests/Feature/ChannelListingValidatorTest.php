<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class ChannelListingValidatorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Channel $channel;
    private Category $category;
    private string $channelCategoryId;
    private Attribute $brand;
    private string $brandChannelAttrId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();

        $this->channel = Channel::create(['code' => 'lazada', 'name' => 'Lazada']);
        $this->category = Category::create(['name' => 'Handphone']);
        $this->brand = Attribute::firstOrCreate(['name' => 'Brand'], ['type' => 'spec']);

        // Kategori channel (leaf) + pemetaan kategori internal → channel.
        $this->channelCategoryId = Uuid::uuid7()->toString();
        DB::table('channel_categories')->insert([
            'id' => $this->channelCategoryId,
            'channel_id' => $this->channel->id,
            'external_id' => 'CAT-100',
            'parent_external_id' => '0',
            'name' => 'Handphone LZ',
            'is_leaf' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('category_channel_mappings')->insert([
            'category_id' => $this->category->id,
            'channel_category_id' => $this->channelCategoryId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Atribut wajib 'Brand' dengan opsi tertutup Nike/Adidas, dipetakan ke atribut internal. */
    private function addRequiredBrandAttribute(bool $mapInternal = true, bool $withOptions = true): void
    {
        $this->brandChannelAttrId = Uuid::uuid7()->toString();
        DB::table('channel_attributes')->insert([
            'id' => $this->brandChannelAttrId,
            'channel_category_id' => $this->channelCategoryId,
            'external_id' => 'brand',
            'name' => 'Brand',
            'is_required' => true,
            'is_multiple' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($withOptions) {
            foreach (['Nike', 'Adidas'] as $opt) {
                DB::table('channel_attribute_options')->insert([
                    'id' => Uuid::uuid7()->toString(),
                    'channel_attribute_id' => $this->brandChannelAttrId,
                    'external_id' => $opt,
                    'name' => $opt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if ($mapInternal) {
            DB::table('attribute_channel_mappings')->insert([
                'attribute_id' => $this->brand->id,
                'channel_attribute_id' => $this->brandChannelAttrId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function makeProduct(?string $brandValue = null, ?int $categoryId = null): Product
    {
        $product = Product::create([
            'name' => 'HP X',
            'category_id' => $categoryId ?? $this->category->id,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'weight' => 0.3,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => 'HPX-1', 'sell_price' => 1000, 'is_active' => true,
        ]);

        if ($brandValue !== null) {
            DB::table('variant_options')->insert([
                'variant_id' => $variant->id,
                'attribute_id' => $this->brand->id,
                'value' => $brandValue,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $product;
    }

    private function validate(Product $product)
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/listing/validate', ['product_id' => $product->id]);
    }

    public function test_ready_when_mapped_category_and_no_required_attributes(): void
    {
        $this->validate($this->makeProduct())
            ->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonCount(0, 'data.issues');
    }

    public function test_category_unmapped_blocks(): void
    {
        $other = Category::create(['name' => 'Sepatu']); // tidak dipetakan
        $this->validate($this->makeProduct(categoryId: $other->id))
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.code', 'category_unmapped');
    }

    public function test_required_attribute_missing_value(): void
    {
        $this->addRequiredBrandAttribute();
        // Produk tanpa nilai Brand.
        $this->validate($this->makeProduct(brandValue: null))
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.code', 'attribute_missing');
    }

    public function test_required_attribute_unmapped_to_internal(): void
    {
        $this->addRequiredBrandAttribute(mapInternal: false);
        $this->validate($this->makeProduct(brandValue: 'Nike'))
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.code', 'attribute_unmapped');
    }

    public function test_value_not_in_closed_options(): void
    {
        $this->addRequiredBrandAttribute();
        $this->validate($this->makeProduct(brandValue: 'Puma')) // bukan Nike/Adidas
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.code', 'value_unmapped');
    }

    public function test_ready_when_required_attribute_filled_and_mapped(): void
    {
        $this->addRequiredBrandAttribute();
        $this->validate($this->makeProduct(brandValue: 'Nike'))
            ->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonCount(0, 'data.issues');
    }
}
