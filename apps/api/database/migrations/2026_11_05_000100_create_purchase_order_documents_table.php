<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Papers kept with a supplier purchase order (spec, E12: "admin records
     * Alibaba order number, amount, currency, payment date and documents"):
     * the Alibaba invoice, payment receipts, shipping papers. Files live on
     * the private documents disk and are read through signed links.
     */
    public function up(): void
    {
        Schema::create('purchase_order_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('supplier_purchase_order_id')->constrained()->restrictOnDelete();
            $table->string('kind', 24);
            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('file_name', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['supplier_purchase_order_id', 'id']);
        });

        DB::statement("ALTER TABLE purchase_order_documents ADD CONSTRAINT purchase_order_documents_kind_check CHECK (kind IN ('alibaba_invoice', 'payment_receipt', 'shipping_document', 'customs_document', 'other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_documents');
    }
};
