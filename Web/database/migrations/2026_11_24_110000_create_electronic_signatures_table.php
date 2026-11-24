<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Firma electrónica del colaborador (append-only, encadenada con HashChain) y
 * NIP de firma en `users`. La firma acredita la conformidad del colaborador
 * (LFT art. 132 fr. XXXIV): convenio de registro, aviso de privacidad y cada
 * rectificación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electronic_signatures', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('signable_type', 120);
            $table->unsignedBigInteger('signable_id');
            $table->string('purpose', 30)->comment('rectification|registration_agreement|privacy_notice');
            $table->string('method', 12)->comment('pin|biometric');
            $table->text('statement')->comment('Texto exacto que se firmó');
            $table->char('payload_sha256', 64);
            $table->foreignId('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('signed_at', 6)->comment('UTC');
            $table->unsignedBigInteger('sequence');
            $table->char('previous_hash', 64);
            $table->char('hash', 64);
            $table->unique(['organization_id', 'sequence']);
            $table->index(['signable_type', 'signable_id']);
            $table->index(['employee_id', 'purpose']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('signature_pin_hash', 255)->nullable()->after('password');
            $table->dateTime('signature_pin_set_at')->nullable()->after('signature_pin_hash');
            $table->unsignedTinyInteger('signature_pin_failed_attempts')->default(0)->after('signature_pin_set_at');
            $table->dateTime('signature_pin_locked_until')->nullable()->after('signature_pin_failed_attempts');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS electronic_signatures_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS electronic_signatures_no_delete');
            DB::unprepared("CREATE TRIGGER electronic_signatures_no_update BEFORE UPDATE ON electronic_signatures FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro inmutable: electronic_signatures no admite UPDATE'");
            DB::unprepared("CREATE TRIGGER electronic_signatures_no_delete BEFORE DELETE ON electronic_signatures FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro inmutable: electronic_signatures no admite DELETE'");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS electronic_signatures_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS electronic_signatures_no_delete');
        }
        Schema::dropIfExists('electronic_signatures');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['signature_pin_hash', 'signature_pin_set_at', 'signature_pin_failed_attempts', 'signature_pin_locked_until']);
        });
    }
};
