<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verificación facial open source (YuNet + SFace + MiniFASNet).
 *
 * - `face_enrollments.template`: plantilla SFace (128 float32) CIFRADA con APP_KEY
 *   (cast `encrypted`). Dato biométrico sensible: nunca se guarda la imagen.
 * - Sin borrado físico: revocar = status `revoked`. La plantilla sólo se vacía
 *   (template = null) por "revocación por derecho de cancelación" (ARCO), auditada.
 * - Se permite re-enrolar: se elimina el único (employee_id, provider) para
 *   conservar el histórico; un solo enrolamiento activo se garantiza en dominio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('face_enrollments', function (Blueprint $table) {
            $table->index(['employee_id', 'status'], 'face_enrollments_employee_status_index');
        });

        Schema::table('face_enrollments', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'provider']);
            $table->text('template')->nullable()->after('external_subject_id')->comment('Cifrado (cast encrypted)');
            $table->string('template_version', 40)->nullable()->after('template');
            $table->json('quality')->nullable()->after('template_version')->comment('Métricas de calidad; sin imágenes');
            $table->foreignId('consent_document_id')->nullable()->after('consent_at')->constrained('policy_documents')->restrictOnDelete();
            $table->char('consent_document_sha256', 64)->nullable()->after('consent_document_id');
            $table->timestamp('revoked_at')->nullable()->after('status');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users')->restrictOnDelete();
            $table->string('revocation_reason', 255)->nullable()->after('revoked_by');
            $table->timestamp('template_erased_at')->nullable()->after('revocation_reason');
        });

        Schema::create('face_challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->json('steps');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'created_at']);
        });

        Schema::table('face_verifications', function (Blueprint $table) {
            $table->foreignId('face_challenge_id')->nullable()->after('employee_id')->constrained('face_challenges')->restrictOnDelete();
            $table->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('face_verifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('face_challenge_id');
            $table->dropIndex(['employee_id', 'created_at']);
        });

        Schema::dropIfExists('face_challenges');

        Schema::table('face_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consent_document_id');
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['template', 'template_version', 'quality', 'consent_document_sha256', 'revoked_at', 'revocation_reason', 'template_erased_at']);
            $table->unique(['employee_id', 'provider']);
        });

        Schema::table('face_enrollments', function (Blueprint $table) {
            $table->dropIndex('face_enrollments_employee_status_index');
        });
    }
};
