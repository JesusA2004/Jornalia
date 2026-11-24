<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Configuración (PDF pantalla 19):
 *  - Color institucional #225C54 (hoja membretada) cuando el color guardado está fuera de la paleta.
 *  - Elimina correos de privacidad de ejemplo (p. ej. privacidad@empresa.mx): el contacto queda
 *    vacío («pendiente») hasta que RH y Jurídico capturen el correo real.
 */
return new class extends Migration
{
    /** Paleta institucional (debe coincidir con CompanySettingsController::PALETTE). */
    private const PALETTE = ['#163A36', '#225C54', '#2F5D3A', '#1B4B5A', '#AF8B51', '#7A4F1E', '#E6D3A8'];

    public function up(): void
    {
        DB::table('organizations')
            ->where(fn ($q) => $q->whereNull('brand_primary_color')->orWhereNotIn(DB::raw('UPPER(brand_primary_color)'), self::PALETTE))
            ->update(['brand_primary_color' => '#225C54']);

        DB::table('organizations')
            ->where(fn ($q) => $q->where('privacy_contact_email', 'like', '%@empresa.mx')
                ->orWhere('privacy_contact_email', 'like', '%@example.%')
                ->orWhere('privacy_contact_email', 'like', '%@ejemplo.%'))
            ->update(['privacy_contact_email' => null]);
    }

    public function down(): void
    {
        // Sin reversión: no se restauran datos de ejemplo.
    }
};
