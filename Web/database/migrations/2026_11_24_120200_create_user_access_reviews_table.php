<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisión trimestral de usuarios activos (PDF pantalla 18, punto 4).
 * Una revisión por organización y trimestre; las decisiones quedan en JSON y en bitácora.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_access_reviews')) {
            return;
        }

        Schema::create('user_access_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('period', 10)->comment('AAAA-Tn');
            $table->date('due_on');
            $table->string('status', 12)->default('open')->comment('open|completed');
            $table->json('decisions')->nullable()->comment('{user_id: {decision, by, at, note}}');
            $table->json('summary')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_access_reviews');
    }
};
