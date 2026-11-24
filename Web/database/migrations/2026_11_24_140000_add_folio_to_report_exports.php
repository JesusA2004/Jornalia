<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Folio legible de cada exportación de reporte (encabezado institucional y
 * bitácora). Formato: REP-AAAA-NNNNNN (consecutivo global del registro).
 * Las exportaciones existentes reciben su folio a partir de su id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_exports', function (Blueprint $table) {
            $table->string('folio', 24)->nullable()->unique()->after('uuid');
        });

        DB::table('report_exports')->whereNull('folio')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('report_exports')->where('id', $row->id)->update([
                    'folio' => sprintf('REP-%s-%06d', substr((string) $row->created_at, 0, 4) ?: date('Y'), $row->id),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('report_exports', function (Blueprint $table) {
            $table->dropUnique(['folio']);
            $table->dropColumn('folio');
        });
    }
};
