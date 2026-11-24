<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Festivos administrables por RH preservando los obligatorios (Revisión RH, pantalla 08).
 *
 * Los días de descanso obligatorio precargados (LFT art. 74, `organization_id` nulo) son
 * globales y nunca se eliminan ni desactivan. Para anotarlos, renombrarlos o registrar
 * el día movido por la ley, la organización guarda un AJUSTE propio que sustituye al
 * global sólo para ella (`replaces_holiday_id`), con motivo y bitácora.
 *
 * Compatible con los datos existentes: columnas nuevas nulas; ningún registro cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->foreignId('replaces_holiday_id')->nullable()->after('work_center_id')
                ->comment('Ajuste de la organización a un día oficial global (art. 74)')
                ->constrained('holidays')->restrictOnDelete();
            $table->string('change_reason', 255)->nullable()->after('legal_basis')->comment('Motivo del último cambio (obligatorio en días de descanso obligatorio)');
            $table->string('notes', 255)->nullable()->after('change_reason')->comment('Anotación de RH visible en Calendario');
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            // Un solo ajuste por organización y día oficial.
            $table->unique(['organization_id', 'replaces_holiday_id'], 'holidays_org_replaces_unique');
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropUnique('holidays_org_replaces_unique');
            $table->dropConstrainedForeignId('replaces_holiday_id');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['change_reason', 'notes']);
        });
    }
};
