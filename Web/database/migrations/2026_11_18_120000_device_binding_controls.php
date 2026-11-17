<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control de dispositivos contra préstamo de credenciales:
 *  - `hardware_id`: identificador del teléfono (Android ID por firma de app). Un mismo
 *    teléfono no puede quedar vinculado a dos colaboradores.
 *  - `pending_reason`: por qué un dispositivo espera autorización (p. ej. cambio de teléfono).
 *  - `replaced_by_device_id`: trazabilidad del cambio de teléfono.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('hardware_id', 128)->nullable()->after('device_uuid');
            $table->string('pending_reason', 30)->nullable()->after('status');
            $table->foreignId('replaced_by_device_id')->nullable()->after('revoke_reason')->constrained('devices')->nullOnDelete();
            $table->index('hardware_id');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaced_by_device_id');
            $table->dropIndex(['hardware_id']);
            $table->dropColumn(['hardware_id', 'pending_reason']);
        });
    }
};
