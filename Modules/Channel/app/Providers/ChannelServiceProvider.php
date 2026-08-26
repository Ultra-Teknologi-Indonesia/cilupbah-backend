<?php

namespace Modules\Channel\Providers;

use Modules\Channel\Console\Commands\AlertChannelReauth;
use Modules\Channel\Console\Commands\AuditChannelProductActivity;
use Modules\Channel\Console\Commands\AuditChannelSkuCoverage;
use Modules\Channel\Console\Commands\BackfillDownloadHistory;
use Modules\Channel\Console\Commands\BackfillShopeeShopNames;
use Modules\Channel\Console\Commands\BackfillTikTokCommercePlatform;
use Modules\Channel\Console\Commands\EvaluateOrderSyncHealth;
use Modules\Channel\Console\Commands\MonitorChannelSkuHealth;
use Modules\Channel\Console\Commands\MonitorDownloadHealth;
use Modules\Channel\Console\Commands\MonitorShadowPullHealth;
use Modules\Channel\Console\Commands\PullChannelShop;
use Modules\Channel\Console\Commands\PullLiveOrdersCommand;
use Modules\Channel\Console\Commands\PullShadowOrdersCommand;
use Modules\Channel\Console\Commands\ReportMissingChannelSku;
use Modules\Channel\Console\Commands\ReapStaleDownloadTransactions;
use Modules\Channel\Console\Commands\RepairStaleSyncErrors;
use Modules\Channel\Console\Commands\ShadowOffCommand;
use Modules\Channel\Console\Commands\ShadowPromoteCommand;
use Modules\Channel\Console\Commands\ShadowPurgeCommand;
use Modules\Channel\Console\Commands\ShadowReconcileCommand;
use Modules\Channel\Console\Commands\StockHandoverCommand;
use Modules\Channel\Console\Commands\StockReconcileCommand;
use Modules\Channel\Console\Commands\StockRollbackCommand;
use Modules\Channel\Console\Commands\SyncTikTokAttributes;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Observers\ChannelShopObserver;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class ChannelServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Channel';

    protected string $nameLower = 'channel';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected array $commands = [
        AlertChannelReauth::class,
        AuditChannelProductActivity::class,
        AuditChannelSkuCoverage::class,
        BackfillDownloadHistory::class,
        BackfillShopeeShopNames::class,
        BackfillTikTokCommercePlatform::class,
        EvaluateOrderSyncHealth::class,
        MonitorChannelSkuHealth::class,
        MonitorDownloadHealth::class,
        PullChannelShop::class,
        ReapStaleDownloadTransactions::class,
        ReportMissingChannelSku::class,
        RepairStaleSyncErrors::class,
        SyncTikTokAttributes::class,

        MonitorShadowPullHealth::class,
        PullShadowOrdersCommand::class,
        PullLiveOrdersCommand::class,
        ShadowOffCommand::class,
        ShadowPromoteCommand::class,
        ShadowPurgeCommand::class,
        ShadowReconcileCommand::class,

        StockHandoverCommand::class,
        StockReconcileCommand::class,
        StockRollbackCommand::class,
        \Modules\Channel\Console\Commands\ReplayFailedWebhooksCommand::class,
        \Modules\Channel\Console\Commands\CleanOrderCutoverCommand::class,
        \Modules\Channel\Console\Commands\MonitorLiveQueueCommand::class,
    ];

    public function boot(): void
    {
        parent::boot();
        ChannelShop::observe(ChannelShopObserver::class);
    }

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('channel:monitor-sku-health')
            ->dailyAt('06:30')
            ->withoutOverlapping();
    }
}
