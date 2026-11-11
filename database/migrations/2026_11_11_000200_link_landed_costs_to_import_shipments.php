<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional link from a GRN landed-cost charge to the documented import shipment
        // (an operational record of workflow purchases.shipments) it arrived with.
        Schema::table('goods_receipt_landed_costs', function (Blueprint $table) {
            $table->foreignId('import_shipment_id')->nullable()
                ->constrained('operational_registers', indexName: 'grn_landed_shipment_fk')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_landed_costs', function (Blueprint $table) {
            $table->dropForeign('grn_landed_shipment_fk');
            $table->dropColumn('import_shipment_id');
        });
    }
};
