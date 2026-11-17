<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colaboradores, relaciones laborales (altas/bajas/reingresos), horarios,
 * asignaciones con vigencia y políticas de asistencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 40);
            $table->string('type', 20)->default('fixed')->comment('fixed|rotating|night|mixed|flexible|split|cycle|on_call|variable');
            $table->string('shift_kind', 10)->default('day')->comment('day|night|mixed');
            $table->unsignedSmallInteger('cycle_length_days')->default(7);
            $table->date('cycle_anchor_date')->nullable()->comment('Día índice 0 de ciclos rotativos');
            $table->unsignedSmallInteger('tolerance_minutes')->default(10);
            $table->unsignedSmallInteger('flex_minutes')->default(0)->comment('Ventana de entrada flexible');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('schedule_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('day_index')->comment('0..cycle_length-1; en semanal 0=lunes');
            $table->unsignedTinyInteger('ordinal')->default(1)->comment('Para horarios partidos');
            $table->time('starts_at');
            $table->time('ends_at')->comment('Si es menor que starts_at, cruza medianoche');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->timestamps();
            $table->unique(['schedule_id', 'day_index', 'ordinal']);
        });

        Schema::create('attendance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('location_mode', 20)->default('record');
            $table->json('allowed_auth_methods');
            $table->boolean('require_strong_biometric')->default(false);
            $table->boolean('allow_offline')->default(true);
            $table->unsignedSmallInteger('offline_max_hours')->default(72);
            $table->boolean('face_verification_required')->default(false);
            $table->boolean('web_punch_allowed')->default(false);
            $table->unsignedSmallInteger('max_clock_skew_seconds')->default(300);
            $table->unsignedSmallInteger('min_location_accuracy_meters')->default(150);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('geofences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_meters');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('attendance_policy_geofence', function (Blueprint $table) {
            $table->foreignId('attendance_policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('geofence_id')->constrained()->cascadeOnDelete();
            $table->primary(['attendance_policy_id', 'geofence_id']);
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('employee_number', 40);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('second_last_name', 100)->nullable();
            $table->text('curp')->nullable()->comment('Cifrado');
            $table->text('rfc')->nullable()->comment('Cifrado');
            $table->text('nss')->nullable()->comment('Cifrado');
            $table->date('birth_date')->nullable()->comment('Sólo para reglas de menores de edad');
            $table->string('email', 160)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('status', 20)->default('active')->comment('active|inactive|terminated');
            $table->string('external_source', 40)->nullable()->comment('p. ej. people');
            $table->string('external_id', 80)->nullable();
            $table->timestamp('external_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'employee_number']);
            $table->unique(['organization_id', 'external_source', 'external_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'last_name', 'first_name']);
        });

        Schema::create('employments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->nullable()->constrained()->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('seniority_starts_on')->comment('Base para antigüedad/vacaciones');
            $table->string('contract_type', 20)->default('indefinite')->comment('indefinite|fixed_term|seasonal|trial|training');
            $table->string('work_modality', 20)->default('onsite')->comment('onsite|remote|hybrid|field');
            $table->string('termination_reason', 255)->nullable();
            $table->string('status', 20)->default('active')->comment('active|terminated');
            $table->timestamps();
            $table->index(['employee_id', 'starts_on']);
        });

        Schema::create('employment_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employment_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('work_center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supervisor_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attendance_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employment_id', 'effective_from']);
            $table->index(['organization_id', 'branch_id']);
        });

        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->boolean('is_rest_day')->default(false);
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->string('reason', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_exceptions');
        Schema::dropIfExists('employment_assignments');
        Schema::dropIfExists('employments');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('attendance_policy_geofence');
        Schema::dropIfExists('geofences');
        Schema::dropIfExists('attendance_policies');
        Schema::dropIfExists('schedule_segments');
        Schema::dropIfExists('schedules');
    }
};
