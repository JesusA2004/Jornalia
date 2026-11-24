<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora inalterable (PDF regla 5): valor anterior, valor nuevo y dispositivo.
 * Los triggers de inmutabilidad existentes siguen vigentes (sólo se agregan columnas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->json('old_values')->nullable()->after('context');
            $table->json('new_values')->nullable()->after('old_values');
            $table->unsignedBigInteger('device_id')->nullable()->after('user_agent')->comment('Dispositivo de la app, si la acción vino de la API');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['old_values', 'new_values', 'device_id']);
        });
    }
};
