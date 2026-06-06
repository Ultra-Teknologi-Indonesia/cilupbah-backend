# E2E Inbound Flow — UUID Primary Key

Generated: 2026-06-06 14:50:12

> **Key Change:** `inbound_items.id` dan `location_bins.id` sekarang UUID.
> UUID primary key = QR code. Tidak perlu kolom `qr_code` terpisah.

## Step 1: Create Inbound (DRAFT)

```
POST http://localhost:8000/api/v1/inbounds
```

**Request Body:**
```json
{
    "location_id": 1,
    "type": "PURCHASE_ORDER",
    "expected_date": "2026-06-15",
    "created_by": "admin",
    "items": [
        {
            "item_id": 1,
            "expected_qty": 20
        },
        {
            "item_id": 2,
            "expected_qty": 10
        }
    ]
}
```

**Response:** `201`
```json
{
    "status": "success",
    "message": "Draft Inbound berhasil dibuat",
    "data": {
        "location_id": 1,
        "type": "PURCHASE_ORDER",
        "expected_date": "2026-06-15T00:00:00.000000Z",
        "created_by": "admin",
        "transaction_number": "INB-HCDR78Y3",
        "status": "DRAFT",
        "updated_at": "2026-06-06T07:50:15.000000Z",
        "created_at": "2026-06-06T07:50:15.000000Z",
        "id": 1,
        "items": [
            {
                "id": "019e9be9-42c8-72e5-b71c-a7f757c6dc81",
                "inbound_id": 1,
                "item_id": 1,
                "expected_qty": 20,
                "received_qty": 0,
                "condition": "GOOD",
                "created_at": "2026-06-06T07:50:16.000000Z",
                "updated_at": "2026-06-06T07:50:16.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null
            },
            {
                "id": "019e9be9-42d0-7381-ad20-8e2ed4f88821",
                "inbound_id": 1,
                "item_id": 2,
                "expected_qty": 10,
                "received_qty": 0,
                "condition": "GOOD",
                "created_at": "2026-06-06T07:50:16.000000Z",
                "updated_at": "2026-06-06T07:50:16.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null
            }
        ]
    }
}
```

---


## Step 2: Get Inbound Detail

```
GET http://localhost:8000/api/v1/inbounds/1
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Detail Inbound berhasil diambil",
    "data": {
        "id": 1,
        "location_id": 1,
        "transaction_number": "INB-HCDR78Y3",
        "reference_number": null,
        "type": "PURCHASE_ORDER",
        "status": "DRAFT",
        "expected_date": "2026-06-15T00:00:00.000000Z",
        "created_by": "admin",
        "created_at": "2026-06-06T07:50:15.000000Z",
        "updated_at": "2026-06-06T07:50:15.000000Z",
        "source_type": null,
        "source_id": null,
        "location": {
            "id": 1,
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
            "created_at": "2026-06-06T07:47:02.000000Z",
            "updated_at": "2026-06-06T07:47:02.000000Z"
        },
        "items": [
            {
                "id": "019e9be9-42c8-72e5-b71c-a7f757c6dc81",
                "inbound_id": 1,
                "item_id": 1,
                "expected_qty": 20,
                "received_qty": 0,
                "condition": "GOOD",
                "created_at": "2026-06-06T07:50:16.000000Z",
                "updated_at": "2026-06-06T07:50:16.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "receipts": [],
                "variant": {
                    "id": 1,
                    "sku": "LAPTOP-001-8GB",
                    "product_id": 1
                }
            },
            {
                "id": "019e9be9-42d0-7381-ad20-8e2ed4f88821",
                "inbound_id": 1,
                "item_id": 2,
                "expected_qty": 10,
                "received_qty": 0,
                "condition": "GOOD",
                "created_at": "2026-06-06T07:50:16.000000Z",
                "updated_at": "2026-06-06T07:50:16.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "receipts": [],
                "variant": {
                    "id": 2,
                    "sku": "MOUSE-001-BLK",
                    "product_id": 2
                }
            }
        ]
    }
}
```

---


## Step 3: Admin Assign to Worker

```
POST http://localhost:8000/api/v1/inbounds/1/assign
```

**Request Body:**
```json
{
    "assigned_to": 2,
    "notes": "Handle laptop & mouse batch"
}
```

**Response:** `201`
```json
{
    "status": "success",
    "message": "Inbound berhasil di-assign",
    "data": {
        "inbound_id": 1,
        "assigned_to": 2,
        "assigned_by": 1,
        "status": "PENDING",
        "notes": "Handle laptop & mouse batch",
        "updated_at": "2026-06-06T07:50:17.000000Z",
        "created_at": "2026-06-06T07:50:17.000000Z",
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


## Step 4: Get Assignments

```
GET http://localhost:8000/api/v1/inbounds/1/assignments
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Daftar assignment berhasil diambil",
    "data": [
        {
            "id": 1,
            "inbound_id": 1,
            "assigned_to": 2,
            "assigned_by": 1,
            "status": "PENDING",
            "notes": "Handle laptop & mouse batch",
            "started_at": null,
            "completed_at": null,
            "created_at": "2026-06-06T07:50:17.000000Z",
            "updated_at": "2026-06-06T07:50:17.000000Z",
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


## Step 5: Worker — My Assignments

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
            "inbound_id": 1,
            "assigned_to": 2,
            "assigned_by": 1,
            "status": "PENDING",
            "notes": "Handle laptop & mouse batch",
            "started_at": null,
            "completed_at": null,
            "created_at": "2026-06-06T07:50:17.000000Z",
            "updated_at": "2026-06-06T07:50:17.000000Z",
            "inbound": {
                "id": 1,
                "location_id": 1,
                "transaction_number": "INB-HCDR78Y3",
                "reference_number": null,
                "type": "PURCHASE_ORDER",
                "status": "DRAFT",
                "expected_date": "2026-06-15T00:00:00.000000Z",
                "created_by": "admin",
                "created_at": "2026-06-06T07:50:15.000000Z",
                "updated_at": "2026-06-06T07:50:15.000000Z",
                "source_type": null,
                "source_id": null,
                "location": {
                    "id": 1,
                    "location_name": "Gudang Utama"
                },
                "items": [
                    {
                        "id": "019e9be9-42c8-72e5-b71c-a7f757c6dc81",
                        "inbound_id": 1,
                        "item_id": 1,
                        "expected_qty": 20,
                        "received_qty": 0,
                        "condition": "GOOD",
                        "created_at": "2026-06-06T07:50:16.000000Z",
                        "updated_at": "2026-06-06T07:50:16.000000Z",
                        "putaway_qty": 0,
                        "discrepancy_qty": 0,
                        "discrepancy_note": null
                    },
                    {
                        "id": "019e9be9-42d0-7381-ad20-8e2ed4f88821",
                        "inbound_id": 1,
                        "item_id": 2,
                        "expected_qty": 10,
                        "received_qty": 0,
                        "condition": "GOOD",
                        "created_at": "2026-06-06T07:50:16.000000Z",
                        "updated_at": "2026-06-06T07:50:16.000000Z",
                        "putaway_qty": 0,
                        "discrepancy_qty": 0,
                        "discrepancy_note": null
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
        "inbound_id": 1,
        "assigned_to": 2,
        "assigned_by": 1,
        "status": "IN_PROGRESS",
        "notes": "Handle laptop & mouse batch",
        "started_at": "2026-06-06T07:50:17.000000Z",
        "completed_at": null,
        "created_at": "2026-06-06T07:50:17.000000Z",
        "updated_at": "2026-06-06T07:50:17.000000Z",
        "inbound": {
            "id": 1,
            "location_id": 1,
            "transaction_number": "INB-HCDR78Y3",
            "reference_number": null,
            "type": "PURCHASE_ORDER",
            "status": "DRAFT",
            "expected_date": "2026-06-15T00:00:00.000000Z",
            "created_by": "admin",
            "created_at": "2026-06-06T07:50:15.000000Z",
            "updated_at": "2026-06-06T07:50:15.000000Z",
            "source_type": null,
            "source_id": null,
            "items": [
                {
                    "id": "019e9be9-42c8-72e5-b71c-a7f757c6dc81",
                    "inbound_id": 1,
                    "item_id": 1,
                    "expected_qty": 20,
                    "received_qty": 0,
                    "condition": "GOOD",
                    "created_at": "2026-06-06T07:50:16.000000Z",
                    "updated_at": "2026-06-06T07:50:16.000000Z",
                    "putaway_qty": 0,
                    "discrepancy_qty": 0,
                    "discrepancy_note": null
                },
                {
                    "id": "019e9be9-42d0-7381-ad20-8e2ed4f88821",
                    "inbound_id": 1,
                    "item_id": 2,
                    "expected_qty": 10,
                    "received_qty": 0,
                    "condition": "GOOD",
                    "created_at": "2026-06-06T07:50:16.000000Z",
                    "updated_at": "2026-06-06T07:50:16.000000Z",
                    "putaway_qty": 0,
                    "discrepancy_qty": 0,
                    "discrepancy_note": null
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


## Step 7: Scan QR Barang (UUID = PK)

```
GET http://localhost:8000/api/v1/inbounds/scan/019e9be9-42c8-72e5-b71c-a7f757c6dc81
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Item ditemukan",
    "data": {
        "id": "019e9be9-42c8-72e5-b71c-a7f757c6dc81",
        "inbound_id": 1,
        "item_id": 1,
        "expected_qty": 20,
        "received_qty": 0,
        "condition": "GOOD",
        "created_at": "2026-06-06T07:50:16.000000Z",
        "updated_at": "2026-06-06T07:50:16.000000Z",
        "putaway_qty": 0,
        "discrepancy_qty": 0,
        "discrepancy_note": null,
        "inbound": {
            "id": 1,
            "location_id": 1,
            "transaction_number": "INB-HCDR78Y3",
            "reference_number": null,
            "type": "PURCHASE_ORDER",
            "status": "DRAFT",
            "expected_date": "2026-06-15T00:00:00.000000Z",
            "created_by": "admin",
            "created_at": "2026-06-06T07:50:15.000000Z",
            "updated_at": "2026-06-06T07:50:15.000000Z",
            "source_type": null,
            "source_id": null,
            "location": {
                "id": 1,
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
                "created_at": "2026-06-06T07:47:02.000000Z",
                "updated_at": "2026-06-06T07:47:02.000000Z"
            }
        },
        "variant": {
            "id": 1,
            "sku": "LAPTOP-001-8GB",
            "product_id": 1
        }
    }
}
```

---


## Step 8: Receive Items

```
POST http://localhost:8000/api/v1/inbounds/1/receive
```

**Request Body:**
```json
{
    "received_by": "admin",
    "items": [
        {
            "inbound_item_id": "019e9be9-42c8-72e5-b71c-a7f757c6dc81",
            "qty": 20
        },
        {
            "inbound_item_id": "019e9be9-42d0-7381-ad20-8e2ed4f88821",
            "qty": 10
        }
    ]
}
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Penerimaan Inbound berhasil diproses",
    "data": {
        "id": 1,
        "location_id": 1,
        "transaction_number": "INB-HCDR78Y3",
        "reference_number": null,
        "type": "PURCHASE_ORDER",
        "status": "RECEIVED",
        "expected_date": "2026-06-15T00:00:00.000000Z",
        "created_by": "admin",
        "created_at": "2026-06-06T07:50:15.000000Z",
        "updated_at": "2026-06-06T07:50:18.000000Z",
        "source_type": null,
        "source_id": null,
        "location": {
            "id": 1,
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
            "created_at": "2026-06-06T07:47:02.000000Z",
            "updated_at": "2026-06-06T07:47:02.000000Z"
        },
        "items": [
            {
                "id": "019e9be9-42c8-72e5-b71c-a7f757c6dc81",
                "inbound_id": 1,
                "item_id": 1,
                "expected_qty": 20,
                "received_qty": 20,
                "condition": "GOOD",
                "created_at": "2026-06-06T07:50:16.000000Z",
                "updated_at": "2026-06-06T07:50:17.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "receipts": [
                    {
                        "id": 1,
                        "inbound_item_id": "019e9be9-42c8-72e5-b71c-a7f757c6dc81",
                        "qty": 20,
                        "bin_id": "019e9be6-5102-70be-b76b-66b8fb12b40c",
                        "batch_no": null,
                        "serial_no": null,
                        "received_by": "admin",
                        "received_date": "2026-06-06T07:50:17.000000Z",
                        "created_at": "2026-06-06T07:50:17.000000Z",
                        "updated_at": "2026-06-06T07:50:17.000000Z",
                        "condition": "GOOD",
                        "bin": {
                            "id": "019e9be6-5102-70be-b76b-66b8fb12b40c",
                            "location_id": 1,
                            "floor_code": "INB",
                            "row_code": "0",
                            "column_code": "0",
                            "bin_code": "0",
                            "bin_final_code": "INBOUND-DEFAULT",
                            "max_qty": 0,
                            "is_inbound": true,
                            "created_at": "2026-06-06T07:47:03.000000Z",
                            "updated_at": "2026-06-06T07:47:03.000000Z"
                        }
                    }
                ],
                "variant": {
                    "id": 1,
                    "sku": "LAPTOP-001-8GB",
                    "product_id": 1
                }
            },
            {
                "id": "019e9be9-42d0-7381-ad20-8e2ed4f88821",
                "inbound_id": 1,
                "item_id": 2,
                "expected_qty": 10,
                "received_qty": 10,
                "condition": "GOOD",
                "created_at": "2026-06-06T07:50:16.000000Z",
                "updated_at": "2026-06-06T07:50:17.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "receipts": [
                    {
                        "id": 2,
                        "inbound_item_id": "019e9be9-42d0-7381-ad20-8e2ed4f88821",
                        "qty": 10,
                        "bin_id": "019e9be6-5102-70be-b76b-66b8fb12b40c",
                        "batch_no": null,
                        "serial_no": null,
                        "received_by": "admin",
                        "received_date": "2026-06-06T07:50:17.000000Z",
                        "created_at": "2026-06-06T07:50:17.000000Z",
                        "updated_at": "2026-06-06T07:50:17.000000Z",
                        "condition": "GOOD",
                        "bin": {
                            "id": "019e9be6-5102-70be-b76b-66b8fb12b40c",
                            "location_id": 1,
                            "floor_code": "INB",
                            "row_code": "0",
                            "column_code": "0",
                            "bin_code": "0",
                            "bin_final_code": "INBOUND-DEFAULT",
                            "max_qty": 0,
                            "is_inbound": true,
                            "created_at": "2026-06-06T07:47:03.000000Z",
                            "updated_at": "2026-06-06T07:47:03.000000Z"
                        }
                    }
                ],
                "variant": {
                    "id": 2,
                    "sku": "MOUSE-001-BLK",
                    "product_id": 2
                }
            }
        ]
    }
}
```

---


## Step 9: Close Receiving

```
POST http://localhost:8000/api/v1/inbounds/1/close-receiving
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Receiving ditutup, discrepancy tercatat",
    "data": {
        "id": 1,
        "location_id": 1,
        "transaction_number": "INB-HCDR78Y3",
        "reference_number": null,
        "type": "PURCHASE_ORDER",
        "status": "RECEIVED",
        "expected_date": "2026-06-15T00:00:00.000000Z",
        "created_by": "admin",
        "created_at": "2026-06-06T07:50:15.000000Z",
        "updated_at": "2026-06-06T07:50:18.000000Z",
        "source_type": null,
        "source_id": null,
        "location": {
            "id": 1,
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
            "created_at": "2026-06-06T07:47:02.000000Z",
            "updated_at": "2026-06-06T07:47:02.000000Z"
        },
        "items": [
            {
                "id": "019e9be9-42c8-72e5-b71c-a7f757c6dc81",
                "inbound_id": 1,
                "item_id": 1,
                "expected_qty": 20,
                "received_qty": 20,
                "condition": "GOOD",
                "created_at": "2026-06-06T07:50:16.000000Z",
                "updated_at": "2026-06-06T07:50:17.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "receipts": [
                    {
                        "id": 1,
                        "inbound_item_id": "019e9be9-42c8-72e5-b71c-a7f757c6dc81",
                        "qty": 20,
                        "bin_id": "019e9be6-5102-70be-b76b-66b8fb12b40c",
                        "batch_no": null,
                        "serial_no": null,
                        "received_by": "admin",
                        "received_date": "2026-06-06T07:50:17.000000Z",
                        "created_at": "2026-06-06T07:50:17.000000Z",
                        "updated_at": "2026-06-06T07:50:17.000000Z",
                        "condition": "GOOD",
                        "bin": {
                            "id": "019e9be6-5102-70be-b76b-66b8fb12b40c",
                            "location_id": 1,
                            "floor_code": "INB",
                            "row_code": "0",
                            "column_code": "0",
                            "bin_code": "0",
                            "bin_final_code": "INBOUND-DEFAULT",
                            "max_qty": 0,
                            "is_inbound": true,
                            "created_at": "2026-06-06T07:47:03.000000Z",
                            "updated_at": "2026-06-06T07:47:03.000000Z"
                        }
                    }
                ],
                "variant": {
                    "id": 1,
                    "sku": "LAPTOP-001-8GB",
                    "product_id": 1
                }
            },
            {
                "id": "019e9be9-42d0-7381-ad20-8e2ed4f88821",
                "inbound_id": 1,
                "item_id": 2,
                "expected_qty": 10,
                "received_qty": 10,
                "condition": "GOOD",
                "created_at": "2026-06-06T07:50:16.000000Z",
                "updated_at": "2026-06-06T07:50:17.000000Z",
                "putaway_qty": 0,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "receipts": [
                    {
                        "id": 2,
                        "inbound_item_id": "019e9be9-42d0-7381-ad20-8e2ed4f88821",
                        "qty": 10,
                        "bin_id": "019e9be6-5102-70be-b76b-66b8fb12b40c",
                        "batch_no": null,
                        "serial_no": null,
                        "received_by": "admin",
                        "received_date": "2026-06-06T07:50:17.000000Z",
                        "created_at": "2026-06-06T07:50:17.000000Z",
                        "updated_at": "2026-06-06T07:50:17.000000Z",
                        "condition": "GOOD",
                        "bin": {
                            "id": "019e9be6-5102-70be-b76b-66b8fb12b40c",
                            "location_id": 1,
                            "floor_code": "INB",
                            "row_code": "0",
                            "column_code": "0",
                            "bin_code": "0",
                            "bin_final_code": "INBOUND-DEFAULT",
                            "max_qty": 0,
                            "is_inbound": true,
                            "created_at": "2026-06-06T07:47:03.000000Z",
                            "updated_at": "2026-06-06T07:47:03.000000Z"
                        }
                    }
                ],
                "variant": {
                    "id": 2,
                    "sku": "MOUSE-001-BLK",
                    "product_id": 2
                }
            }
        ]
    }
}
```

---


## Step 10: Scan Putaway Item 1 → Bin A-1-1-1 (15 of 20)

```
POST http://localhost:8000/api/v1/inbounds/scan-putaway
```

**Request Body:**
```json
{
    "inbound_item_id": "019e9be9-42c8-72e5-b71c-a7f757c6dc81",
    "bin_id": "019e9be6-510b-72bf-9b29-acb587b74b09",
    "qty": 15
}
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Putaway berhasil, stock diperbarui",
    "data": {
        "id": "019e9be9-42c8-72e5-b71c-a7f757c6dc81",
        "inbound_id": 1,
        "item_id": 1,
        "expected_qty": 20,
        "received_qty": 20,
        "condition": "GOOD",
        "created_at": "2026-06-06T07:50:16.000000Z",
        "updated_at": "2026-06-06T07:50:18.000000Z",
        "putaway_qty": 15,
        "discrepancy_qty": 0,
        "discrepancy_note": null,
        "inbound": {
            "id": 1,
            "location_id": 1,
            "transaction_number": "INB-HCDR78Y3",
            "reference_number": null,
            "type": "PURCHASE_ORDER",
            "status": "PUTAWAY_IN_PROGRESS",
            "expected_date": "2026-06-15T00:00:00.000000Z",
            "created_by": "admin",
            "created_at": "2026-06-06T07:50:15.000000Z",
            "updated_at": "2026-06-06T07:50:18.000000Z",
            "source_type": null,
            "source_id": null
        },
        "variant": {
            "id": 1,
            "sku": "LAPTOP-001-8GB",
            "product_id": 1
        }
    }
}
```

---


## Step 11: Scan Putaway Item 1 → Bin A-1-2-1 (remaining 5)

```
POST http://localhost:8000/api/v1/inbounds/scan-putaway
```

**Request Body:**
```json
{
    "inbound_item_id": "019e9be9-42c8-72e5-b71c-a7f757c6dc81",
    "bin_id": "019e9be6-510c-715b-b279-65fbcc72a513",
    "qty": 5
}
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Putaway berhasil, stock diperbarui",
    "data": {
        "id": "019e9be9-42c8-72e5-b71c-a7f757c6dc81",
        "inbound_id": 1,
        "item_id": 1,
        "expected_qty": 20,
        "received_qty": 20,
        "condition": "GOOD",
        "created_at": "2026-06-06T07:50:16.000000Z",
        "updated_at": "2026-06-06T07:50:18.000000Z",
        "putaway_qty": 20,
        "discrepancy_qty": 0,
        "discrepancy_note": null,
        "inbound": {
            "id": 1,
            "location_id": 1,
            "transaction_number": "INB-HCDR78Y3",
            "reference_number": null,
            "type": "PURCHASE_ORDER",
            "status": "PUTAWAY_IN_PROGRESS",
            "expected_date": "2026-06-15T00:00:00.000000Z",
            "created_by": "admin",
            "created_at": "2026-06-06T07:50:15.000000Z",
            "updated_at": "2026-06-06T07:50:18.000000Z",
            "source_type": null,
            "source_id": null
        },
        "variant": {
            "id": 1,
            "sku": "LAPTOP-001-8GB",
            "product_id": 1
        }
    }
}
```

---


## Step 12: Scan Putaway Item 2 → Bin A-1-1-1 (full 10)

```
POST http://localhost:8000/api/v1/inbounds/scan-putaway
```

**Request Body:**
```json
{
    "inbound_item_id": "019e9be9-42d0-7381-ad20-8e2ed4f88821",
    "bin_id": "019e9be6-510b-72bf-9b29-acb587b74b09",
    "qty": 10
}
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Putaway berhasil, stock diperbarui",
    "data": {
        "id": "019e9be9-42d0-7381-ad20-8e2ed4f88821",
        "inbound_id": 1,
        "item_id": 2,
        "expected_qty": 10,
        "received_qty": 10,
        "condition": "GOOD",
        "created_at": "2026-06-06T07:50:16.000000Z",
        "updated_at": "2026-06-06T07:50:18.000000Z",
        "putaway_qty": 10,
        "discrepancy_qty": 0,
        "discrepancy_note": null,
        "inbound": {
            "id": 1,
            "location_id": 1,
            "transaction_number": "INB-HCDR78Y3",
            "reference_number": null,
            "type": "PURCHASE_ORDER",
            "status": "COMPLETED",
            "expected_date": "2026-06-15T00:00:00.000000Z",
            "created_by": "admin",
            "created_at": "2026-06-06T07:50:15.000000Z",
            "updated_at": "2026-06-06T07:50:18.000000Z",
            "source_type": null,
            "source_id": null
        },
        "variant": {
            "id": 2,
            "sku": "MOUSE-001-BLK",
            "product_id": 2
        }
    }
}
```

---


## Step 13: Final Inbound Status (expect COMPLETED)

```
GET http://localhost:8000/api/v1/inbounds/1
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Detail Inbound berhasil diambil",
    "data": {
        "id": 1,
        "location_id": 1,
        "transaction_number": "INB-HCDR78Y3",
        "reference_number": null,
        "type": "PURCHASE_ORDER",
        "status": "COMPLETED",
        "expected_date": "2026-06-15T00:00:00.000000Z",
        "created_by": "admin",
        "created_at": "2026-06-06T07:50:15.000000Z",
        "updated_at": "2026-06-06T07:50:18.000000Z",
        "source_type": null,
        "source_id": null,
        "location": {
            "id": 1,
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
            "created_at": "2026-06-06T07:47:02.000000Z",
            "updated_at": "2026-06-06T07:47:02.000000Z"
        },
        "items": [
            {
                "id": "019e9be9-42c8-72e5-b71c-a7f757c6dc81",
                "inbound_id": 1,
                "item_id": 1,
                "expected_qty": 20,
                "received_qty": 20,
                "condition": "GOOD",
                "created_at": "2026-06-06T07:50:16.000000Z",
                "updated_at": "2026-06-06T07:50:18.000000Z",
                "putaway_qty": 20,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "receipts": [
                    {
                        "id": 1,
                        "inbound_item_id": "019e9be9-42c8-72e5-b71c-a7f757c6dc81",
                        "qty": 20,
                        "bin_id": "019e9be6-5102-70be-b76b-66b8fb12b40c",
                        "batch_no": null,
                        "serial_no": null,
                        "received_by": "admin",
                        "received_date": "2026-06-06T07:50:17.000000Z",
                        "created_at": "2026-06-06T07:50:17.000000Z",
                        "updated_at": "2026-06-06T07:50:17.000000Z",
                        "condition": "GOOD",
                        "bin": {
                            "id": "019e9be6-5102-70be-b76b-66b8fb12b40c",
                            "location_id": 1,
                            "floor_code": "INB",
                            "row_code": "0",
                            "column_code": "0",
                            "bin_code": "0",
                            "bin_final_code": "INBOUND-DEFAULT",
                            "max_qty": 0,
                            "is_inbound": true,
                            "created_at": "2026-06-06T07:47:03.000000Z",
                            "updated_at": "2026-06-06T07:47:03.000000Z"
                        }
                    }
                ],
                "variant": {
                    "id": 1,
                    "sku": "LAPTOP-001-8GB",
                    "product_id": 1
                }
            },
            {
                "id": "019e9be9-42d0-7381-ad20-8e2ed4f88821",
                "inbound_id": 1,
                "item_id": 2,
                "expected_qty": 10,
                "received_qty": 10,
                "condition": "GOOD",
                "created_at": "2026-06-06T07:50:16.000000Z",
                "updated_at": "2026-06-06T07:50:18.000000Z",
                "putaway_qty": 10,
                "discrepancy_qty": 0,
                "discrepancy_note": null,
                "receipts": [
                    {
                        "id": 2,
                        "inbound_item_id": "019e9be9-42d0-7381-ad20-8e2ed4f88821",
                        "qty": 10,
                        "bin_id": "019e9be6-5102-70be-b76b-66b8fb12b40c",
                        "batch_no": null,
                        "serial_no": null,
                        "received_by": "admin",
                        "received_date": "2026-06-06T07:50:17.000000Z",
                        "created_at": "2026-06-06T07:50:17.000000Z",
                        "updated_at": "2026-06-06T07:50:17.000000Z",
                        "condition": "GOOD",
                        "bin": {
                            "id": "019e9be6-5102-70be-b76b-66b8fb12b40c",
                            "location_id": 1,
                            "floor_code": "INB",
                            "row_code": "0",
                            "column_code": "0",
                            "bin_code": "0",
                            "bin_final_code": "INBOUND-DEFAULT",
                            "max_qty": 0,
                            "is_inbound": true,
                            "created_at": "2026-06-06T07:47:03.000000Z",
                            "updated_at": "2026-06-06T07:47:03.000000Z"
                        }
                    }
                ],
                "variant": {
                    "id": 2,
                    "sku": "MOUSE-001-BLK",
                    "product_id": 2
                }
            }
        ]
    }
}
```

---


## Step 14: Verify Inventory Stocks

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
            "id": 3,
            "item_id": 1,
            "location_id": 1,
            "bin_id": "019e9be6-510b-72bf-9b29-acb587b74b09",
            "batch_no": "",
            "serial_no": "",
            "expired_date": null,
            "on_hand": 15,
            "on_order": 0,
            "reserved": 0,
            "available": 15,
            "created_at": "2026-06-06T07:50:18.000000Z",
            "updated_at": "2026-06-06T07:50:18.000000Z",
            "product": {
                "id": 1,
                "sku": "LAPTOP-001-8GB",
                "product_id": 1
            },
            "location": {
                "id": 1,
                "location_name": "Gudang Utama"
            },
            "bin": {
                "id": "019e9be6-510b-72bf-9b29-acb587b74b09",
                "bin_final_code": "A-1-1-1"
            }
        },
        {
            "id": 4,
            "item_id": 1,
            "location_id": 1,
            "bin_id": "019e9be6-510c-715b-b279-65fbcc72a513",
            "batch_no": "",
            "serial_no": "",
            "expired_date": null,
            "on_hand": 5,
            "on_order": 0,
            "reserved": 0,
            "available": 5,
            "created_at": "2026-06-06T07:50:18.000000Z",
            "updated_at": "2026-06-06T07:50:18.000000Z",
            "product": {
                "id": 1,
                "sku": "LAPTOP-001-8GB",
                "product_id": 1
            },
            "location": {
                "id": 1,
                "location_name": "Gudang Utama"
            },
            "bin": {
                "id": "019e9be6-510c-715b-b279-65fbcc72a513",
                "bin_final_code": "A-1-2-1"
            }
        },
        {
            "id": 5,
            "item_id": 2,
            "location_id": 1,
            "bin_id": "019e9be6-510b-72bf-9b29-acb587b74b09",
            "batch_no": "",
            "serial_no": "",
            "expired_date": null,
            "on_hand": 10,
            "on_order": 0,
            "reserved": 0,
            "available": 10,
            "created_at": "2026-06-06T07:50:18.000000Z",
            "updated_at": "2026-06-06T07:50:18.000000Z",
            "product": {
                "id": 2,
                "sku": "MOUSE-001-BLK",
                "product_id": 2
            },
            "location": {
                "id": 1,
                "location_name": "Gudang Utama"
            },
            "bin": {
                "id": "019e9be6-510b-72bf-9b29-acb587b74b09",
                "bin_final_code": "A-1-1-1"
            }
        },
        {
            "id": 1,
            "item_id": 1,
            "location_id": 1,
            "bin_id": "019e9be6-5102-70be-b76b-66b8fb12b40c",
            "batch_no": "",
            "serial_no": "",
            "expired_date": null,
            "on_hand": 0,
            "on_order": 0,
            "reserved": 0,
            "available": 0,
            "created_at": "2026-06-06T07:50:17.000000Z",
            "updated_at": "2026-06-06T07:50:18.000000Z",
            "product": {
                "id": 1,
                "sku": "LAPTOP-001-8GB",
                "product_id": 1
            },
            "location": {
                "id": 1,
                "location_name": "Gudang Utama"
            },
            "bin": {
                "id": "019e9be6-5102-70be-b76b-66b8fb12b40c",
                "bin_final_code": "INBOUND-DEFAULT"
            }
        },
        {
            "id": 2,
            "item_id": 2,
            "location_id": 1,
            "bin_id": "019e9be6-5102-70be-b76b-66b8fb12b40c",
            "batch_no": "",
            "serial_no": "",
            "expired_date": null,
            "on_hand": 0,
            "on_order": 0,
            "reserved": 0,
            "available": 0,
            "created_at": "2026-06-06T07:50:17.000000Z",
            "updated_at": "2026-06-06T07:50:18.000000Z",
            "product": {
                "id": 2,
                "sku": "MOUSE-001-BLK",
                "product_id": 2
            },
            "location": {
                "id": 1,
                "location_name": "Gudang Utama"
            },
            "bin": {
                "id": "019e9be6-5102-70be-b76b-66b8fb12b40c",
                "bin_final_code": "INBOUND-DEFAULT"
            }
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 1,
        "per_page": 10,
        "total": 5
    }
}
```

---


## Step 15: Assignment Status (expect COMPLETED)

```
GET http://localhost:8000/api/v1/inbounds/1/assignments
```

**Response:** `200`
```json
{
    "status": "success",
    "message": "Daftar assignment berhasil diambil",
    "data": [
        {
            "id": 1,
            "inbound_id": 1,
            "assigned_to": 2,
            "assigned_by": 1,
            "status": "COMPLETED",
            "notes": "Handle laptop & mouse batch",
            "started_at": "2026-06-06T07:50:17.000000Z",
            "completed_at": "2026-06-06T07:50:18.000000Z",
            "created_at": "2026-06-06T07:50:17.000000Z",
            "updated_at": "2026-06-06T07:50:18.000000Z",
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


## Step 16: Error — Scan Invalid UUID

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


## Step 17: Error — Putaway on COMPLETED inbound

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
            "id": 1,
            "location_id": 1,
            "transaction_number": "INB-HCDR78Y3",
            "reference_number": null,
            "type": "PURCHASE_ORDER",
            "status": "COMPLETED",
            "expected_date": "2026-06-15T00:00:00.000000Z",
            "created_by": "admin",
            "created_at": "2026-06-06T07:50:15.000000Z",
            "updated_at": "2026-06-06T07:50:18.000000Z",
            "source_type": null,
            "source_id": null,
            "location": {
                "id": 1,
                "location_name": "Gudang Utama"
            },
            "items": [
                {
                    "id": "019e9be9-42c8-72e5-b71c-a7f757c6dc81",
                    "inbound_id": 1,
                    "item_id": 1,
                    "expected_qty": 20,
                    "received_qty": 20,
                    "condition": "GOOD",
                    "created_at": "2026-06-06T07:50:16.000000Z",
                    "updated_at": "2026-06-06T07:50:18.000000Z",
                    "putaway_qty": 20,
                    "discrepancy_qty": 0,
                    "discrepancy_note": null,
                    "variant": {
                        "id": 1,
                        "sku": "LAPTOP-001-8GB",
                        "product_id": 1
                    }
                },
                {
                    "id": "019e9be9-42d0-7381-ad20-8e2ed4f88821",
                    "inbound_id": 1,
                    "item_id": 2,
                    "expected_qty": 10,
                    "received_qty": 10,
                    "condition": "GOOD",
                    "created_at": "2026-06-06T07:50:16.000000Z",
                    "updated_at": "2026-06-06T07:50:18.000000Z",
                    "putaway_qty": 10,
                    "discrepancy_qty": 0,
                    "discrepancy_note": null,
                    "variant": {
                        "id": 2,
                        "sku": "MOUSE-001-BLK",
                        "product_id": 2
                    }
                }
            ]
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 1,
        "per_page": 5,
        "total": 1
    }
}
```

---

