<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estructura alineada con Mr. Lana People (PDF pantalla 17):
 *  - Identificador externo (origen + id) en sucursales, centros de trabajo,
 *    departamentos y puestos para sincronización idempotente.
 *  - Registro patronal por sucursal (cada sucursal es un centro de trabajo).
 * Compatible con datos existentes: columnas nulas, sin valores inventados.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['branches', 'work_centers', 'departments', 'positions'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                if (! Schema::hasColumn($name, 'external_source')) {
                    $table->string('external_source', 40)->nullable()->comment('p. ej. people');
                    $table->string('external_id', 80)->nullable();
                    $table->timestamp('external_synced_at')->nullable();
                    $table->unique(['organization_id', 'external_source', 'external_id'], "{$name}_external_unique");
                }
            });
        }

        Schema::table('branches', function (Blueprint $table) {
            if (! Schema::hasColumn('branches', 'employer_registration')) {
                $table->string('employer_registration', 20)->nullable()->after('address')->comment('Registro patronal IMSS del centro de trabajo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('employer_registration');
        });

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropUnique("{$name}_external_unique");
                $table->dropColumn(['external_source', 'external_id', 'external_synced_at']);
            });
        }
    }
};
