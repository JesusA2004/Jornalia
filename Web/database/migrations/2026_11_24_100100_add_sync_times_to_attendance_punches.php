<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hora oficial del registro (Revisión RH, regla general 3): además de la hora del
 * dispositivo (`device_time`) y la del servidor (`server_time`), se guardan:
 *  - `received_at`: recepción en servidor (UTC, America/Mexico_City para mostrar).
 *  - `synced_at`: hora en que el dispositivo envió/sincronizó el marcaje según su
 *    propio reloj (permite distinguir sincronización tardía de reloj alterado).
 *
 * Aditiva y compatible: la tabla es append-only (triggers bloquean UPDATE), por lo
 * que las columnas se llenan sólo al INSERT; los registros previos quedan en null
 * y se interpretan con `server_time`. No forman parte del sello hash existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dateTime('received_at', 6)->nullable()->after('device_time')
                ->comment('UTC — recepción en servidor');
            $table->dateTime('synced_at', 6)->nullable()->after('received_at')
                ->comment('UTC — hora de envío/sincronización declarada por el dispositivo');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropColumn(['received_at', 'synced_at']);
        });
    }
};
