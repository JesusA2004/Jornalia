<?php

namespace App\Domain\Incidences;

use Illuminate\Support\Facades\DB;

/**
 * Catálogo CERRADO de incidencias (PDF pantalla 06). RH sólo ajusta, en
 * Configuración › Incidencias, la evidencia, la ruta de autorización y la
 * vigencia; no se agregan tipos nuevos ni se borra ninguno.
 *
 * La falta injustificada NO se captura: la determina el sistema cuando no hay
 * marcaje ni justificación aprobada. Vacaciones conserva su propio módulo.
 * Las omisiones de checada generan una rectificación para firma del colaborador.
 */
final class IncidenceCatalog
{
    /** @var array<string, string> */
    public const EVIDENCE_KINDS = [
        'imss_certificate' => 'Certificado de incapacidad IMSS',
        'authorized_form' => 'Formato de permiso autorizado',
        'death_certificate' => 'Acta de defunción',
        'proof' => 'Constancia',
    ];

    /** @var array<string, string> */
    public const APPROVAL_ROUTES = [
        'manager' => 'Jefe inmediato',
        'hr' => 'RH',
        'manager_hr' => 'Jefe inmediato y RH',
    ];

    /**
     * @var list<array{code:string, name:string, category:string, effect:string, evidence:string, folio:bool, route:string, omission:?string, description:string}>
     */
    public const TYPES = [
        ['code' => 'PERMISO_CON_GOCE', 'name' => 'Permiso con goce de sueldo', 'category' => 'leave', 'effect' => 'paid_absence', 'evidence' => 'authorized_form', 'folio' => false, 'route' => 'manager_hr', 'omission' => null, 'description' => 'Permiso autorizado con pago.'],
        ['code' => 'PERMISO_SIN_GOCE', 'name' => 'Permiso sin goce de sueldo', 'category' => 'leave', 'effect' => 'unpaid_absence', 'evidence' => 'authorized_form', 'folio' => false, 'route' => 'manager_hr', 'omission' => null, 'description' => 'Permiso autorizado sin pago.'],
        ['code' => 'FALTA_JUSTIFICADA', 'name' => 'Falta justificada', 'category' => 'absence', 'effect' => 'justified', 'evidence' => 'proof', 'folio' => false, 'route' => 'manager_hr', 'omission' => null, 'description' => 'Ausencia justificada con constancia.'],
        ['code' => 'INCAPACIDAD_EG', 'name' => 'Incapacidad · enfermedad general', 'category' => 'disability', 'effect' => 'justified', 'evidence' => 'imss_certificate', 'folio' => true, 'route' => 'manager_hr', 'omission' => null, 'description' => 'Certificado IMSS con folio.'],
        ['code' => 'INCAPACIDAD_RT', 'name' => 'Incapacidad · riesgo de trabajo', 'category' => 'disability', 'effect' => 'justified', 'evidence' => 'imss_certificate', 'folio' => true, 'route' => 'manager_hr', 'omission' => null, 'description' => 'Certificado IMSS con folio.'],
        ['code' => 'INCAPACIDAD_MAT', 'name' => 'Incapacidad · maternidad', 'category' => 'disability', 'effect' => 'justified', 'evidence' => 'imss_certificate', 'folio' => true, 'route' => 'manager_hr', 'omission' => null, 'description' => 'Certificado IMSS con folio (LFT art. 170).'],
        ['code' => 'PATERNIDAD', 'name' => 'Licencia de paternidad', 'category' => 'leave', 'effect' => 'paid_absence', 'evidence' => 'proof', 'folio' => false, 'route' => 'manager_hr', 'omission' => null, 'description' => 'LFT art. 132 fr. XXVII Bis.'],
        ['code' => 'LUTO', 'name' => 'Luto', 'category' => 'leave', 'effect' => 'justified', 'evidence' => 'death_certificate', 'folio' => false, 'route' => 'manager_hr', 'omission' => null, 'description' => 'Con acta de defunción.'],
        ['code' => 'OMISION_ENTRADA', 'name' => 'Omisión de checada · entrada', 'category' => 'time', 'effect' => 'informative', 'evidence' => 'proof', 'folio' => false, 'route' => 'manager', 'omission' => 'clock_in', 'description' => 'Se resuelve con rectificación firmada por el colaborador.'],
        ['code' => 'OMISION_SALIDA', 'name' => 'Omisión de checada · salida', 'category' => 'time', 'effect' => 'informative', 'evidence' => 'proof', 'folio' => false, 'route' => 'manager', 'omission' => 'clock_out', 'description' => 'Se resuelve con rectificación firmada por el colaborador.'],
    ];

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_column(self::TYPES, 'code');
    }

    /**
     * Niveles de autorización según la ruta configurada del tipo.
     *
     * @return list<array{level:int, permission:string, label:string}>
     */
    public static function levelsFor(string $route): array
    {
        return match ($route) {
            'manager' => [['level' => 1, 'permission' => 'incidences.approve', 'label' => 'Jefe inmediato']],
            'hr' => [['level' => 1, 'permission' => 'incidences.validate', 'label' => 'RH']],
            default => [
                ['level' => 1, 'permission' => 'incidences.approve', 'label' => 'Jefe inmediato'],
                ['level' => 2, 'permission' => 'incidences.validate', 'label' => 'RH'],
            ],
        };
    }

    /**
     * Ajusta el catálogo de todas las organizaciones (idempotente).
     */
    public static function syncAllOrganizations(): void
    {
        foreach (DB::table('organizations')->pluck('id') as $id) {
            self::syncOrganization((int) $id);
        }
    }

    /**
     * Crea los tipos faltantes, fija los campos cerrados de los existentes y
     * desactiva (sin borrar) los que quedan fuera del catálogo. Los ajustes
     * configurables por RH sólo se inicializan la primera vez.
     */
    public static function syncOrganization(int $organizationId): void
    {
        $now = now();
        $today = $now->toDateString();

        foreach (self::TYPES as $t) {
            $existing = DB::table('incidence_types')->where('organization_id', $organizationId)->where('code', $t['code'])->first();
            $fixed = [
                'name' => $t['name'],
                'category' => $t['category'],
                'operational_effect' => $t['effect'],
                'description' => $t['description'],
                'omission_punch_type' => $t['omission'],
                'is_system' => true,
                'affects_payroll' => $t['category'] !== 'time',
                'updated_at' => $now,
            ];

            if ($existing === null) {
                DB::table('incidence_types')->insert($fixed + [
                    'organization_id' => $organizationId,
                    'code' => $t['code'],
                    'requires_evidence' => true,
                    'evidence_kind' => $t['evidence'],
                    'requires_folio' => $t['folio'],
                    'requires_approval' => true,
                    'approval_route' => $t['route'],
                    'employee_requestable' => true,
                    'active' => true,
                    'created_at' => $now,
                ]);

                continue;
            }

            $initial = $existing->evidence_kind === null ? [
                'requires_evidence' => true,
                'evidence_kind' => $t['evidence'],
                'requires_folio' => $t['folio'],
                'requires_approval' => true,
                'approval_route' => $t['route'],
                'employee_requestable' => true,
            ] : [];
            DB::table('incidence_types')->where('id', $existing->id)->update($fixed + $initial);
        }

        // Fuera del catálogo: baja lógica por desactivación (el historial se conserva).
        DB::table('incidence_types')
            ->where('organization_id', $organizationId)
            ->whereNotIn('code', self::codes())
            ->where('active', true)
            ->update(['active' => false, 'valid_to' => DB::raw("COALESCE(valid_to, '{$today}')"), 'updated_at' => $now]);
    }
}
