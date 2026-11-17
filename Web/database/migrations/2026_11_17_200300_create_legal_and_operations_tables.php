<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normativa versionada, festivos, incidencias, vacaciones, horas extraordinarias,
 * aprobaciones multinivel, evidencias, alertas de cumplimiento, acuses, auditoría,
 * exportaciones, verificación facial e integración con People.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_versions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('verification_status', 24);
            $table->json('parameters');
            $table->json('sources');
            $table->text('notes')->nullable();
            $table->char('checksum', 64)->comment('sha256 de parameters; detecta alteraciones');
            $table->timestamps();
            $table->index(['valid_from', 'valid_to']);
        });

        Schema::create('vacation_entitlement_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->unsignedSmallInteger('from_year');
            $table->unsignedSmallInteger('to_year')->nullable();
            $table->unsignedSmallInteger('days');
            $table->string('source', 255);
            $table->timestamps();
            $table->unique(['code', 'from_year']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete()->comment('null = oficial nacional');
            $table->foreignId('work_center_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('kind', 12)->comment('official|company|local');
            $table->boolean('is_mandatory_rest')->default(true)->comment('Descanso obligatorio LFT art. 74');
            $table->string('legal_basis', 160)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'work_center_id', 'date', 'code']);
            $table->index('date');
        });

        Schema::create('approval_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 20)->comment('rectification|incidence|vacation|overtime');
            $table->json('levels')->comment('[{level:1, permission:"...", label:"..."}]');
            $table->timestamps();
            $table->unique(['organization_id', 'subject']);
        });

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->morphs('approvable');
            $table->unsignedTinyInteger('level');
            $table->string('decision', 10)->comment('approved|rejected');
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->dateTime('decided_at');
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->morphs('attachable');
            $table->string('disk', 30);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('incidence_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('category', 16)->comment('absence|leave|disability|vacation|time|other');
            $table->text('description')->nullable();
            $table->string('operational_effect', 24)->comment('paid_absence|unpaid_absence|justified|unjustified|informative|time_adjustment');
            $table->boolean('requires_evidence')->default(false);
            $table->boolean('requires_approval')->default(true);
            $table->boolean('affects_payroll')->default(false);
            $table->boolean('employee_requestable')->default(true);
            $table->boolean('is_system')->default(false);
            $table->boolean('active')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('incidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incidence_type_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('minutes')->nullable();
            $table->text('reason')->nullable();
            $table->string('source', 10)->default('employee')->comment('employee|manager|system');
            $table->string('status', 12)->default('pending');
            $table->unsignedTinyInteger('current_level')->default(1);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['employee_id', 'starts_on', 'ends_on']);
        });

        Schema::create('vacation_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('extra_days')->comment('Prestaciones superiores: [{from_year,to_year,days}] adicionales al mínimo legal');
            $table->decimal('premium_percent', 5, 2)->default(25.00);
            $table->unsignedSmallInteger('min_notice_days')->default(0);
            $table->boolean('allow_half_days')->default(false);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('vacation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('service_year');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('days');
            $table->text('comment')->nullable();
            $table->string('status', 12)->default('pending');
            $table->unsignedTinyInteger('current_level')->default(1);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['employee_id', 'starts_on']);
        });

        Schema::create('vacation_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('service_year');
            $table->smallInteger('days');
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('overtime_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->date('week_start');
            $table->unsignedInteger('recorded_excess_minutes');
            $table->unsignedInteger('authorized_minutes')->default(0);
            $table->unsignedInteger('double_minutes')->default(0);
            $table->unsignedInteger('triple_minutes')->default(0);
            $table->unsignedInteger('over_limit_minutes')->default(0)->comment('Excede límites legales: genera alerta, nunca se elimina');
            $table->string('status', 12)->default('pending');
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('authorized_at')->nullable();
            $table->text('justification')->nullable();
            $table->string('legal_version_code', 30)->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'date']);
            $table->index(['organization_id', 'week_start']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('compliance_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('rule_code', 40);
            $table->string('severity', 10)->comment('info|warning|critical');
            $table->string('message', 255);
            $table->json('data')->nullable();
            $table->string('legal_basis', 160)->nullable();
            $table->string('status', 12)->default('open')->comment('open|acknowledged|resolved');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'date', 'rule_code']);
            $table->index(['organization_id', 'status', 'severity']);
        });

        Schema::create('policy_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('title', 200);
            $table->string('version', 20);
            $table->longText('body_markdown');
            $table->char('sha256', 64);
            $table->boolean('requires_acknowledgement')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'code', 'version']);
        });

        Schema::create('document_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('policy_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('document_sha256', 64);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('hash', 64);
            $table->dateTime('acknowledged_at', 6);
            $table->unique(['policy_document_id', 'employee_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 60);
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description', 255);
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['organization_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['organization_id', 'action']);
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report', 40);
            $table->string('format', 5);
            $table->json('parameters');
            $table->string('status', 12)->default('queued');
            $table->string('path', 255)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('face_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('external_subject_id', 120);
            $table->timestamp('consent_at');
            $table->foreignId('identity_verified_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 12)->default('active');
            $table->timestamps();
            $table->unique(['employee_id', 'provider']);
        });

        Schema::create('face_verifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('result', 16);
            $table->json('provider_metadata')->nullable()->comment('Sin imágenes ni vectores');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('source', 30);
            $table->string('kind', 20)->comment('import|sync|export');
            $table->string('status', 12);
            $table->json('stats')->nullable();
            $table->json('conflicts')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (['document_acknowledgements', 'audit_logs'] as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
                DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro inmutable: {$table} no admite UPDATE'");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro inmutable: {$table} no admite DELETE'");
            }
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (['document_acknowledgements', 'audit_logs'] as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
            }
        }
        foreach ([
            'integration_runs', 'face_verifications', 'face_enrollments', 'report_exports', 'audit_logs',
            'document_acknowledgements', 'policy_documents', 'compliance_alerts', 'overtime_records',
            'vacation_adjustments', 'vacation_requests', 'vacation_policies', 'incidences', 'incidence_types',
            'attachments', 'approval_decisions', 'approval_flows', 'holidays', 'vacation_entitlement_rules', 'legal_versions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
