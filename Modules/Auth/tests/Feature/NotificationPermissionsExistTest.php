<?php

namespace Modules\Auth\Tests\Feature;

use Modules\Auth\Support\PermissionCatalog;
use Modules\Inbound\Services\InboundService;
use Modules\Inventory\Services\PutawayService;
use Modules\Inventory\Services\StockReplenishmentService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesReturnService;
use Tests\TestCase;

class NotificationPermissionsExistTest extends TestCase
{
    public function test_notification_target_permissions_exist_in_rbac(): void
    {
        $catalog = PermissionCatalog::allPermissionNames();

        $targets = [
            SalesOrderService::NOTIF_ORDER_PERMISSION,       
            SalesReturnService::NOTIF_PERMISSION,            
            StockReplenishmentService::NOTIF_PERMISSION,     
            PutawayService::NOTIF_PERMISSION,                
            InboundService::NOTIF_PENEMPATAN,                
            'view-integrasi-channel',                        
            'view-stok-opname',                              
            'view-user',                                     
            'view-laporan-stok-minus',                       
        ];

        foreach ($targets as $permission) {
            $this->assertContains(
                $permission,
                $catalog,
                "Permission notifikasi '{$permission}' tidak ada di RBAC catalog (config/rbac.php) — "
                . 'notifikasi akan gagal diam-diam (tidak ada penerima).'
            );
        }
    }

    public function test_no_manage_prefixed_permission_targets_remain(): void
    {

        $constants = [
            SalesOrderService::NOTIF_ORDER_PERMISSION,
            SalesReturnService::NOTIF_PERMISSION,
            StockReplenishmentService::NOTIF_PERMISSION,
            PutawayService::NOTIF_PERMISSION,
            InboundService::NOTIF_PENEMPATAN,
        ];

        foreach ($constants as $permission) {
            $this->assertStringStartsNotWith('manage-', $permission);
        }
    }
}
