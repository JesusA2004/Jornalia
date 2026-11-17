<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. Ubicación de trabajo en tiempo real (append-only).
 * 2. Baja lógica en todos los catálogos: en este sistema nada se elimina físicamente.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $softDeletable = [
        'organizations', 'legal_entities', 'work_centers', 'branches', 'departments', 'positions',
        'users', 'employees', 'devices', 'schedules', 'schedule_segments', 'schedule_exceptions',
        'attendance_policies', 'geofences', 'incidence_types', 'vacation_policies', 'holidays',
        'policy_documents', 'approval_flows', 'roles',
    ];

    public function up(): void
    {
        Schema::create('location_points', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->decimal('heading', 6, 2)->nullable();
            $table->decimal('speed_mps', 7, 2)->nullable();
            $table->decimal('altitude_m', 8, 2)->nullable();
            $table->boolean('is_mocked')->nullable();
            $table->string('app_state', 12);
            $table->dateTime('captured_at', 6)->comment('UTC, declarada por el dispositivo');
            $table->dateTime('received_at', 6)->comment('UTC, recepción en servidor');
            $table->unique(['user_id', 'uuid']);
            $table->index(['employee_id', 'captured_at']);
            $table->index(['organization_id', 'received_at']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dateTime('last_location_at')->nullable()->after('status');
        });

        foreach ($this->softDeletable as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'deleted_at')) {
                Schema::table($name, fn (Blueprint $table) => $table->softDeletes());
            }
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS location_points_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS location_points_no_delete');
            DB::unprepared("CREATE TRIGGER location_points_no_update BEFORE UPDATE ON location_points FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro inmutable: location_points no admite UPDATE'");
            DB::unprepared("CREATE TRIGGER location_points_no_delete BEFORE DELETE ON location_points FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro inmutable: location_points no admite DELETE'");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS location_points_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS location_points_no_delete');
        }
        foreach ($this->softDeletable as $name) {
            if (Schema::hasColumn($name, 'deleted_at')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropSoftDeletes());
            }
        }
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('last_location_at'));
        Schema::dropIfExists('location_points');
    }
};
