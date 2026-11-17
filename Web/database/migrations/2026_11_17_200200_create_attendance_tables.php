<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registro electrónico de asistencias APPEND-ONLY.
 *
 * - attendance_punches: marcajes originales. Nunca se actualizan ni eliminan.
 * - attendance_adjustments: efectos reconocidos de rectificaciones aprobadas. Append-only.
 * - Ambas tablas forman cadenas de hash por organización (sequence + previous_hash + hash).
 * - Triggers de BD bloquean UPDATE y DELETE (defensa en profundidad además del dominio).
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $immutableTables = ['attendance_punches', 'attendance_adjustments', 'document_acknowledgements', 'audit_logs', 'integrity_anchors'];

    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('device_uuid');
            $table->string('name', 120);
            $table->string('platform', 10);
            $table->string('os_version', 40)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('model', 120)->nullable();
            $table->boolean('biometric_available')->default(false);
            $table->boolean('is_corporate')->default(false);
            $table->text('secret')->comment('Secreto HMAC cifrado con APP_KEY');
            $table->string('status', 10)->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 255)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('expo_push_token', 255)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_uuid']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('punch_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('nonce', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('employment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->string('type', 12);
            $table->string('source', 16);
            $table->dateTime('server_time', 6)->comment('UTC — hora oficial de recepción');
            $table->dateTime('device_time', 6)->nullable()->comment('UTC — declarada por el dispositivo');
            $table->integer('clock_skew_seconds')->nullable();
            $table->string('timezone', 64);
            $table->date('local_date')->comment('Fecha civil en la zona del centro de trabajo');
            $table->string('auth_method', 20);
            $table->boolean('signature_valid')->default(false);
            $table->string('face_verification_id', 36)->nullable();
            $table->boolean('captured_offline')->default(false);
            $table->string('status', 10)->default('accepted');
            $table->json('anomalies');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_meters')->nullable();
            $table->dateTime('location_captured_at')->nullable();
            $table->boolean('location_mocked')->nullable();
            $table->foreignId('geofence_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('distance_meters')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->unsignedBigInteger('sequence');
            $table->char('previous_hash', 64);
            $table->char('hash', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['organization_id', 'sequence']);
            $table->unique(['employee_id', 'client_uuid']);
            $table->index(['employee_id', 'server_time']);
            $table->index(['organization_id', 'local_date']);
        });

        Schema::create('rectification_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date');
            $table->uuid('punch_uuid')->nullable();
            $table->string('kind', 24)->comment('missing_punch|wrong_type|time_clarification|other');
            $table->string('proposed_type', 12)->nullable();
            $table->dateTime('proposed_time')->nullable();
            $table->text('reason');
            $table->string('status', 12)->default('pending');
            $table->unsignedTinyInteger('current_level')->default(1);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['employee_id', 'date']);
        });

        Schema::create('attendance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('rectification_request_id')->constrained()->restrictOnDelete();
            $table->string('action', 10)->comment('add|void|replace');
            $table->uuid('target_punch_uuid')->nullable();
            $table->string('type', 12)->nullable();
            $table->dateTime('recognized_time', 6)->nullable()->comment('UTC');
            $table->date('local_date');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->unsignedBigInteger('sequence');
            $table->char('previous_hash', 64);
            $table->char('hash', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['organization_id', 'sequence']);
            $table->index(['employee_id', 'local_date']);
        });

        Schema::create('integrity_anchors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('chain', 30);
            $table->unsignedBigInteger('sequence');
            $table->char('hash', 64);
            $table->char('signature', 64)->comment('HMAC del ancla con clave de anclaje');
            $table->string('method', 30);
            $table->string('external_reference', 255)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['organization_id', 'chain', 'sequence']);
        });

        Schema::create('workday_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->foreignId('schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shift_kind', 10)->nullable();
            $table->dateTime('scheduled_start')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            $table->unsignedInteger('scheduled_minutes')->default(0);
            $table->dateTime('first_in')->nullable();
            $table->dateTime('last_out')->nullable();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->unsignedInteger('excess_minutes')->default(0);
            $table->unsignedInteger('night_minutes')->default(0);
            $table->string('status', 20);
            $table->boolean('has_rectifications')->default(false);
            $table->json('anomalies');
            $table->string('legal_version_code', 30)->nullable();
            $table->timestamp('computed_at');
            $table->timestamps();
            $table->unique(['employee_id', 'date']);
            $table->index(['organization_id', 'date', 'status']);
            $table->index(['organization_id', 'branch_id', 'date']);
        });

        $this->createImmutabilityTriggers(['attendance_punches', 'attendance_adjustments', 'integrity_anchors']);
    }

    /**
     * @param  list<string>  $tables
     */
    private function createImmutabilityTriggers(array $tables): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($tables as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
            DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro inmutable: {$table} no admite UPDATE'");
            DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro inmutable: {$table} no admite DELETE'");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach ($this->immutableTables as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
            }
        }
        Schema::dropIfExists('workday_summaries');
        Schema::dropIfExists('integrity_anchors');
        Schema::dropIfExists('attendance_adjustments');
        Schema::dropIfExists('rectification_requests');
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('punch_challenges');
        Schema::dropIfExists('devices');
    }
};
