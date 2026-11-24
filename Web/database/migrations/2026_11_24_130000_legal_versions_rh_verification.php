<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normativa editable sólo por RH con verificación real (PDF pantallas 14–16 y 19):
 *  - `legal_versions` registra quién y cuándo la verificó (nunca se precarga como verificada).
 *  - `legal_version_revisions`: historial append-only de cada cambio (antes/después) y verificación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_versions', function (Blueprint $table) {
            $table->foreignId('verified_by')->nullable()->after('verification_status')->constrained('users')->restrictOnDelete();
            $table->dateTime('verified_at')->nullable()->after('verified_by')->comment('UTC; fecha real de validación por RH');
            $table->foreignId('updated_by')->nullable()->after('checksum')->constrained('users')->restrictOnDelete();
        });

        // Ninguna versión puede quedar «verificada» sin un usuario y una fecha real de validación.
        DB::table('legal_versions')->whereNull('verified_by')->update(['verification_status' => 'unverified']);

        Schema::create('legal_version_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 20)->comment('updated|verified|unverified');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->char('before_checksum', 64)->nullable();
            $table->char('after_checksum', 64)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['legal_version_id', 'created_at']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS legal_version_revisions_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS legal_version_revisions_no_delete');
            DB::unprepared("CREATE TRIGGER legal_version_revisions_no_update BEFORE UPDATE ON legal_version_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro inmutable: legal_version_revisions no admite UPDATE'");
            DB::unprepared("CREATE TRIGGER legal_version_revisions_no_delete BEFORE DELETE ON legal_version_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro inmutable: legal_version_revisions no admite DELETE'");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS legal_version_revisions_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS legal_version_revisions_no_delete');
        }
        Schema::dropIfExists('legal_version_revisions');
        Schema::table('legal_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn('verified_at');
        });
    }
};
