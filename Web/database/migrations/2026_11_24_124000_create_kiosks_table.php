<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiosco de sucursal (PDF pantalla 11, punto 6): tableta fija en la sucursal para quien
 * no tiene celular. Se activa con un código de un solo uso generado en la web; el
 * colaborador se identifica con número de empleado + NIP de firma.
 *
 * Sin borrado físico: la baja es lógica (revocación + SoftDeletes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('status', 10)->default('pending')->comment('pending | active | revoked');
            $table->string('activation_code_hash', 64)->nullable()->unique()->comment('SHA-256 del código de un solo uso');
            $table->timestamp('activation_expires_at')->nullable();
            $table->string('token_hash', 64)->nullable()->unique()->comment('SHA-256 del token del kiosco');
            $table->uuid('device_uuid')->nullable();
            $table->string('platform', 10)->nullable();
            $table->string('os_version', 40)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('model', 120)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'status']);
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->foreignId('kiosk_id')->nullable()->after('device_id')->constrained('kiosks')->restrictOnDelete();
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->json('integrity_flags')->nullable()->after('biometric_available')->comment('Señales reportadas por la app: rooted, emulator, mock_location');
            $table->timestamp('integrity_reported_at')->nullable()->after('integrity_flags');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['integrity_flags', 'integrity_reported_at']);
        });
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kiosk_id');
        });
        Schema::dropIfExists('kiosks');
    }
};
