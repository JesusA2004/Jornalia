<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archivo PDF versionado de los documentos del registro (aviso de privacidad y
 * convenio de registro). El PDF publicado es inmutable igual que el texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('policy_documents', 'file_path')) {
                $table->string('file_path')->nullable()->after('body_markdown');
            }
            if (! Schema::hasColumn('policy_documents', 'file_name')) {
                $table->string('file_name', 200)->nullable()->after('file_path');
            }
            if (! Schema::hasColumn('policy_documents', 'file_sha256')) {
                $table->char('file_sha256', 64)->nullable()->after('file_name');
            }
            if (! Schema::hasColumn('policy_documents', 'file_size')) {
                $table->unsignedInteger('file_size')->nullable()->after('file_sha256');
            }
        });
    }

    public function down(): void
    {
        Schema::table('policy_documents', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'file_name', 'file_sha256', 'file_size']);
        });
    }
};
