import os
import re

files_to_check = [
    "Modules/Bantuan/app/Services/FieldDescriptionResolver.php",
    "Modules/Bantuan/config/filter-enums.php",
    "Modules/Channel/tests/Feature/LazadaOrderPullTest.php",
    "Modules/Channel/tests/Feature/TikTokOrderOpsTest.php",
    "Modules/Inbound/database/seeders/InboundDatabaseSeeder.php",
    "Modules/Inventory/app/Repositories/StockReplenishmentRepository.php",
    "Modules/Inventory/tests/Feature/AdjustmentNegativeStockTest.php",
    "Modules/Inventory/tests/Feature/BinTransferNegativeStockTest.php",
    "Modules/Inventory/tests/Feature/BinTransferTest.php",
    "Modules/Inventory/tests/Feature/InventoryChannelStockSyncTest.php",
    "Modules/Inventory/tests/Feature/ScanCorrectionTest.php",
    "Modules/Outbound/app/Jobs/ProcessPicklistCompleteJob.php",
    "Modules/Outbound/app/Services/OutboundAdHocPickService.php",
    "Modules/Outbound/app/Services/OutboundFulfillmentService.php",
    "Modules/Outbound/app/Services/PicklistService.php",
    "Modules/Outbound/database/seeders/MultiRackPicklistSeeder.php",
    "Modules/Outbound/database/seeders/PicklistSeeder.php",
    "Modules/Outbound/tests/Feature/BundleOutboundExplosionTest.php",
    "Modules/Outbound/tests/Feature/PickingNegativeStockTest.php",
    "Modules/Outbound/tests/Feature/PicklistFailAndSplitTest.php",
    "Modules/Outbound/tests/Feature/RevertStageTest.php",
    "Modules/Sales/app/Console/Commands/BackfillStatusHistory.php",
    "Modules/Sales/app/Console/Commands/RelocateOrdersToKecil.php",
    "Modules/Sales/app/Enums/SalesOrderStatus.php",
    "Modules/Sales/app/Exports/SalesOrdersExport.php",
    "Modules/Sales/app/Observers/SalesOrderAuditObserver.php",
    "Modules/Sales/app/Repositories/SalesOrderRepository.php",
    "Modules/Sales/app/Services/SalesOrderManualService.php",
    "Modules/Sales/app/Services/SalesOrderService.php",
    "Modules/Sales/tests/Feature/CancelRequestFlowTest.php",
    "Modules/Sales/tests/Feature/PesananSemuaVisibilityAndStatusFilterTest.php",
    "Modules/Warehouse/tests/Feature/LocationApiTest.php",
    "Modules/Warehouse/tests/Feature/LocationBinSearchSkuTest.php",
    "Modules/Warehouse/tests/Feature/WarehouseBusinessFlowTest.php",
    "Modules/Warehouse/tests/Unit/LocationServiceTest.php",
    "Modules/Webhook/tests/Feature/WebhookHardeningTest.php",
    "tests/Feature/Order/OrderLifecycleTest.php"
]

for file in files_to_check:
    filepath = os.path.join(".", file)
    if not os.path.exists(filepath):
        continue
        
    with open(filepath, 'r') as f:
        lines = f.readlines()
        
    for i, line in enumerate(lines):
        if "reserved" in line.lower():
            print(f"{file}:{i+1}: {line.strip()}")

