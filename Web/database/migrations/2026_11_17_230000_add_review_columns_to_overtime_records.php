<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite autorizar horas extraordinarias con el flujo multinivel común
 * (`ApprovalWorkflow`), igual que rectificaciones, incidencias y vacaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->unsignedTinyInteger('current_level')->default(1)->after('status');
            $table->foreignId('reviewed_by')->nullable()->after('authorized_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_comment')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['current_level', 'reviewed_at', 'review_comment']);
        });
    }
};
