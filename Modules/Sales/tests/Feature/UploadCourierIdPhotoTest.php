<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class UploadCourierIdPhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private SalesOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->user = $this->createPrivilegedUser();

        $location = Location::firstOrCreate(
            ['location_code' => 'WH-TEST'],
            ['id' => (string) Str::uuid(), 'location_name' => 'Gudang Utama', 'location_type' => 'WAREHOUSE']
        );

        $this->order = SalesOrder::create([
            'salesorder_no' => 'SO-TEST-001',
            'order_no' => 'SP-2608247AJPKKV4',
            'location_id' => $location->id,
            'source' => 'manual',
            'status' => 'packed',
            'payment_status' => 'UNPAID',
            'total_amount' => 100000,
            'recipient_name' => 'John Doe',
            'recipient_phone' => '081234567890',
            'recipient_address' => 'Jl. Test No. 1',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_upload_courier_id_photo_successfully(): void
    {
        $file = UploadedFile::fake()->image('courier_id_card.jpg', 600, 400)->size(500);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/sales/{$this->order->id}/courier-pickup/photo", [
                'photo' => $file,
            ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Foto identitas kurir berhasil diunggah');

        $this->order->refresh();
        $this->assertCount(1, $this->order->getMedia('courier_id'));
        $this->assertNotNull($this->order->courier_pickup_recorded_at);
    }

    public function test_upload_courier_id_photo_fails_when_photo_is_missing(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/sales/{$this->order->id}/courier-pickup/photo", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['photo']);
    }

    public function test_delete_courier_id_photo(): void
    {
        $file = UploadedFile::fake()->image('courier_id_card.jpg', 600, 400)->size(500);
        $this->order->addMedia($file)->toMediaCollection('courier_id');

        $this->assertCount(1, $this->order->getMedia('courier_id'));

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/sales/{$this->order->id}/courier-pickup/photo");

        $response->assertOk();
        $this->order->refresh();
        $this->assertCount(0, $this->order->getMedia('courier_id'));
    }
}
