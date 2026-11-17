<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notificaciones: token push FCM por dispositivo, comunicados, destinatarios y
 * bitácora de avisos automáticos (idempotencia). Nada se elimina físicamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('push_token', 512)->nullable()->after('expo_push_token');
            $table->string('push_provider', 10)->nullable()->after('push_token')->comment('fcm|expo');
            $table->timestamp('push_token_updated_at')->nullable()->after('push_provider');
            $table->timestamp('push_token_invalidated_at')->nullable()->after('push_token_updated_at')->comment('Token desactivado (UNREGISTERED); se conserva');
            $table->string('push_token_error', 40)->nullable()->after('push_token_invalidated_at');
        });

        // Conserva los tokens Expo existentes (no se borra el dato original).
        DB::table('devices')->whereNotNull('expo_push_token')->update([
            'push_token' => DB::raw('expo_push_token'),
            'push_provider' => 'expo',
        ]);

        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('withdrawn_at')->nullable()->after('read_at')->comment('Retirada: deja de mostrarse, se conserva');
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('title', 80);
            $table->text('body');
            $table->string('priority', 10)->default('normal')->comment('normal|high');
            $table->string('audience_type', 20)->comment('all|branches|departments|positions|employees');
            $table->json('audience_ids')->nullable();
            $table->string('status', 12)->default('scheduled')->comment('scheduled|sending|sent');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'scheduled_at']);
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('announcement_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('announcement_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('notification_id')->nullable()->index();
            $table->timestamp('delivered_at')->nullable()->comment('En la bandeja de la app');
            $table->timestamp('read_at')->nullable();
            $table->string('push_status', 12)->default('pending')->comment('pending|sent|no_device|disabled|failed');
            $table->timestamp('push_sent_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('failed_at')->nullable();
            $table->string('error', 120)->nullable();
            $table->timestamps();
            $table->unique(['announcement_id', 'employee_id']);
        });

        Schema::create('notification_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->date('local_date');
            $table->string('reference', 64)->default('');
            $table->timestamp('created_at')->nullable();
            $table->unique(['employee_id', 'type', 'local_date', 'reference'], 'notification_dispatches_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatches');
        Schema::dropIfExists('announcement_recipients');
        Schema::dropIfExists('announcements');
        Schema::table('notifications', fn (Blueprint $table) => $table->dropColumn('withdrawn_at'));
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn(['push_token', 'push_provider', 'push_token_updated_at', 'push_token_invalidated_at', 'push_token_error']));
    }
};
