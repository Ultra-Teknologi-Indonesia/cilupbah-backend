<?php

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\Channel;
use Modules\Channel\Services\TikTokClient;
use Modules\Product\Models\ChannelCategory;
use Modules\Product\Repositories\ChannelAttributeOptionRepository;
use Modules\Product\Repositories\ChannelAttributeRepository;
use Ramsey\Uuid\Uuid;

class ChannelAttributeService
{
    protected ChannelAttributeRepository $attributeRepository;
    protected ChannelAttributeOptionRepository $optionRepository;

    public function __construct(
        ChannelAttributeRepository $attributeRepository,
        ChannelAttributeOptionRepository $optionRepository
    ) {
        $this->attributeRepository = $attributeRepository;
        $this->optionRepository = $optionRepository;
    }

    public function syncAttributesFromChannel(string $channelId, string $channelCategoryId): void
    {
        $channel = Channel::find($channelId);
        $category = ChannelCategory::find($channelCategoryId);

        if (!$channel || !$category) {
            throw new \Exception("Channel or Category not found");
        }

        if ($channel->code === 'tiktok') {
            $this->syncTikTokAttributes($channel, $category);
        } else {
            throw new \Exception("Attribute sync for channel {$channel->code} is not supported yet.");
        }
    }

    protected function syncTikTokAttributes(Channel $channel, ChannelCategory $category): void
    {
        $shop = \Modules\Channel\Models\ChannelShop::where('channel_id', $channel->id)->first();
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No active TikTok shop found with access token to sync attributes");
        }

        $client = app(TikTokClient::class);
        $queries = ['category_version' => 'v2'];

        try {
            $res = $client->request(
                'GET', 
                "/product/202309/categories/{$category->external_id}/attributes", 
                $queries, 
                [], 
                $shop->access_token
            );

            if (!isset($res['data']['attributes'])) {
                return;
            }

            $attributes = $res['data']['attributes'];

            foreach ($attributes as $attr) {
                $channelAttr = \Modules\Product\Models\ChannelAttribute::updateOrCreate(
                    [
                        'channel_category_id' => $category->id,
                        'external_id' => $attr['id'],
                    ],
                    [
                        'name' => $attr['name'],
                        'is_required' => $attr['is_required'] ?? false,
                        'is_multiple' => $attr['is_multiple_selection'] ?? false,
                    ]
                );

                if (!empty($attr['values'])) {
                    $optionsData = [];
                    foreach ($attr['values'] as $val) {
                        $optionsData[] = [
                            'id' => Uuid::uuid7()->toString(),
                            'channel_attribute_id' => $channelAttr->id,
                            'external_id' => $val['id'],
                            'name' => $val['name'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    if (count($optionsData) > 0) {
                        foreach (array_chunk($optionsData, 500) as $chunk) {
                            $this->optionRepository->upsert($chunk, ['channel_attribute_id', 'external_id'], ['name', 'updated_at']);
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error("Failed to sync TikTok attributes: " . $e->getMessage());
            throw new \Exception("Failed to sync TikTok attributes: " . $e->getMessage());
        }
    }

    public function getPaginated(string $channelCategoryId, int $perPage = 10): LengthAwarePaginator
    {
        return $this->attributeRepository->getPaginated($channelCategoryId, $perPage);
    }

    public function getAllAttributes(): LengthAwarePaginator
    {
        return $this->attributeRepository->getAllPaginated();
    }
}
