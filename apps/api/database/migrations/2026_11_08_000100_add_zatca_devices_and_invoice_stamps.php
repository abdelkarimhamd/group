<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ZATCA e-invoicing phase 2.
     *
     * - `zatca_devices`: our EGS units. Each has its own key, the CSIDs
     *   (certificates) ZATCA issued to it, and its invoice chain: the
     *   counter (ICV) and the hash of the last document signed (the next
     *   one's PIH). One is active per environment; onboarding again makes
     *   a new unit with a new chain.
     * - `zatca_invoice_stamps`: an invoice's signed XML, hash, QR and
     *   reporting. Opened with the invoice while phase 2 is on, signed
     *   after the commit; once signed, its place in the chain and its XML
     *   never change.
     */
    public function up(): void
    {
        Schema::create('zatca_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('environment', 20);
            $table->string('status', 16)->default('onboarding');
            // "1-solution|2-model|3-uuid", the unit's serial number in the CSR.
            $table->string('egs_serial', 200);
            $table->string('common_name', 64);
            // Encrypted with APP_KEY.
            $table->text('private_key');
            $table->text('csr');
            $table->string('compliance_request_id', 64)->nullable();
            $table->text('compliance_certificate')->nullable();
            $table->text('compliance_secret')->nullable();
            $table->text('certificate')->nullable();
            $table->text('secret')->nullable();
            $table->timestampTz('certificate_expires_at')->nullable();
            $table->unsignedBigInteger('icv')->default(0);
            // Before the first document: base64 of the hex SHA-256 of "0" (ZatcaSigner::FIRST_PREVIOUS_HASH).
            $table->string('last_hash', 100)->default('NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==');
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE zatca_devices ADD CONSTRAINT zatca_devices_environment_check CHECK (environment IN ('developer-portal', 'simulation', 'core'))");
        DB::statement("ALTER TABLE zatca_devices ADD CONSTRAINT zatca_devices_status_check CHECK (status IN ('onboarding', 'active', 'retired'))");
        DB::statement("ALTER TABLE zatca_devices ADD CONSTRAINT zatca_devices_active_check CHECK (status <> 'active' OR (certificate IS NOT NULL AND secret IS NOT NULL AND activated_at IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX zatca_devices_one_active ON zatca_devices (environment) WHERE status = 'active'");

        Schema::create('zatca_invoice_stamps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('zatca_device_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('icv')->nullable();
            $table->string('previous_hash', 100)->nullable();
            $table->string('invoice_hash', 100)->nullable();
            $table->text('signed_xml')->nullable();
            $table->text('qr')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('reported_at')->nullable();
            // ZATCA's last answer: its HTTP status and body (warnings, errors).
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestampsTz();

            $table->unique(['zatca_device_id', 'icv']);
            $table->index(['reported_at', 'id']);
        });
        DB::statement('ALTER TABLE zatca_invoice_stamps ADD CONSTRAINT zatca_invoice_stamps_signed_check CHECK (
            (signed_at IS NULL) = (zatca_device_id IS NULL) AND (signed_at IS NULL) = (icv IS NULL) AND (signed_at IS NULL) = (previous_hash IS NULL)
            AND (signed_at IS NULL) = (invoice_hash IS NULL) AND (signed_at IS NULL) = (signed_xml IS NULL) AND (signed_at IS NULL) = (qr IS NULL)
            AND (reported_at IS NULL OR signed_at IS NOT NULL))');
        // A signed document is part of its unit's chain: it never changes.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION zatca_invoice_stamps_keep_signed() RETURNS trigger AS $$
            BEGIN
                IF OLD.signed_at IS NOT NULL AND (NEW.invoice_id, NEW.zatca_device_id, NEW.icv, NEW.previous_hash, NEW.invoice_hash, NEW.signed_xml, NEW.qr, NEW.signed_at)
                   IS DISTINCT FROM (OLD.invoice_id, OLD.zatca_device_id, OLD.icv, OLD.previous_hash, OLD.invoice_hash, OLD.signed_xml, OLD.qr, OLD.signed_at) THEN
                    RAISE EXCEPTION 'A signed e-invoice cannot change.' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement('CREATE TRIGGER zatca_invoice_stamps_keep_signed BEFORE UPDATE ON zatca_invoice_stamps FOR EACH ROW EXECUTE FUNCTION zatca_invoice_stamps_keep_signed()');
        DB::statement('CREATE TRIGGER zatca_invoice_stamps_no_delete BEFORE DELETE ON zatca_invoice_stamps FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_invoice_stamps');
        DB::statement('DROP FUNCTION IF EXISTS zatca_invoice_stamps_keep_signed()');
        Schema::dropIfExists('zatca_devices');
    }
};
