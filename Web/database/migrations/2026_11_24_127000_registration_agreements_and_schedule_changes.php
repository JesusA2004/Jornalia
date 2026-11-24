<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PDF RH pantallas 09 y 10:
 *  - (El convenio firmado en papel usa `attachments.category = registration_agreement`,
 *    columna creada por la migración 2026_11_24_110100 de A2.)
 *  - `schedule_exceptions` → «Cambios de horario programados»: motivo de catálogo,
 *    autorización de RH, notificación y rechazo. Los registros existentes quedan
 *    «approved» (ya surtían efecto). Se reemplaza el único (colaborador, fecha) por un
 *    índice simple para conservar el historial de solicitudes rechazadas o anuladas;
 *    la unicidad de la solicitud vigente se valida en el dominio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_exceptions', function (Blueprint $table) {
            $table->string('status', 20)->default('approved')->after('reason');
            $table->string('reason_code', 40)->nullable()->after('status');
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->string('rejection_reason', 255)->nullable()->after('rejected_at');
            $table->timestamp('notified_at')->nullable()->after('rejection_reason');
            $table->index(['employee_id', 'date', 'status'], 'schedule_exceptions_employee_date_status_idx');
        });

        Schema::table('schedule_exceptions', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'date']);
        });

        DB::table('schedule_exceptions')->whereNull('approved_at')->update(['approved_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('schedule_exceptions', function (Blueprint $table) {
            $table->unique(['employee_id', 'date']);
        });
        Schema::table('schedule_exceptions', function (Blueprint $table) {
            $table->dropIndex('schedule_exceptions_employee_date_status_idx');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['status', 'reason_code', 'approved_at', 'rejected_at', 'rejection_reason', 'notified_at']);
        });
    }
};
