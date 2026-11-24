<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisión RH (23-11-2026): se elimina por completo la salida automática.
 *
 * - Se retiran las columnas de configuración (política, puesto, persona) y los
 *   campos derivados de la proyección diaria (hora de corte y tiempo «después
 *   del cierre»). Las proyecciones se recalculan sin corte: todo el tiempo
 *   registrado cuenta.
 * - Los marcajes históricos con `source = system` en `attendance_punches` NO se
 *   tocan (tabla append-only): permanecen identificados por su origen y ya no se
 *   reconocen como marcaje del colaborador.
 * - `down()` restaura las columnas (vacías) para poder revertir el esquema.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['attendance_policies', 'positions', 'employment_assignments'] as $table) {
            if (Schema::hasColumn($table, 'auto_clock_out_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('auto_clock_out_at'));
            }
        }

        $columns = array_values(array_filter(
            ['auto_clock_out_at', 'after_cutoff_minutes'],
            fn (string $c) => Schema::hasColumn('workday_summaries', $c),
        ));
        if ($columns !== []) {
            Schema::table('workday_summaries', fn (Blueprint $t) => $t->dropColumn($columns));
        }
    }

    public function down(): void
    {
        Schema::table('attendance_policies', function (Blueprint $table) {
            $table->time('auto_clock_out_at')->nullable()->after('web_punch_allowed');
        });
        Schema::table('positions', function (Blueprint $table) {
            $table->time('auto_clock_out_at')->nullable()->after('code');
        });
        Schema::table('employment_assignments', function (Blueprint $table) {
            $table->time('auto_clock_out_at')->nullable()->after('attendance_policy_id');
        });
        Schema::table('workday_summaries', function (Blueprint $table) {
            $table->dateTime('auto_clock_out_at')->nullable()->after('last_out');
            $table->unsignedInteger('after_cutoff_minutes')->default(0)->after('excess_minutes');
        });
    }
};
