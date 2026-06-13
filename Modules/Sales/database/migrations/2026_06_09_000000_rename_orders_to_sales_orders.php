<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {

        Schema::table('order_items',     fn (Blueprint $t) => $t->dropForeign(['order_id']));
        Schema::table('picklist_items',  fn (Blueprint $t) => $t->dropForeign(['order_id']));
        Schema::table('picklist_items',  fn (Blueprint $t) => $t->dropForeign(['order_item_id']));
        Schema::table('packlist_items',  fn (Blueprint $t) => $t->dropForeign(['order_item_id']));
        Schema::table('packlists',       fn (Blueprint $t) => $t->dropForeign(['order_id']));
        Schema::table('shipment_orders', fn (Blueprint $t) => $t->dropForeign(['order_id']));
        Schema::table('sales_returns',   fn (Blueprint $t) => $t->dropForeign(['order_id']));
        Schema::table('warranties',      fn (Blueprint $t) => $t->dropForeign(['order_id']));

        Schema::rename('orders', 'sales_orders');
        Schema::rename('order_items', 'sales_order_items');

        Schema::table('sales_order_items', fn (Blueprint $t) =>
            $t->foreign('order_id')->references('id')->on('sales_orders')->cascadeOnDelete());
        Schema::table('picklist_items', function (Blueprint $t) {
            $t->foreign('order_id')->references('id')->on('sales_orders')->restrictOnDelete();
            $t->foreign('order_item_id')->references('id')->on('sales_order_items')->restrictOnDelete();
        });
        Schema::table('packlist_items',  fn (Blueprint $t) =>
            $t->foreign('order_item_id')->references('id')->on('sales_order_items')->restrictOnDelete());
        Schema::table('packlists',       fn (Blueprint $t) =>
            $t->foreign('order_id')->references('id')->on('sales_orders')->restrictOnDelete());
        Schema::table('shipment_orders', fn (Blueprint $t) =>
            $t->foreign('order_id')->references('id')->on('sales_orders')->restrictOnDelete());
        Schema::table('sales_returns',   fn (Blueprint $t) =>
            $t->foreign('order_id')->references('id')->on('sales_orders')->nullOnDelete());
        Schema::table('warranties',      fn (Blueprint $t) =>
            $t->foreign('order_id')->references('id')->on('sales_orders')->nullOnDelete());
    }

    public function down(): void
    {

        Schema::table('sales_order_items', fn (Blueprint $t) => $t->dropForeign(['order_id']));
        Schema::table('picklist_items',    fn (Blueprint $t) => $t->dropForeign(['order_id']));
        Schema::table('picklist_items',    fn (Blueprint $t) => $t->dropForeign(['order_item_id']));
        Schema::table('packlist_items',    fn (Blueprint $t) => $t->dropForeign(['order_item_id']));
        Schema::table('packlists',         fn (Blueprint $t) => $t->dropForeign(['order_id']));
        Schema::table('shipment_orders',   fn (Blueprint $t) => $t->dropForeign(['order_id']));
        Schema::table('sales_returns',     fn (Blueprint $t) => $t->dropForeign(['order_id']));
        Schema::table('warranties',        fn (Blueprint $t) => $t->dropForeign(['order_id']));

        Schema::rename('sales_orders', 'orders');
        Schema::rename('sales_order_items', 'order_items');

        Schema::table('order_items',     fn (Blueprint $t) =>
            $t->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete());
        Schema::table('picklist_items',  function (Blueprint $t) {
            $t->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            $t->foreign('order_item_id')->references('id')->on('order_items')->restrictOnDelete();
        });
        Schema::table('packlist_items',  fn (Blueprint $t) =>
            $t->foreign('order_item_id')->references('id')->on('order_items')->restrictOnDelete());
        Schema::table('packlists',       fn (Blueprint $t) =>
            $t->foreign('order_id')->references('id')->on('orders')->restrictOnDelete());
        Schema::table('shipment_orders', fn (Blueprint $t) =>
            $t->foreign('order_id')->references('id')->on('orders')->restrictOnDelete());
        Schema::table('sales_returns',   fn (Blueprint $t) =>
            $t->foreign('order_id')->references('id')->on('orders')->nullOnDelete());
        Schema::table('warranties',      fn (Blueprint $t) =>
            $t->foreign('order_id')->references('id')->on('orders')->nullOnDelete());
    }
};
