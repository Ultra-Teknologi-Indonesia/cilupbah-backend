=== 1. CREATE LOCATION ===
{
    "status": "success",
    "message": "Lokasi berhasil dibuat.",
    "data": {
        "location_code": "GDG-01",
        "location_name": "Gudang Utama",
        "location_type": "WAREHOUSE",
        "address": "Jl. Raya 1",
        "updated_at": "2026-06-06T08:39:19.000000Z",
        "created_at": "2026-06-06T08:39:19.000000Z",
        "id": 1,
        "bins": [
            {
                "id": "019e9c16-2b4a-7293-af4a-1d3ad658c615",
                "location_id": 1,
                "floor_code": null,
                "row_code": null,
                "column_code": null,
                "bin_code": null,
                "bin_final_code": "DEFAULT",
                "max_qty": 0,
                "is_inbound": true,
                "created_at": "2026-06-06T08:39:19.000000Z",
                "updated_at": "2026-06-06T08:39:19.000000Z"
            }
        ]
    }
}
LOC_ID=1

=== 2. CREATE BIN ===
{
    "status": "success",
    "message": "Bin berhasil dibuat.",
    "data": {
        "location_id": 1,
        "floor_code": "L1",
        "row_code": "A",
        "column_code": "01",
        "bin_code": "B1",
        "bin_final_code": "L1-A-01-B1",
        "id": "019e9c16-3087-725d-9904-d6f163f2a62d",
        "updated_at": "2026-06-06T08:39:20.000000Z",
        "created_at": "2026-06-06T08:39:20.000000Z"
    }
}
BIN_ID=019e9c16-3087-725d-9904-d6f163f2a62d

=== 3. CREATE INBOUND (UUID PK) ===
{
    "status": "success",
    "message": "Draft Inbound berhasil dibuat",
    "data": {
        "location_id": 1,
        "type": "PURCHASE_ORDER",
        "expected_date": "2026-06-10T00:00:00.000000Z",
        "created_by": "admin",
        "transaction_number": "INB-SMCZOD1K",
        "status": "DRAFT",
        "id": "019e9c16-314c-72c7-901f-205e7cc0bab7",
        "updated_at": "2026-06-06T08:39:20.000000Z",
        "created_at": "2026-06-06T08:39:20.000000Z",
        "items": [
            {
                "id": "019e9c16-3150-71cc-9719-85df9f276640",
                "inbound_id": "019e9c16-314c-72c7-901f-205e7cc0bab7",
                "item_id": 1,
                "expected_qty": 100,
                "received_qty": 0,
                "condition": "GOOD",
                "created_at": "2026-06-06T08:39:20.000000Z",
                "updated_at": "2026-06-06T08:39:20.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null
            }
        ]
    }
}
INB_ID=019e9c16-314c-72c7-901f-205e7cc0bab7
ITEM_ID=019e9c16-3150-71cc-9719-85df9f276640

=== 4. GET INBOUND BY UUID ===
{
    "status": "success",
    "message": "Detail Inbound berhasil diambil",
    "data": {
        "id": "019e9c16-314c-72c7-901f-205e7cc0bab7",
        "location_id": 1,
        "transaction_number": "INB-SMCZOD1K",
        "reference_number": null,
        "type": "PURCHASE_ORDER",
        "status": "DRAFT",
        "expected_date": "2026-06-10T00:00:00.000000Z",
        "created_by": "admin",
        "created_at": "2026-06-06T08:39:20.000000Z",
        "updated_at": "2026-06-06T08:39:20.000000Z",
        "source_type": null,
        "source_id": null,
        "location": {
            "id": 1,
            "location_code": "GDG-01",
            "location_name": "Gudang Utama",
            "location_type": "WAREHOUSE",
            "address": "Jl. Raya 1",
            "area": null,
            "city": null,
            "province": null,
            "post_code": null,
            "is_warehouse": true,
            "is_multi_origin": false,
            "default_warehouse_user": null,
            "is_active": true,
            "is_fbl": null,
            "is_tcb": null,
            "is_fbs": null,
            "created_at": "2026-06-06T08:39:19.000000Z",
            "updated_at": "2026-06-06T08:39:19.000000Z"
        },
        "items": [
            {
                "id": "019e9c16-3150-71cc-9719-85df9f276640",
                "inbound_id": "019e9c16-314c-72c7-901f-205e7cc0bab7",
                "item_id": 1,
                "expected_qty": 100,
                "received_qty": 0,
                "condition": "GOOD",
                "created_at": "2026-06-06T08:39:20.000000Z",
                "updated_at": "2026-06-06T08:39:20.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "receipts": [],
                "variant": {
                    "id": 1,
                    "sku": "KP-001-RED-M",
                    "product_id": 1
                }
            }
        ]
    }
}

=== 5. LIST INBOUNDS ===
{
    "status": "success",
    "message": "Daftar inbound berhasil diambil",
    "data": [
        {
            "id": "019e9c16-314c-72c7-901f-205e7cc0bab7",
            "location_id": 1,
            "transaction_number": "INB-SMCZOD1K",
            "reference_number": null,
            "type": "PURCHASE_ORDER",
            "status": "DRAFT",
            "expected_date": "2026-06-10T00:00:00.000000Z",
            "created_by": "admin",
            "created_at": "2026-06-06T08:39:20.000000Z",
            "updated_at": "2026-06-06T08:39:20.000000Z",
            "source_type": null,
            "source_id": null,
            "location": {
                "id": 1,
                "location_name": "Gudang Utama"
            },
            "items": [
                {
                    "id": "019e9c16-3150-71cc-9719-85df9f276640",
                    "inbound_id": "019e9c16-314c-72c7-901f-205e7cc0bab7",
                    "item_id": 1,
                    "expected_qty": 100,
                    "received_qty": 0,
                    "condition": "GOOD",
                    "created_at": "2026-06-06T08:39:20.000000Z",
                    "updated_at": "2026-06-06T08:39:20.000000Z",
                    "putaway_qty": 0,
                    "discrepancy_qty": 0,
                    "discrepancy_note": null,
                    "variant": {
                        "id": 1,
                        "sku": "KP-001-RED-M",
                        "product_id": 1
                    }
                }
            ]
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 1,
        "per_page": 10,
        "total": 1
    }
}

=== 6. RECEIVE ITEMS ===
{
    "status": "success",
    "message": "Penerimaan Inbound berhasil diproses",
    "data": {
        "id": "019e9c16-314c-72c7-901f-205e7cc0bab7",
        "location_id": 1,
        "transaction_number": "INB-SMCZOD1K",
        "reference_number": null,
        "type": "PURCHASE_ORDER",
        "status": "RECEIVED",
        "expected_date": "2026-06-10T00:00:00.000000Z",
        "created_by": "admin",
        "created_at": "2026-06-06T08:39:20.000000Z",
        "updated_at": "2026-06-06T08:39:21.000000Z",
        "source_type": null,
        "source_id": null,
        "location": {
            "id": 1,
            "location_code": "GDG-01",
            "location_name": "Gudang Utama",
            "location_type": "WAREHOUSE",
            "address": "Jl. Raya 1",
            "area": null,
            "city": null,
            "province": null,
            "post_code": null,
            "is_warehouse": true,
            "is_multi_origin": false,
            "default_warehouse_user": null,
            "is_active": true,
            "is_fbl": null,
            "is_tcb": null,
            "is_fbs": null,
            "created_at": "2026-06-06T08:39:19.000000Z",
            "updated_at": "2026-06-06T08:39:19.000000Z"
        },
        "items": [
            {
                "id": "019e9c16-3150-71cc-9719-85df9f276640",
                "inbound_id": "019e9c16-314c-72c7-901f-205e7cc0bab7",
                "item_id": 1,
                "expected_qty": 100,
                "received_qty": 100,
                "condition": "GOOD",
                "created_at": "2026-06-06T08:39:20.000000Z",
                "updated_at": "2026-06-06T08:39:21.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "receipts": [
                    {
                        "id": 1,
                        "inbound_item_id": "019e9c16-3150-71cc-9719-85df9f276640",
                        "qty": 100,
                        "bin_id": "019e9c16-2b4a-7293-af4a-1d3ad658c615",
                        "batch_no": null,
                        "serial_no": null,
                        "received_by": "staff1",
                        "received_date": "2026-06-06T08:39:21.000000Z",
                        "created_at": "2026-06-06T08:39:21.000000Z",
                        "updated_at": "2026-06-06T08:39:21.000000Z",
                        "condition": "GOOD",
                        "bin": {
                            "id": "019e9c16-2b4a-7293-af4a-1d3ad658c615",
                            "location_id": 1,
                            "floor_code": null,
                            "row_code": null,
                            "column_code": null,
                            "bin_code": null,
                            "bin_final_code": "DEFAULT",
                            "max_qty": 0,
                            "is_inbound": true,
                            "created_at": "2026-06-06T08:39:19.000000Z",
                            "updated_at": "2026-06-06T08:39:19.000000Z"
                        }
                    }
                ],
                "variant": {
                    "id": 1,
                    "sku": "KP-001-RED-M",
                    "product_id": 1
                }
            }
        ]
    }
}

=== 7. SCAN QR (lookup item by UUID) ===
{
    "status": "success",
    "message": "Item ditemukan",
    "data": {
        "id": "019e9c16-3150-71cc-9719-85df9f276640",
        "inbound_id": "019e9c16-314c-72c7-901f-205e7cc0bab7",
        "item_id": 1,
        "expected_qty": 100,
        "received_qty": 100,
        "condition": "GOOD",
        "created_at": "2026-06-06T08:39:20.000000Z",
        "updated_at": "2026-06-06T08:39:21.000000Z",
        "putaway_qty": 0,
        "discrepancy_qty": 0,
        "discrepancy_note": null,
        "inbound": {
            "id": "019e9c16-314c-72c7-901f-205e7cc0bab7",
            "location_id": 1,
            "transaction_number": "INB-SMCZOD1K",
            "reference_number": null,
            "type": "PURCHASE_ORDER",
            "status": "RECEIVED",
            "expected_date": "2026-06-10T00:00:00.000000Z",
            "created_by": "admin",
            "created_at": "2026-06-06T08:39:20.000000Z",
            "updated_at": "2026-06-06T08:39:21.000000Z",
            "source_type": null,
            "source_id": null,
            "location": {
                "id": 1,
                "location_code": "GDG-01",
                "location_name": "Gudang Utama",
                "location_type": "WAREHOUSE",
                "address": "Jl. Raya 1",
                "area": null,
                "city": null,
                "province": null,
                "post_code": null,
                "is_warehouse": true,
                "is_multi_origin": false,
                "default_warehouse_user": null,
                "is_active": true,
                "is_fbl": null,
                "is_tcb": null,
                "is_fbs": null,
                "created_at": "2026-06-06T08:39:19.000000Z",
                "updated_at": "2026-06-06T08:39:19.000000Z"
            }
        },
        "variant": {
            "id": 1,
            "sku": "KP-001-RED-M",
            "product_id": 1
        }
    }
}

=== 8. SCAN PUTAWAY ===
{
    "status": "success",
    "message": "Putaway berhasil, stock diperbarui",
    "data": {
        "id": "019e9c16-3150-71cc-9719-85df9f276640",
        "inbound_id": "019e9c16-314c-72c7-901f-205e7cc0bab7",
        "item_id": 1,
        "expected_qty": 100,
        "received_qty": 100,
        "condition": "GOOD",
        "created_at": "2026-06-06T08:39:20.000000Z",
        "updated_at": "2026-06-06T08:39:21.000000Z",
        "putaway_qty": 100,
        "discrepancy_qty": 0,
        "discrepancy_note": null,
        "inbound": {
            "id": "019e9c16-314c-72c7-901f-205e7cc0bab7",
            "location_id": 1,
            "transaction_number": "INB-SMCZOD1K",
            "reference_number": null,
            "type": "PURCHASE_ORDER",
            "status": "COMPLETED",
            "expected_date": "2026-06-10T00:00:00.000000Z",
            "created_by": "admin",
            "created_at": "2026-06-06T08:39:20.000000Z",
            "updated_at": "2026-06-06T08:39:21.000000Z",
            "source_type": null,
            "source_id": null
        },
        "variant": {
            "id": 1,
            "sku": "KP-001-RED-M",
            "product_id": 1
        }
    }
}

=== 9. CHECK INBOUND STATUS (should be COMPLETED) ===
{
    "status": "success",
    "message": "Detail Inbound berhasil diambil",
    "data": {
        "id": "019e9c16-314c-72c7-901f-205e7cc0bab7",
        "location_id": 1,
        "transaction_number": "INB-SMCZOD1K",
        "reference_number": null,
        "type": "PURCHASE_ORDER",
        "status": "COMPLETED",
        "expected_date": "2026-06-10T00:00:00.000000Z",
        "created_by": "admin",
        "created_at": "2026-06-06T08:39:20.000000Z",
        "updated_at": "2026-06-06T08:39:21.000000Z",
        "source_type": null,
        "source_id": null,
        "location": {
            "id": 1,
            "location_code": "GDG-01",
            "location_name": "Gudang Utama",
            "location_type": "WAREHOUSE",
            "address": "Jl. Raya 1",
            "area": null,
            "city": null,
            "province": null,
            "post_code": null,
            "is_warehouse": true,
            "is_multi_origin": false,
            "default_warehouse_user": null,
            "is_active": true,
            "is_fbl": null,
            "is_tcb": null,
            "is_fbs": null,
            "created_at": "2026-06-06T08:39:19.000000Z",
            "updated_at": "2026-06-06T08:39:19.000000Z"
        },
        "items": [
            {
                "id": "019e9c16-3150-71cc-9719-85df9f276640",
                "inbound_id": "019e9c16-314c-72c7-901f-205e7cc0bab7",
                "item_id": 1,
                "expected_qty": 100,
                "received_qty": 100,
                "condition": "GOOD",
                "created_at": "2026-06-06T08:39:20.000000Z",
                "updated_at": "2026-06-06T08:39:21.000000Z",
                "putaway_qty": 100,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "receipts": [
                    {
                        "id": 1,
                        "inbound_item_id": "019e9c16-3150-71cc-9719-85df9f276640",
                        "qty": 100,
                        "bin_id": "019e9c16-2b4a-7293-af4a-1d3ad658c615",
                        "batch_no": null,
                        "serial_no": null,
                        "received_by": "staff1",
                        "received_date": "2026-06-06T08:39:21.000000Z",
                        "created_at": "2026-06-06T08:39:21.000000Z",
                        "updated_at": "2026-06-06T08:39:21.000000Z",
                        "condition": "GOOD",
                        "bin": {
                            "id": "019e9c16-2b4a-7293-af4a-1d3ad658c615",
                            "location_id": 1,
                            "floor_code": null,
                            "row_code": null,
                            "column_code": null,
                            "bin_code": null,
                            "bin_final_code": "DEFAULT",
                            "max_qty": 0,
                            "is_inbound": true,
                            "created_at": "2026-06-06T08:39:19.000000Z",
                            "updated_at": "2026-06-06T08:39:19.000000Z"
                        }
                    }
                ],
                "variant": {
                    "id": 1,
                    "sku": "KP-001-RED-M",
                    "product_id": 1
                }
            }
        ]
    }
}

=== 10. ASSIGN WORKER ===
INB2_ID=019e9c16-3483-7027-a664-09277cb26041
{
    "status": "success",
    "message": "Inbound berhasil di-assign",
    "data": {
        "inbound_id": "019e9c16-3483-7027-a664-09277cb26041",
        "assigned_to": 3,
        "assigned_by": 2,
        "status": "PENDING",
        "notes": "Prioritas tinggi",
        "updated_at": "2026-06-06T08:39:21.000000Z",
        "created_at": "2026-06-06T08:39:21.000000Z",
        "id": 1,
        "worker": {
            "id": 3,
            "name": "Worker Budi",
            "email": "budi@warehouse.com"
        },
        "assigner": {
            "id": 2,
            "name": "Admin Gudang",
            "email": "admin@cilupbah.com"
        }
    }
}

=== 11. GET ASSIGNMENTS ===
{
    "status": "success",
    "message": "Daftar assignment berhasil diambil",
    "data": [
        {
            "id": 1,
            "inbound_id": "019e9c16-3483-7027-a664-09277cb26041",
            "assigned_to": 3,
            "assigned_by": 2,
            "status": "PENDING",
            "notes": "Prioritas tinggi",
            "started_at": null,
            "completed_at": null,
            "created_at": "2026-06-06T08:39:21.000000Z",
            "updated_at": "2026-06-06T08:39:21.000000Z",
            "worker": {
                "id": 3,
                "name": "Worker Budi",
                "email": "budi@warehouse.com"
            },
            "assigner": {
                "id": 2,
                "name": "Admin Gudang",
                "email": "admin@cilupbah.com"
            }
        }
    ]
}

=== 12. PENDING PUTAWAY ===
{
    "status": "success",
    "message": "Items pending putaway",
    "data": []
}

=== 13. CANCEL INBOUND ===
{
    "status": "success",
    "message": "Inbound berhasil dibatalkan",
    "data": {
        "id": "019e9c16-3483-7027-a664-09277cb26041",
        "location_id": 1,
        "transaction_number": "INB-VQJRETY8",
        "reference_number": null,
        "type": "PURCHASE_ORDER",
        "status": "CANCELLED",
        "expected_date": "2026-06-10T00:00:00.000000Z",
        "created_by": "admin",
        "created_at": "2026-06-06T08:39:21.000000Z",
        "updated_at": "2026-06-06T08:39:21.000000Z",
        "source_type": null,
        "source_id": null,
        "location": {
            "id": 1,
            "location_code": "GDG-01",
            "location_name": "Gudang Utama",
            "location_type": "WAREHOUSE",
            "address": "Jl. Raya 1",
            "area": null,
            "city": null,
            "province": null,
            "post_code": null,
            "is_warehouse": true,
            "is_multi_origin": false,
            "default_warehouse_user": null,
            "is_active": true,
            "is_fbl": null,
            "is_tcb": null,
            "is_fbs": null,
            "created_at": "2026-06-06T08:39:19.000000Z",
            "updated_at": "2026-06-06T08:39:19.000000Z"
        },
        "items": [
            {
                "id": "019e9c16-3484-7372-aa72-18944e0c1cd8",
                "inbound_id": "019e9c16-3483-7027-a664-09277cb26041",
                "item_id": 1,
                "expected_qty": 50,
                "received_qty": 0,
                "condition": "GOOD",
                "created_at": "2026-06-06T08:39:21.000000Z",
                "updated_at": "2026-06-06T08:39:21.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "receipts": [],
                "variant": {
                    "id": 1,
                    "sku": "KP-001-RED-M",
                    "product_id": 1
                }
            }
        ]
    }
}

=== 14. CHECK INVENTORY ===
{
    "status": "success",
    "message": "Daftar stok berhasil diambil",
    "data": [
        {
            "id": 1,
            "item_id": 1,
            "location_id": 1,
            "bin_id": "019e9c16-2b4a-7293-af4a-1d3ad658c615",
            "batch_no": "",
            "serial_no": "",
            "expired_date": null,
            "on_hand": 0,
            "on_order": 0,
            "reserved": 0,
            "available": 0,
            "created_at": "2026-06-06T08:39:21.000000Z",
            "updated_at": "2026-06-06T08:39:21.000000Z",
            "product": {
                "id": 1,
                "sku": "KP-001-RED-M",
                "product_id": 1
            },
            "location": {
                "id": 1,
                "location_name": "Gudang Utama"
            },
            "bin": {
                "id": "019e9c16-2b4a-7293-af4a-1d3ad658c615",
                "bin_final_code": "DEFAULT"
            }
        },
        {
            "id": 2,
            "item_id": 1,
            "location_id": 1,
            "bin_id": "019e9c16-3087-725d-9904-d6f163f2a62d",
            "batch_no": "",
            "serial_no": "",
            "expired_date": null,
            "on_hand": 100,
            "on_order": 0,
            "reserved": 0,
            "available": 100,
            "created_at": "2026-06-06T08:39:21.000000Z",
            "updated_at": "2026-06-06T08:39:21.000000Z",
            "product": {
                "id": 1,
                "sku": "KP-001-RED-M",
                "product_id": 1
            },
            "location": {
                "id": 1,
                "location_name": "Gudang Utama"
            },
            "bin": {
                "id": "019e9c16-3087-725d-9904-d6f163f2a62d",
                "bin_final_code": "L1-A-01-B1"
            }
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 1,
        "per_page": 10,
        "total": 2
    }
}

=== DONE ===
