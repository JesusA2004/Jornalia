<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flujo de 5 pasos de rectificaciones (PDF pantalla 05): solicitud con
 * evidencia → autorización del jefe → firma del colaborador → validación RH →
 * aplicación. `stage` guarda la etapa; `status` se conserva para compatibilidad
 * (pending mientras está en curso, approved = aplicada, rejected/cancelled).
 *
 * Mapeo de datos existentes:
 *  - pending   → por_autorizar (debe recorrer el flujo completo, incluida la firma).
 *  - approved  → aplicada, `legacy_without_signature` = true (ya generaron su ajuste
 *                antes de existir la firma; no se exige firma retroactiva).
 *  - rejected  → rechazada (rejection_stage = por_autorizar).
 *  - cancelled → rechazada (rejection_stage = cancelada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rectification_requests', function (Blueprint $table) {
            $table->string('stage', 16)->default('por_autorizar')->after('status')
                ->comment('por_autorizar|por_firmar|por_validar|aplicada|rechazada');
            $table->string('origin', 12)->default('employee')->after('requested_by')->comment('employee|manager|incidence');
            $table->unsignedBigInteger('incidence_id')->nullable()->after('origin');
            $table->string('open_key', 120)->nullable()->after('stage')
                ->comment('Clave única mientras la solicitud está abierta (evita duplicados)');
            $table->foreignId('authorized_by')->nullable()->after('current_level')->constrained('users')->nullOnDelete();
            $table->dateTime('authorized_at')->nullable()->after('authorized_by');
            $table->text('authorization_comment')->nullable()->after('authorized_at');
            $table->dateTime('signature_due_at')->nullable()->after('authorization_comment')->comment('UTC');
            $table->foreignId('electronic_signature_id')->nullable()->after('signature_due_at')->constrained('electronic_signatures')->restrictOnDelete();
            $table->dateTime('signed_at')->nullable()->after('electronic_signature_id');
            $table->foreignId('validated_by')->nullable()->after('signed_at')->constrained('users')->nullOnDelete();
            $table->dateTime('validated_at')->nullable()->after('validated_by');
            $table->text('validation_comment')->nullable()->after('validated_at');
            $table->dateTime('applied_at')->nullable()->after('validation_comment');
            $table->string('rejection_stage', 16)->nullable()->after('applied_at')->comment('por_autorizar|por_firmar|por_validar|vencida|declinada|cancelada');
            $table->text('rejection_reason')->nullable()->after('rejection_stage');
            $table->foreignId('rejected_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            $table->dateTime('rejected_at')->nullable()->after('rejected_by');
            $table->boolean('is_late')->default(false)->after('rejected_at')->comment('Extemporánea: posterior al cierre de nómina');
            $table->boolean('legacy_without_signature')->default(false)->after('is_late');
            $table->unique('open_key');
            $table->index(['organization_id', 'stage']);
            $table->index(['stage', 'signature_due_at']);
        });

        if (! Schema::hasColumn('attachments', 'category')) {
            Schema::table('attachments', function (Blueprint $table) {
                $table->string('category', 40)->nullable()->after('attachable_id')
                    ->comment('Tipo de adjunto (p. ej. registration_agreement, evidence)');
            });
        }

        DB::table('rectification_requests')->where('status', 'approved')->update([
            'stage' => 'aplicada', 'legacy_without_signature' => true,
            'applied_at' => DB::raw('COALESCE(reviewed_at, updated_at)'),
            'validated_by' => DB::raw('reviewed_by'), 'validated_at' => DB::raw('reviewed_at'),
        ]);
        DB::table('rectification_requests')->where('status', 'rejected')->update([
            'stage' => 'rechazada', 'rejection_stage' => 'por_autorizar',
            'rejection_reason' => DB::raw('review_comment'), 'rejected_by' => DB::raw('reviewed_by'), 'rejected_at' => DB::raw('reviewed_at'),
        ]);
        DB::table('rectification_requests')->where('status', 'cancelled')->update([
            'stage' => 'rechazada', 'rejection_stage' => 'cancelada',
        ]);
        DB::table('rectification_requests')->where('status', 'pending')->update([
            'stage' => 'por_autorizar', 'current_level' => 1,
            'open_key' => DB::raw("CONCAT('legacy:', id)"),
        ]);
    }

    public function down(): void
    {
        Schema::table('rectification_requests', function (Blueprint $table) {
            $table->dropUnique(['open_key']);
            $table->dropIndex(['organization_id', 'stage']);
            $table->dropIndex(['stage', 'signature_due_at']);
            $table->dropConstrainedForeignId('authorized_by');
            $table->dropConstrainedForeignId('electronic_signature_id');
            $table->dropConstrainedForeignId('validated_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn([
                'stage', 'origin', 'incidence_id', 'open_key', 'authorized_at', 'authorization_comment', 'signature_due_at',
                'signed_at', 'validated_at', 'validation_comment', 'applied_at', 'rejection_stage', 'rejection_reason',
                'rejected_at', 'is_late', 'legacy_without_signature',
            ]);
        });
        if (Schema::hasColumn('attachments', 'category')) {
            Schema::table('attachments', fn (Blueprint $table) => $table->dropColumn('category'));
        }
    }
};
