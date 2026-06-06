# E2E Inbound Flow — API Responses

Generated: 2026-06-06 13:52:46

## Step 1: Create Inbound (DRAFT)

```
POST http://localhost:8000/api/v1/inbounds
```

**Request Body:**
```json
{
  "location_id": 32,
  "type": "PURCHASE_ORDER",
  "expected_date": "2026-06-10",
  "created_by": "admin",
  "items": [
    { "item_id": 35, "expected_qty": 20 },
    { "item_id": 36, "expected_qty": 10 }
  ]
}
```

**Response:** `201`
```json
{
    "status": "success",
    "message": "Draft Inbound berhasil dibuat",
    "data": {
        "location_id": 32,
        "type": "PURCHASE_ORDER",
        "expected_date": "2026-06-10T00:00:00.000000Z",
        "created_by": "admin",
        "transaction_number": "INB-4QHGPZIH",
        "status": "DRAFT",
        "updated_at": "2026-06-06T06:52:47.000000Z",
        "created_at": "2026-06-06T06:52:47.000000Z",
        "id": 4,
        "items": [
            {
                "id": 4,
                "inbound_id": 4,
                "item_id": 35,
                "expected_qty": 20,
                "received_qty": 0,
                "condition": "GOOD",
                "created_at": "2026-06-06T06:52:47.000000Z",
                "updated_at": "2026-06-06T06:52:47.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "qr_code": "24417499-a4bc-4705-ad12-9d027002e5e1"
            },
            {
                "id": 5,
                "inbound_id": 4,
                "item_id": 36,
                "expected_qty": 10,
                "received_qty": 0,
                "condition": "GOOD",
                "created_at": "2026-06-06T06:52:47.000000Z",
                "updated_at": "2026-06-06T06:52:47.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "qr_code": "a6be34ba-f602-42a6-88cb-8ddb60689b52"
            }
        ]
    }
}
```

---

## Step 2: Get Inbound Detail

```
GET http://localhost:8000/api/v1/inbounds/4
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Detail Inbound berhasil diambil",
    "data": {
        "id": 4,
        "location_id": 32,
        "transaction_number": "INB-4QHGPZIH",
        "reference_number": null,
        "type": "PURCHASE_ORDER",
        "status": "DRAFT",
        "expected_date": "2026-06-10T00:00:00.000000Z",
        "created_by": "admin",
        "created_at": "2026-06-06T06:52:47.000000Z",
        "updated_at": "2026-06-06T06:52:47.000000Z",
        "source_type": null,
        "source_id": null,
        "location": {
            "id": 32,
            "location_code": "WH-MAIN",
            "location_name": "Gudang Utama",
            "location_type": "warehouse",
            "address": "Jakarta",
            "area": null,
            "city": "Jakarta",
            "province": "DKI Jakarta",
            "post_code": null,
            "is_warehouse": true,
            "is_multi_origin": false,
            "default_warehouse_user": null,
            "is_active": true,
            "is_fbl": null,
            "is_tcb": null,
            "is_fbs": null,
            "created_at": "2026-06-05T10:42:14.000000Z",
            "updated_at": "2026-06-05T10:42:14.000000Z"
        },
        "items": [
            {
                "id": 4,
                "inbound_id": 4,
                "item_id": 35,
                "expected_qty": 20,
                "received_qty": 0,
                "condition": "GOOD",
                "created_at": "2026-06-06T06:52:47.000000Z",
                "updated_at": "2026-06-06T06:52:47.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "qr_code": "24417499-a4bc-4705-ad12-9d027002e5e1",
                "receipts": [],
                "variant": {
                    "id": 35,
                    "sku": "MOUSE-001-BLK",
                    "product_id": 36
                }
            },
            {
                "id": 5,
                "inbound_id": 4,
                "item_id": 36,
                "expected_qty": 10,
                "received_qty": 0,
                "condition": "GOOD",
                "created_at": "2026-06-06T06:52:47.000000Z",
                "updated_at": "2026-06-06T06:52:47.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "qr_code": "a6be34ba-f602-42a6-88cb-8ddb60689b52",
                "receipts": [],
                "variant": null
            }
        ]
    }
}
```

---

## Step 3: Admin Assign Inbound to Worker

```
POST http://localhost:8000/api/v1/inbounds/4/assign
```

**Request Body:**
```json
{"assigned_to": 2, "notes": "Handle laptop & mouse batch"}
```

**Response:** `201`
```json
{
    "status": "success",
    "message": "Inbound berhasil di-assign",
    "data": {
        "inbound_id": 4,
        "assigned_to": 2,
        "assigned_by": 1,
        "status": "PENDING",
        "notes": "Handle laptop & mouse batch",
        "updated_at": "2026-06-06T06:52:51.000000Z",
        "created_at": "2026-06-06T06:52:51.000000Z",
        "id": 1,
        "worker": {
            "id": 2,
            "name": "Pekerja Gudang",
            "email": "worker@e2e.com"
        },
        "assigner": {
            "id": 1,
            "name": "Admin Gudang",
            "email": "admin@e2e.com"
        }
    }
}
```

---

## Step 4: Get Assignments for Inbound

```
GET http://localhost:8000/api/v1/inbounds/4/assignments
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Daftar assignment berhasil diambil",
    "data": [
        {
            "id": 1,
            "inbound_id": 4,
            "assigned_to": 2,
            "assigned_by": 1,
            "status": "PENDING",
            "notes": "Handle laptop & mouse batch",
            "started_at": null,
            "completed_at": null,
            "created_at": "2026-06-06T06:52:51.000000Z",
            "updated_at": "2026-06-06T06:52:51.000000Z",
            "worker": {
                "id": 2,
                "name": "Pekerja Gudang",
                "email": "worker@e2e.com"
            },
            "assigner": {
                "id": 1,
                "name": "Admin Gudang",
                "email": "admin@e2e.com"
            }
        }
    ]
}
```

---

## Step 5: Worker — My Assignments (PENDING)

```
GET http://localhost:8000/api/v1/inbounds/my-assignments?status=PENDING
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Daftar assignment Anda",
    "data": [
        {
            "id": 1,
            "inbound_id": 4,
            "assigned_to": 2,
            "assigned_by": 1,
            "status": "PENDING",
            "notes": "Handle laptop & mouse batch",
            "started_at": null,
            "completed_at": null,
            "created_at": "2026-06-06T06:52:51.000000Z",
            "updated_at": "2026-06-06T06:52:51.000000Z",
            "inbound": {
                "id": 4,
                "location_id": 32,
                "transaction_number": "INB-4QHGPZIH",
                "reference_number": null,
                "type": "PURCHASE_ORDER",
                "status": "DRAFT",
                "expected_date": "2026-06-10T00:00:00.000000Z",
                "created_by": "admin",
                "created_at": "2026-06-06T06:52:47.000000Z",
                "updated_at": "2026-06-06T06:52:47.000000Z",
                "source_type": null,
                "source_id": null,
                "location": {
                    "id": 32,
                    "location_name": "Gudang Utama"
                },
                "items": [
                    {
                        "id": 4,
                        "inbound_id": 4,
                        "item_id": 35,
                        "expected_qty": 20,
                        "received_qty": 0,
                        "condition": "GOOD",
                        "created_at": "2026-06-06T06:52:47.000000Z",
                        "updated_at": "2026-06-06T06:52:47.000000Z",
                        "putaway_qty": 0,
                        "discrepancy_qty": 0,
                        "discrepancy_note": null,
                        "qr_code": "24417499-a4bc-4705-ad12-9d027002e5e1"
                    },
                    {
                        "id": 5,
                        "inbound_id": 4,
                        "item_id": 36,
                        "expected_qty": 10,
                        "received_qty": 0,
                        "condition": "GOOD",
                        "created_at": "2026-06-06T06:52:47.000000Z",
                        "updated_at": "2026-06-06T06:52:47.000000Z",
                        "putaway_qty": 0,
                        "discrepancy_qty": 0,
                        "discrepancy_note": null,
                        "qr_code": "a6be34ba-f602-42a6-88cb-8ddb60689b52"
                    }
                ]
            }
        }
    ]
}
```

---

## Step 6: Worker — Start Assignment

```
POST http://localhost:8000/api/v1/inbounds/assignments/1/start
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Assignment dimulai",
    "data": {
        "id": 1,
        "inbound_id": 4,
        "assigned_to": 2,
        "assigned_by": 1,
        "status": "IN_PROGRESS",
        "notes": "Handle laptop & mouse batch",
        "started_at": "2026-06-06T06:52:52.000000Z",
        "completed_at": null,
        "created_at": "2026-06-06T06:52:51.000000Z",
        "updated_at": "2026-06-06T06:52:52.000000Z",
        "inbound": {
            "id": 4,
            "location_id": 32,
            "transaction_number": "INB-4QHGPZIH",
            "reference_number": null,
            "type": "PURCHASE_ORDER",
            "status": "DRAFT",
            "expected_date": "2026-06-10T00:00:00.000000Z",
            "created_by": "admin",
            "created_at": "2026-06-06T06:52:47.000000Z",
            "updated_at": "2026-06-06T06:52:47.000000Z",
            "source_type": null,
            "source_id": null,
            "items": [
                {
                    "id": 4,
                    "inbound_id": 4,
                    "item_id": 35,
                    "expected_qty": 20,
                    "received_qty": 0,
                    "condition": "GOOD",
                    "created_at": "2026-06-06T06:52:47.000000Z",
                    "updated_at": "2026-06-06T06:52:47.000000Z",
                    "putaway_qty": 0,
                    "discrepancy_qty": 0,
                    "discrepancy_note": null,
                    "qr_code": "24417499-a4bc-4705-ad12-9d027002e5e1"
                },
                {
                    "id": 5,
                    "inbound_id": 4,
                    "item_id": 36,
                    "expected_qty": 10,
                    "received_qty": 0,
                    "condition": "GOOD",
                    "created_at": "2026-06-06T06:52:47.000000Z",
                    "updated_at": "2026-06-06T06:52:47.000000Z",
                    "putaway_qty": 0,
                    "discrepancy_qty": 0,
                    "discrepancy_note": null,
                    "qr_code": "a6be34ba-f602-42a6-88cb-8ddb60689b52"
                }
            ]
        },
        "worker": {
            "id": 2,
            "name": "Pekerja Gudang"
        }
    }
}
```

---

## Step 7: Worker — Scan QR Barang (Item 1)

```
GET http://localhost:8000/api/v1/inbounds/scan/24417499-a4bc-4705-ad12-9d027002e5e1
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Item ditemukan",
    "data": {
        "id": 4,
        "inbound_id": 4,
        "item_id": 35,
        "expected_qty": 20,
        "received_qty": 0,
        "condition": "GOOD",
        "created_at": "2026-06-06T06:52:47.000000Z",
        "updated_at": "2026-06-06T06:52:47.000000Z",
        "putaway_qty": 0,
        "discrepancy_qty": 0,
        "discrepancy_note": null,
        "qr_code": "24417499-a4bc-4705-ad12-9d027002e5e1",
        "inbound": {
            "id": 4,
            "location_id": 32,
            "transaction_number": "INB-4QHGPZIH",
            "reference_number": null,
            "type": "PURCHASE_ORDER",
            "status": "DRAFT",
            "expected_date": "2026-06-10T00:00:00.000000Z",
            "created_by": "admin",
            "created_at": "2026-06-06T06:52:47.000000Z",
            "updated_at": "2026-06-06T06:52:47.000000Z",
            "source_type": null,
            "source_id": null,
            "location": {
                "id": 32,
                "location_code": "WH-MAIN",
                "location_name": "Gudang Utama",
                "location_type": "warehouse",
                "address": "Jakarta",
                "area": null,
                "city": "Jakarta",
                "province": "DKI Jakarta",
                "post_code": null,
                "is_warehouse": true,
                "is_multi_origin": false,
                "default_warehouse_user": null,
                "is_active": true,
                "is_fbl": null,
                "is_tcb": null,
                "is_fbs": null,
                "created_at": "2026-06-05T10:42:14.000000Z",
                "updated_at": "2026-06-05T10:42:14.000000Z"
            }
        },
        "variant": {
            "id": 35,
            "sku": "MOUSE-001-BLK",
            "product_id": 36
        }
    }
}
```

---

## Step 8: Admin — Receive Items (Dock Receipt)

```
POST http://localhost:8000/api/v1/inbounds/4/receive
```

**Request Body:**
```json
{
  "received_by": "admin",
  "items": [
    {"inbound_item_id": 4, "qty": 20},
    {"inbound_item_id": 5, "qty": 10}
  ]
}
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Penerimaan Inbound berhasil diproses",
    "data": {
        "id": 4,
        "location_id": 32,
        "transaction_number": "INB-4QHGPZIH",
        "reference_number": null,
        "type": "PURCHASE_ORDER",
        "status": "RECEIVED",
        "expected_date": "2026-06-10T00:00:00.000000Z",
        "created_by": "admin",
        "created_at": "2026-06-06T06:52:47.000000Z",
        "updated_at": "2026-06-06T06:52:52.000000Z",
        "source_type": null,
        "source_id": null,
        "location": {
            "id": 32,
            "location_code": "WH-MAIN",
            "location_name": "Gudang Utama",
            "location_type": "warehouse",
            "address": "Jakarta",
            "area": null,
            "city": "Jakarta",
            "province": "DKI Jakarta",
            "post_code": null,
            "is_warehouse": true,
            "is_multi_origin": false,
            "default_warehouse_user": null,
            "is_active": true,
            "is_fbl": null,
            "is_tcb": null,
            "is_fbs": null,
            "created_at": "2026-06-05T10:42:14.000000Z",
            "updated_at": "2026-06-05T10:42:14.000000Z"
        },
        "items": [
            {
                "id": 4,
                "inbound_id": 4,
                "item_id": 35,
                "expected_qty": 20,
                "received_qty": 20,
                "condition": "GOOD",
                "created_at": "2026-06-06T06:52:47.000000Z",
                "updated_at": "2026-06-06T06:52:52.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "qr_code": "24417499-a4bc-4705-ad12-9d027002e5e1",
                "receipts": [
                    {
                        "id": 6,
                        "inbound_item_id": 4,
                        "qty": 20,
                        "bin_id": 1,
                        "batch_no": null,
                        "serial_no": null,
                        "received_by": "admin",
                        "received_date": "2026-06-06T06:52:52.000000Z",
                        "created_at": "2026-06-06T06:52:52.000000Z",
                        "updated_at": "2026-06-06T06:52:52.000000Z",
                        "condition": "GOOD",
                        "bin": {
                            "id": 1,
                            "location_id": 32,
                            "floor_code": "INB",
                            "row_code": "0",
                            "column_code": "0",
                            "bin_code": "0",
                            "bin_final_code": "INBOUND-DEFAULT",
                            "max_qty": 0,
                            "is_inbound": true,
                            "created_at": "2026-06-06T01:44:01.000000Z",
                            "updated_at": "2026-06-06T01:44:01.000000Z",
                            "qr_code": "7ff36d54-53eb-4bca-b2af-1de66a90b969"
                        }
                    }
                ],
                "variant": {
                    "id": 35,
                    "sku": "MOUSE-001-BLK",
                    "product_id": 36
                }
            },
            {
                "id": 5,
                "inbound_id": 4,
                "item_id": 36,
                "expected_qty": 10,
                "received_qty": 10,
                "condition": "GOOD",
                "created_at": "2026-06-06T06:52:47.000000Z",
                "updated_at": "2026-06-06T06:52:52.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "qr_code": "a6be34ba-f602-42a6-88cb-8ddb60689b52",
                "receipts": [
                    {
                        "id": 7,
                        "inbound_item_id": 5,
                        "qty": 10,
                        "bin_id": 1,
                        "batch_no": null,
                        "serial_no": null,
                        "received_by": "admin",
                        "received_date": "2026-06-06T06:52:52.000000Z",
                        "created_at": "2026-06-06T06:52:52.000000Z",
                        "updated_at": "2026-06-06T06:52:52.000000Z",
                        "condition": "GOOD",
                        "bin": {
                            "id": 1,
                            "location_id": 32,
                            "floor_code": "INB",
                            "row_code": "0",
                            "column_code": "0",
                            "bin_code": "0",
                            "bin_final_code": "INBOUND-DEFAULT",
                            "max_qty": 0,
                            "is_inbound": true,
                            "created_at": "2026-06-06T01:44:01.000000Z",
                            "updated_at": "2026-06-06T01:44:01.000000Z",
                            "qr_code": "7ff36d54-53eb-4bca-b2af-1de66a90b969"
                        }
                    }
                ],
                "variant": null
            }
        ]
    }
}
```

---

## Step 9: Admin — Close Receiving

```
POST http://localhost:8000/api/v1/inbounds/4/close-receiving
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Receiving ditutup, discrepancy tercatat",
    "data": {
        "id": 4,
        "location_id": 32,
        "transaction_number": "INB-4QHGPZIH",
        "reference_number": null,
        "type": "PURCHASE_ORDER",
        "status": "RECEIVED",
        "expected_date": "2026-06-10T00:00:00.000000Z",
        "created_by": "admin",
        "created_at": "2026-06-06T06:52:47.000000Z",
        "updated_at": "2026-06-06T06:52:52.000000Z",
        "source_type": null,
        "source_id": null,
        "location": {
            "id": 32,
            "location_code": "WH-MAIN",
            "location_name": "Gudang Utama",
            "location_type": "warehouse",
            "address": "Jakarta",
            "area": null,
            "city": "Jakarta",
            "province": "DKI Jakarta",
            "post_code": null,
            "is_warehouse": true,
            "is_multi_origin": false,
            "default_warehouse_user": null,
            "is_active": true,
            "is_fbl": null,
            "is_tcb": null,
            "is_fbs": null,
            "created_at": "2026-06-05T10:42:14.000000Z",
            "updated_at": "2026-06-05T10:42:14.000000Z"
        },
        "items": [
            {
                "id": 4,
                "inbound_id": 4,
                "item_id": 35,
                "expected_qty": 20,
                "received_qty": 20,
                "condition": "GOOD",
                "created_at": "2026-06-06T06:52:47.000000Z",
                "updated_at": "2026-06-06T06:52:52.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "qr_code": "24417499-a4bc-4705-ad12-9d027002e5e1",
                "receipts": [
                    {
                        "id": 6,
                        "inbound_item_id": 4,
                        "qty": 20,
                        "bin_id": 1,
                        "batch_no": null,
                        "serial_no": null,
                        "received_by": "admin",
                        "received_date": "2026-06-06T06:52:52.000000Z",
                        "created_at": "2026-06-06T06:52:52.000000Z",
                        "updated_at": "2026-06-06T06:52:52.000000Z",
                        "condition": "GOOD",
                        "bin": {
                            "id": 1,
                            "location_id": 32,
                            "floor_code": "INB",
                            "row_code": "0",
                            "column_code": "0",
                            "bin_code": "0",
                            "bin_final_code": "INBOUND-DEFAULT",
                            "max_qty": 0,
                            "is_inbound": true,
                            "created_at": "2026-06-06T01:44:01.000000Z",
                            "updated_at": "2026-06-06T01:44:01.000000Z",
                            "qr_code": "7ff36d54-53eb-4bca-b2af-1de66a90b969"
                        }
                    }
                ],
                "variant": {
                    "id": 35,
                    "sku": "MOUSE-001-BLK",
                    "product_id": 36
                }
            },
            {
                "id": 5,
                "inbound_id": 4,
                "item_id": 36,
                "expected_qty": 10,
                "received_qty": 10,
                "condition": "GOOD",
                "created_at": "2026-06-06T06:52:47.000000Z",
                "updated_at": "2026-06-06T06:52:52.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "qr_code": "a6be34ba-f602-42a6-88cb-8ddb60689b52",
                "receipts": [
                    {
                        "id": 7,
                        "inbound_item_id": 5,
                        "qty": 10,
                        "bin_id": 1,
                        "batch_no": null,
                        "serial_no": null,
                        "received_by": "admin",
                        "received_date": "2026-06-06T06:52:52.000000Z",
                        "created_at": "2026-06-06T06:52:52.000000Z",
                        "updated_at": "2026-06-06T06:52:52.000000Z",
                        "condition": "GOOD",
                        "bin": {
                            "id": 1,
                            "location_id": 32,
                            "floor_code": "INB",
                            "row_code": "0",
                            "column_code": "0",
                            "bin_code": "0",
                            "bin_final_code": "INBOUND-DEFAULT",
                            "max_qty": 0,
                            "is_inbound": true,
                            "created_at": "2026-06-06T01:44:01.000000Z",
                            "updated_at": "2026-06-06T01:44:01.000000Z",
                            "qr_code": "7ff36d54-53eb-4bca-b2af-1de66a90b969"
                        }
                    }
                ],
                "variant": null
            }
        ]
    }
}
```

---

## Step 10: Worker — Scan Putaway Item 1 (partial: 15 of 20 to Bin A-1-1-1)

```
POST http://localhost:8000/api/v1/inbounds/scan-putaway
```

**Request Body:**
```json
{"qr_code": "24417499-a4bc-4705-ad12-9d027002e5e1", "bin_qr_code": "abac22f6-db1a-4241-9028-6a7d78fcbded", "qty": 15}
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Putaway berhasil, stock diperbarui",
    "data": {
        "id": 4,
        "inbound_id": 4,
        "item_id": 35,
        "expected_qty": 20,
        "received_qty": 20,
        "condition": "GOOD",
        "created_at": "2026-06-06T06:52:47.000000Z",
        "updated_at": "2026-06-06T06:52:52.000000Z",
        "putaway_qty": 15,
        "discrepancy_qty": 0,
        "discrepancy_note": null,
        "qr_code": "24417499-a4bc-4705-ad12-9d027002e5e1",
        "inbound": {
            "id": 4,
            "location_id": 32,
            "transaction_number": "INB-4QHGPZIH",
            "reference_number": null,
            "type": "PURCHASE_ORDER",
            "status": "PUTAWAY_IN_PROGRESS",
            "expected_date": "2026-06-10T00:00:00.000000Z",
            "created_by": "admin",
            "created_at": "2026-06-06T06:52:47.000000Z",
            "updated_at": "2026-06-06T06:52:52.000000Z",
            "source_type": null,
            "source_id": null
        },
        "variant": {
            "id": 35,
            "sku": "MOUSE-001-BLK",
            "product_id": 36
        }
    }
}
```

---

## Step 11: Worker — Scan Putaway Item 1 (remaining 5 to Bin A-1-2-1)

```
POST http://localhost:8000/api/v1/inbounds/scan-putaway
```

**Request Body:**
```json
{"qr_code": "24417499-a4bc-4705-ad12-9d027002e5e1", "bin_qr_code": "6b3bdde2-1976-437c-bdbd-2315004d6bd8", "qty": 5}
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Putaway berhasil, stock diperbarui",
    "data": {
        "id": 4,
        "inbound_id": 4,
        "item_id": 35,
        "expected_qty": 20,
        "received_qty": 20,
        "condition": "GOOD",
        "created_at": "2026-06-06T06:52:47.000000Z",
        "updated_at": "2026-06-06T06:52:53.000000Z",
        "putaway_qty": 20,
        "discrepancy_qty": 0,
        "discrepancy_note": null,
        "qr_code": "24417499-a4bc-4705-ad12-9d027002e5e1",
        "inbound": {
            "id": 4,
            "location_id": 32,
            "transaction_number": "INB-4QHGPZIH",
            "reference_number": null,
            "type": "PURCHASE_ORDER",
            "status": "PUTAWAY_IN_PROGRESS",
            "expected_date": "2026-06-10T00:00:00.000000Z",
            "created_by": "admin",
            "created_at": "2026-06-06T06:52:47.000000Z",
            "updated_at": "2026-06-06T06:52:52.000000Z",
            "source_type": null,
            "source_id": null
        },
        "variant": {
            "id": 35,
            "sku": "MOUSE-001-BLK",
            "product_id": 36
        }
    }
}
```

---

## Step 12: Worker — Scan Putaway Item 2 (full: 10 to Bin A-1-1-1)

```
POST http://localhost:8000/api/v1/inbounds/scan-putaway
```

**Request Body:**
```json
{"qr_code": "a6be34ba-f602-42a6-88cb-8ddb60689b52", "bin_qr_code": "abac22f6-db1a-4241-9028-6a7d78fcbded", "qty": 10}
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Putaway berhasil, stock diperbarui",
    "data": {
        "id": 5,
        "inbound_id": 4,
        "item_id": 36,
        "expected_qty": 10,
        "received_qty": 10,
        "condition": "GOOD",
        "created_at": "2026-06-06T06:52:47.000000Z",
        "updated_at": "2026-06-06T06:52:53.000000Z",
        "putaway_qty": 10,
        "discrepancy_qty": 0,
        "discrepancy_note": null,
        "qr_code": "a6be34ba-f602-42a6-88cb-8ddb60689b52",
        "inbound": {
            "id": 4,
            "location_id": 32,
            "transaction_number": "INB-4QHGPZIH",
            "reference_number": null,
            "type": "PURCHASE_ORDER",
            "status": "COMPLETED",
            "expected_date": "2026-06-10T00:00:00.000000Z",
            "created_by": "admin",
            "created_at": "2026-06-06T06:52:47.000000Z",
            "updated_at": "2026-06-06T06:52:53.000000Z",
            "source_type": null,
            "source_id": null
        },
        "variant": null
    }
}
```

---

## Step 13: Final Inbound Status (should be COMPLETED)

```
GET http://localhost:8000/api/v1/inbounds/4
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Detail Inbound berhasil diambil",
    "data": {
        "id": 4,
        "location_id": 32,
        "transaction_number": "INB-4QHGPZIH",
        "reference_number": null,
        "type": "PURCHASE_ORDER",
        "status": "COMPLETED",
        "expected_date": "2026-06-10T00:00:00.000000Z",
        "created_by": "admin",
        "created_at": "2026-06-06T06:52:47.000000Z",
        "updated_at": "2026-06-06T06:52:53.000000Z",
        "source_type": null,
        "source_id": null,
        "location": {
            "id": 32,
            "location_code": "WH-MAIN",
            "location_name": "Gudang Utama",
            "location_type": "warehouse",
            "address": "Jakarta",
            "area": null,
            "city": "Jakarta",
            "province": "DKI Jakarta",
            "post_code": null,
            "is_warehouse": true,
            "is_multi_origin": false,
            "default_warehouse_user": null,
            "is_active": true,
            "is_fbl": null,
            "is_tcb": null,
            "is_fbs": null,
            "created_at": "2026-06-05T10:42:14.000000Z",
            "updated_at": "2026-06-05T10:42:14.000000Z"
        },
        "items": [
            {
                "id": 4,
                "inbound_id": 4,
                "item_id": 35,
                "expected_qty": 20,
                "received_qty": 20,
                "condition": "GOOD",
                "created_at": "2026-06-06T06:52:47.000000Z",
                "updated_at": "2026-06-06T06:52:53.000000Z",
                "putaway_qty": 20,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "qr_code": "24417499-a4bc-4705-ad12-9d027002e5e1",
                "receipts": [
                    {
                        "id": 6,
                        "inbound_item_id": 4,
                        "qty": 20,
                        "bin_id": 1,
                        "batch_no": null,
                        "serial_no": null,
                        "received_by": "admin",
                        "received_date": "2026-06-06T06:52:52.000000Z",
                        "created_at": "2026-06-06T06:52:52.000000Z",
                        "updated_at": "2026-06-06T06:52:52.000000Z",
                        "condition": "GOOD",
                        "bin": {
                            "id": 1,
                            "location_id": 32,
                            "floor_code": "INB",
                            "row_code": "0",
                            "column_code": "0",
                            "bin_code": "0",
                            "bin_final_code": "INBOUND-DEFAULT",
                            "max_qty": 0,
                            "is_inbound": true,
                            "created_at": "2026-06-06T01:44:01.000000Z",
                            "updated_at": "2026-06-06T01:44:01.000000Z",
                            "qr_code": "7ff36d54-53eb-4bca-b2af-1de66a90b969"
                        }
                    }
                ],
                "variant": {
                    "id": 35,
                    "sku": "MOUSE-001-BLK",
                    "product_id": 36
                }
            },
            {
                "id": 5,
                "inbound_id": 4,
                "item_id": 36,
                "expected_qty": 10,
                "received_qty": 10,
                "condition": "GOOD",
                "created_at": "2026-06-06T06:52:47.000000Z",
                "updated_at": "2026-06-06T06:52:53.000000Z",
                "putaway_qty": 10,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "qr_code": "a6be34ba-f602-42a6-88cb-8ddb60689b52",
                "receipts": [
                    {
                        "id": 7,
                        "inbound_item_id": 5,
                        "qty": 10,
                        "bin_id": 1,
                        "batch_no": null,
                        "serial_no": null,
                        "received_by": "admin",
                        "received_date": "2026-06-06T06:52:52.000000Z",
                        "created_at": "2026-06-06T06:52:52.000000Z",
                        "updated_at": "2026-06-06T06:52:52.000000Z",
                        "condition": "GOOD",
                        "bin": {
                            "id": 1,
                            "location_id": 32,
                            "floor_code": "INB",
                            "row_code": "0",
                            "column_code": "0",
                            "bin_code": "0",
                            "bin_final_code": "INBOUND-DEFAULT",
                            "max_qty": 0,
                            "is_inbound": true,
                            "created_at": "2026-06-06T01:44:01.000000Z",
                            "updated_at": "2026-06-06T01:44:01.000000Z",
                            "qr_code": "7ff36d54-53eb-4bca-b2af-1de66a90b969"
                        }
                    }
                ],
                "variant": null
            }
        ]
    }
}
```

---

## Step 14: Verify Inventory Updated

```
GET http://localhost:8000/api/v1/inventory/stocks
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Daftar stok berhasil diambil",
    "data": [
        {
            "id": 41,
            "item_id": 36,
            "location_id": 32,
            "bin_id": 2,
            "on_hand": 10,
            "available": 10,
            "product": null,
            "location": { "id": 32, "location_name": "Gudang Utama" },
            "bin": { "id": 2, "bin_final_code": "A-1-1-1" }
        },
        {
            "id": 40,
            "item_id": 35,
            "location_id": 32,
            "bin_id": 3,
            "on_hand": 5,
            "available": 5,
            "product": { "id": 35, "sku": "MOUSE-001-BLK", "product_id": 36 },
            "location": { "id": 32, "location_name": "Gudang Utama" },
            "bin": { "id": 3, "bin_final_code": "A-1-2-1" }
        },
        {
            "id": 39,
            "item_id": 35,
            "location_id": 32,
            "bin_id": 2,
            "on_hand": 15,
            "available": 15,
            "product": { "id": 35, "sku": "MOUSE-001-BLK", "product_id": 36 },
            "location": { "id": 32, "location_name": "Gudang Utama" },
            "bin": { "id": 2, "bin_final_code": "A-1-1-1" }
        },
        {
            "id": 36,
            "item_id": 34,
            "location_id": 32,
            "bin_id": 2,
            "on_hand": 100,
            "available": 100,
            "product": { "id": 34, "sku": "LAPTOP-001-8GB", "product_id": 35 },
            "location": { "id": 32, "location_name": "Gudang Utama" },
            "bin": { "id": 2, "bin_final_code": "A-1-1-1" }
        }
    ],
    "meta": { "current_page": 1, "last_page": 1, "per_page": 10, "total": 7 }
}
```

> **Note:** Rows with `on_hand: 0` (INBOUND-DEFAULT bin) omitted for brevity — stock moved out via putaway.

---

## Step 15: Check Assignment Status (should be COMPLETED)

```
GET http://localhost:8000/api/v1/inbounds/4/assignments
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Daftar assignment berhasil diambil",
    "data": [
        {
            "id": 1,
            "inbound_id": 4,
            "assigned_to": 2,
            "assigned_by": 1,
            "status": "COMPLETED",
            "notes": "Handle laptop & mouse batch",
            "started_at": "2026-06-06T06:52:52.000000Z",
            "completed_at": "2026-06-06T06:52:53.000000Z",
            "created_at": "2026-06-06T06:52:51.000000Z",
            "updated_at": "2026-06-06T06:52:53.000000Z",
            "worker": {
                "id": 2,
                "name": "Pekerja Gudang",
                "email": "worker@e2e.com"
            },
            "assigner": {
                "id": 1,
                "name": "Admin Gudang",
                "email": "admin@e2e.com"
            }
        }
    ]
}
```

---

## Step 16: Error — Scan Invalid QR

```
GET http://localhost:8000/api/v1/inbounds/scan/00000000-0000-0000-0000-000000000000
```

**Response:** `404`
```json
{
    "status": "error",
    "message": "QR Code tidak ditemukan."
}
```

---

## Step 17: Error — Putaway Over Qty (already completed)

```
POST http://localhost:8000/api/v1/inbounds/scan-putaway
```

**Response:** `500`
```json
{
    "status": "error",
    "message": "Inbound berstatus COMPLETED, tidak bisa di-putaway."
}
```

---

## Step 18: List All Inbounds

```
GET http://localhost:8000/api/v1/inbounds?limit=5
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Daftar inbound berhasil diambil",
    "data": [
        {
            "id": 4,
            "location_id": 32,
            "transaction_number": "INB-4QHGPZIH",
            "reference_number": null,
            "type": "PURCHASE_ORDER",
            "status": "COMPLETED",
            "expected_date": "2026-06-10T00:00:00.000000Z",
            "created_by": "admin",
            "created_at": "2026-06-06T06:52:47.000000Z",
            "updated_at": "2026-06-06T06:52:53.000000Z",
            "source_type": null,
            "source_id": null,
            "location": {
                "id": 32,
                "location_name": "Gudang Utama"
            },
            "items": [
                {
                    "id": 4,
                    "inbound_id": 4,
                    "item_id": 35,
                    "expected_qty": 20,
                    "received_qty": 20,
                    "condition": "GOOD",
                    "created_at": "2026-06-06T06:52:47.000000Z",
                    "updated_at": "2026-06-06T06:52:53.000000Z",
                    "putaway_qty": 20,
                    "discrepancy_qty": 0,
                    "discrepancy_note": null,
                    "qr_code": "24417499-a4bc-4705-ad12-9d027002e5e1",
                    "variant": {
                        "id": 35,
                        "sku": "MOUSE-001-BLK",
                        "product_id": 36
                    }
                },
                {
                    "id": 5,
                    "inbound_id": 4,
                    "item_id": 36,
                    "expected_qty": 10,
                    "received_qty": 10,
                    "condition": "GOOD",
                    "created_at": "2026-06-06T06:52:47.000000Z",
                    "updated_at": "2026-06-06T06:52:53.000000Z",
                    "putaway_qty": 10,
                    "discrepancy_qty": 0,
                    "discrepancy_note": null,
                    "qr_code": "a6be34ba-f602-42a6-88cb-8ddb60689b52",
                    "variant": null
                }
            ]
        },
        {
            "id": 3,
            "location_id": 32,
            "transaction_number": "INB-RKCS35TA",
            "reference_number": "PO-2026-0002",
            "type": "PURCHASE_ORDER",
            "status": "COMPLETED",
            "expected_date": "2026-06-06T00:00:00.000000Z",
            "created_by": "admin",
            "created_at": "2026-06-06T01:49:27.000000Z",
            "updated_at": "2026-06-06T01:49:27.000000Z",
            "source_type": "purchase_order",
            "source_id": 2,
            "location": {
                "id": 32,
                "location_name": "Gudang Utama"
            },
            "items": [
                {
                    "id": 3,
                    "inbound_id": 3,
                    "item_id": 34,
                    "expected_qty": 100,
                    "received_qty": 100,
                    "condition": "GOOD",
                    "created_at": "2026-06-06T01:49:27.000000Z",
                    "updated_at": "2026-06-06T01:49:27.000000Z",
                    "putaway_qty": 100,
                    "discrepancy_qty": 0,
                    "discrepancy_note": null,
                    "qr_code": "82ad5f33-9dfa-4f2f-ab16-899993957a11",
                    "variant": {
                        "id": 34,
                        "sku": "LAPTOP-001-8GB",
                        "product_id": 35
                    }
                }
            ]
        },
        {
            "id": 1,
            "location_id": 32,
            "transaction_number": "INB-ESW3J8EQ",
            "reference_number": "PO-2026-0001",
            "type": "PURCHASE_ORDER",
            "status": "DRAFT",
            "expected_date": "2026-06-06T00:00:00.000000Z",
            "created_by": "admin",
            "created_at": "2026-06-06T01:47:02.000000Z",
            "updated_at": "2026-06-06T01:47:02.000000Z",
            "source_type": "purchase_order",
            "source_id": 1,
            "location": {
                "id": 32,
                "location_name": "Gudang Utama"
            },
            "items": [
                {
                    "id": 1,
                    "inbound_id": 1,
                    "item_id": 33,
                    "expected_qty": 100,
                    "received_qty": 0,
                    "condition": "GOOD",
                    "created_at": "2026-06-06T01:47:02.000000Z",
                    "updated_at": "2026-06-06T01:47:02.000000Z",
                    "putaway_qty": 0,
                    "discrepancy_qty": 0,
                    "discrepancy_note": null,
                    "qr_code": "b0c2dbd7-1343-439f-bf4f-15b6f808f25c",
                    "variant": {
                        "id": 33,
                        "sku": "TEST-INB-001",
                        "product_id": 34
                    }
                }
            ]
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 1,
        "per_page": 5,
        "total": 3
    }
}
```

