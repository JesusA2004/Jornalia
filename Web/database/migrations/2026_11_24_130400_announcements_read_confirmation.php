<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmación de lectura para comunicados importantes (PDF pantalla 12, punto 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->boolean('requires_confirmation')->default(false)->after('priority');
        });
        Schema::table('announcement_recipients', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('read_at')->comment('Confirmación explícita de lectura en la app');
        });
    }

    public function down(): void
    {
        Schema::table('announcement_recipients', fn (Blueprint $table) => $table->dropColumn('confirmed_at'));
        Schema::table('announcements', fn (Blueprint $table) => $table->dropColumn('requires_confirmation'));
    }
};
