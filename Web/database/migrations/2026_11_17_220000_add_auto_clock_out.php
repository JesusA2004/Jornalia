<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre automático de jornada (hora máxima de salida).
 *
 * Prioridad de configuración: persona (asignación vigente) > puesto > política de asistencia.
 * El sistema registra una salida identificada como automática; el tiempo posterior al
 * cierre se conserva aparte y no se reconoce como tiempo extraordinario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_policies', function (Blueprint $table) {
            $table->time('auto_clock_out_at')->nullable()->after('web_punch_allowed');
        });
        Schema::table('positions', function (Blueprint $table) {
            $table->time('auto_clock_out_at')->nullable()->after('code');
        });
        Schema::table('employment_assignments', function (Blueprint $table) {
            $table->time('auto_clock_out_at')->nullable()->after('attendance_policy_id')
                ->comment('Hora máxima de salida específica de la persona');
        });
        Schema::table('workday_summaries', function (Blueprint $table) {
            $table->dateTime('auto_clock_out_at')->nullable()->after('last_out');
            $table->unsignedInteger('after_cutoff_minutes')->default(0)->after('excess_minutes')
                ->comment('Tiempo registrado después del cierre automático: se conserva, no se reconoce como extra');
        });
    }

    public function down(): void
    {
        Schema::table('workday_summaries', fn (Blueprint $t) => $t->dropColumn(['auto_clock_out_at', 'after_cutoff_minutes']));
        Schema::table('employment_assignments', fn (Blueprint $t) => $t->dropColumn('auto_clock_out_at'));
        Schema::table('positions', fn (Blueprint $t) => $t->dropColumn('auto_clock_out_at'));
        Schema::table('attendance_policies', fn (Blueprint $t) => $t->dropColumn('auto_clock_out_at'));
    }
};
