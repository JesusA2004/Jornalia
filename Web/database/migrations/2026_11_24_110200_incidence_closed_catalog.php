<?php

use App\Domain\Incidences\IncidenceCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incidencias (PDF pantalla 06): catálogo cerrado con evidencia y autorización
 * por tipo, folio IMSS, cancelación con motivo (sin borrado) e identificadores
 * externos para recibir incidencias aprobadas desde Mr. Lana People.
 *
 * Ajusta el catálogo de organizaciones existentes de forma idempotente: crea o
 * actualiza los tipos del catálogo y DESACTIVA (no borra) los que quedan fuera,
 * conservando todas las incidencias históricas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidence_types', function (Blueprint $table) {
            $table->string('evidence_kind', 24)->nullable()->after('requires_evidence')
                ->comment('imss_certificate|authorized_form|death_certificate|proof');
            $table->boolean('requires_folio')->default(false)->after('evidence_kind')->comment('Folio IMSS de la incapacidad');
            $table->string('approval_route', 12)->default('manager_hr')->after('requires_approval')->comment('manager|hr|manager_hr');
            $table->string('omission_punch_type', 12)->nullable()->after('approval_route')
                ->comment('clock_in|clock_out: la omisión genera una rectificación para firma');
            $table->string('external_code', 40)->nullable()->after('code')->comment('Código del tipo en Mr. Lana People');
        });

        Schema::table('incidences', function (Blueprint $table) {
            $table->string('folio', 40)->nullable()->after('reason');
            $table->foreignId('rectification_request_id')->nullable()->after('folio')->constrained('rectification_requests')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->after('review_comment')->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable()->after('cancelled_by');
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');
            $table->string('external_source', 20)->nullable()->after('source');
            $table->string('external_id', 80)->nullable()->after('external_source');
            $table->dateTime('external_synced_at')->nullable()->after('external_id');
            $table->unique(['organization_id', 'external_source', 'external_id'], 'incidences_external_unique');
        });

        Schema::table('vacation_requests', function (Blueprint $table) {
            $table->string('external_source', 20)->nullable()->after('comment');
            $table->string('external_id', 80)->nullable()->after('external_source');
            $table->dateTime('external_synced_at')->nullable()->after('external_id');
            $table->unique(['organization_id', 'external_source', 'external_id'], 'vacation_requests_external_unique');
        });

        IncidenceCatalog::syncAllOrganizations();
    }

    public function down(): void
    {
        Schema::table('vacation_requests', function (Blueprint $table) {
            $table->dropUnique('vacation_requests_external_unique');
            $table->dropColumn(['external_source', 'external_id', 'external_synced_at']);
        });
        Schema::table('incidences', function (Blueprint $table) {
            $table->dropUnique('incidences_external_unique');
            $table->dropConstrainedForeignId('rectification_request_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['folio', 'cancelled_at', 'cancellation_reason', 'external_source', 'external_id', 'external_synced_at']);
        });
        Schema::table('incidence_types', function (Blueprint $table) {
            $table->dropColumn(['evidence_kind', 'requires_folio', 'approval_route', 'omission_punch_type', 'external_code']);
        });
    }
};
