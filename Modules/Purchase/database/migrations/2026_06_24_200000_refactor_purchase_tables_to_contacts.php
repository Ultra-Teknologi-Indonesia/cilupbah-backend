<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- Migrate referenced suppliers into contacts table ---
        $poColumn = Schema::hasColumn('purchase_orders', 'contact_id') ? 'contact_id' : 'supplier_id';
        $pbColumn = Schema::hasColumn('purchase_bills', 'contact_id') ? 'contact_id' : 'supplier_id';

        $referencedSupplierIds = DB::table('purchase_orders')->pluck($poColumn)
            ->merge(DB::table('purchase_bills')->pluck($pbColumn))
            ->unique()
            ->filter();

        $existingContactIds = DB::table('contacts')->pluck('id');
        $missingIds = $referencedSupplierIds->diff($existingContactIds);

        if ($missingIds->isNotEmpty()) {
            $suppliers = DB::table('suppliers')->whereIn('id', $missingIds)->get();
            foreach ($suppliers as $supplier) {
                $paymentTerm = preg_match('/(\d+)/', $supplier->payment_term ?? '', $m) ? (int) $m[1] : null;

                DB::table('contacts')->insert([
                    'id' => $supplier->id,
                    'code' => $supplier->code,
                    'name' => $supplier->name,
                    'company_name' => $supplier->company_name,
                    'email' => $supplier->email,
                    'phone' => $supplier->phone,
                    'address' => $supplier->address,
                    'city' => $supplier->city,
                    'tax_id' => $supplier->tax_id,
                    'contact_person' => $supplier->contact_person,
                    'payment_term' => $paymentTerm,
                    'notes' => $supplier->notes,
                    'status' => $supplier->status,
                    'type' => 'SUPPLIER',
                    'created_at' => $supplier->created_at,
                    'updated_at' => $supplier->updated_at,
                ]);
            }
        }

        // --- purchase_orders: supplier_id → contact_id + new fields ---
        if (Schema::hasColumn('purchase_orders', 'supplier_id')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->dropForeign(['supplier_id']);
            });
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->renameColumn('supplier_id', 'contact_id');
            });
        }

        if (! Schema::hasColumn('purchase_orders', 'ref_no')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                if (! $this->hasForeignKey('purchase_orders', 'purchase_orders_contact_id_foreign')) {
                    $table->foreign('contact_id')->references('id')->on('contacts')->restrictOnDelete();
                }
                $table->string('ref_no', 100)->nullable()->after('po_number');
                $table->decimal('sub_total', 15, 2)->default(0)->after('total_amount');
                $table->decimal('total_disc', 15, 2)->default(0)->after('sub_total');
                $table->decimal('total_tax', 15, 2)->default(0)->after('total_disc');
                $table->boolean('is_tax_included')->default(false)->after('total_tax');
            });
        }

        $poPaymentType = DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_name = 'purchase_orders' AND column_name = 'payment_term'");
        if ($poPaymentType && $poPaymentType->data_type !== 'integer') {
            DB::statement("ALTER TABLE purchase_orders ALTER COLUMN payment_term DROP DEFAULT");
            DB::statement("UPDATE purchase_orders SET payment_term = NULL WHERE payment_term IS NULL OR payment_term !~ '^[0-9]+$'");
            DB::statement("ALTER TABLE purchase_orders ALTER COLUMN payment_term TYPE integer USING payment_term::integer");
        }

        // --- purchase_order_items: add disc/tax/unit fields ---
        if (! Schema::hasColumn('purchase_order_items', 'description')) {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                $table->text('description')->nullable()->after('item_id');
                $table->string('unit', 30)->nullable()->after('description');
                $table->decimal('disc', 5, 2)->default(0)->after('unit_price');
                $table->decimal('disc_amount', 15, 2)->default(0)->after('disc');
                $table->foreignId('tax_id')->nullable()->constrained('taxes')->nullOnDelete()->after('disc_amount');
                $table->decimal('tax_amount', 15, 2)->default(0)->after('tax_id');
            });
        }

        if (Schema::hasColumn('purchase_order_items', 'subtotal')) {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                $table->renameColumn('subtotal', 'amount');
            });
        }

        // --- purchase_bills: supplier_id → contact_id + new fields ---
        if (Schema::hasColumn('purchase_bills', 'supplier_id')) {
            Schema::table('purchase_bills', function (Blueprint $table) {
                $table->dropForeign(['supplier_id']);
            });
            Schema::table('purchase_bills', function (Blueprint $table) {
                $table->renameColumn('supplier_id', 'contact_id');
            });
        }

        if (! Schema::hasColumn('purchase_bills', 'ref_no')) {
            Schema::table('purchase_bills', function (Blueprint $table) {
                if (! $this->hasForeignKey('purchase_bills', 'purchase_bills_contact_id_foreign')) {
                    $table->foreign('contact_id')->references('id')->on('contacts')->restrictOnDelete();
                }
                $table->string('ref_no', 100)->nullable()->after('bill_number');
                $table->integer('payment_term')->nullable()->after('due_date');
                $table->decimal('sub_total', 15, 2)->default(0)->after('total_amount');
                $table->decimal('total_disc', 15, 2)->default(0)->after('sub_total');
                $table->decimal('total_tax', 15, 2)->default(0)->after('total_disc');
                $table->boolean('is_tax_included')->default(false)->after('total_tax');
                $table->string('tag', 100)->nullable()->after('is_tax_included');
            });
        }

        // --- purchase_bill_items: add po_item link, disc/tax/unit fields ---
        if (! Schema::hasColumn('purchase_bill_items', 'description')) {
            Schema::table('purchase_bill_items', function (Blueprint $table) {
                $table->foreignUuid('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete()->after('purchase_bill_id');
                $table->text('description')->nullable()->after('item_id');
                $table->string('unit', 30)->nullable()->after('description');
                $table->decimal('disc', 5, 2)->default(0)->after('unit_price');
                $table->decimal('disc_amount', 15, 2)->default(0)->after('disc');
                $table->foreignId('tax_id')->nullable()->constrained('taxes')->nullOnDelete()->after('disc_amount');
                $table->decimal('tax_amount', 15, 2)->default(0)->after('tax_id');
            });
        }

        if (Schema::hasColumn('purchase_bill_items', 'subtotal')) {
            Schema::table('purchase_bill_items', function (Blueprint $table) {
                $table->renameColumn('subtotal', 'amount');
            });
        }
    }

    private function hasForeignKey(string $table, string $keyName): bool
    {
        return (bool) DB::selectOne(
            "SELECT 1 FROM information_schema.table_constraints WHERE table_name = ? AND constraint_name = ? AND constraint_type = 'FOREIGN KEY'",
            [$table, $keyName]
        );
    }

    public function down(): void
    {
        // purchase_bill_items
        Schema::table('purchase_bill_items', function (Blueprint $table) {
            $table->renameColumn('amount', 'subtotal');
            $table->dropForeign(['tax_id']);
            $table->dropForeign(['purchase_order_item_id']);
            $table->dropColumn(['purchase_order_item_id', 'description', 'unit', 'disc', 'disc_amount', 'tax_id', 'tax_amount']);
        });

        // purchase_bills
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
            $table->dropColumn(['ref_no', 'payment_term', 'sub_total', 'total_disc', 'total_tax', 'is_tax_included', 'tag']);
        });
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->renameColumn('contact_id', 'supplier_id');
        });
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
        });

        // purchase_order_items
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->renameColumn('amount', 'subtotal');
            $table->dropForeign(['tax_id']);
            $table->dropColumn(['description', 'unit', 'disc', 'disc_amount', 'tax_id', 'tax_amount']);
        });

        // purchase_orders
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
            $table->dropColumn(['ref_no', 'sub_total', 'total_disc', 'total_tax', 'is_tax_included']);
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->renameColumn('contact_id', 'supplier_id');
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE purchase_orders ALTER COLUMN payment_term TYPE varchar(50) USING payment_term::text");
    }
};
