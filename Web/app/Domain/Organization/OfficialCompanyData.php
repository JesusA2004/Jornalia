<?php

namespace App\Domain\Organization;

use App\Domain\Audit\AuditLogger;
use App\Models\LegalEntity;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Datos oficiales de la empresa tomados de la Constancia de Situación Fiscal
 * (revisión RH, pantallas 17 y 19). Viven en:
 *  - `legal_entities` (razón social, RFC, domicilio fiscal) y
 *  - `organizations.name` (nombre comercial).
 *
 * Carga idempotente: llena campos vacíos, deja igual lo que ya coincide y NUNCA
 * sobrescribe un valor capturado distinto salvo que se fuerce explícitamente;
 * cada cambio queda en la bitácora con valor anterior y nuevo.
 */
final class OfficialCompanyData
{
    public const LEGAL_NAME = 'PRODUCTOS Y SERVICIOS MR LANA, S.A.P.I. de C.V.';

    public const RFC = 'PSM2311289J2';

    public const FISCAL_ADDRESS = 'Subida del Club 114, Col. Reforma, C.P. 62260, Cuernavaca, Morelos';

    public const TRADE_NAME = 'Mr. Lana';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CurrentOrganization $current,
    ) {}

    /**
     * @return array{status: 'applied'|'unchanged'|'conflict'|'skipped', changes: list<array{field:string, before:?string, after:string}>, conflicts: list<array{field:string, current:?string, official:string}>, message: string}
     */
    public function apply(Organization $organization, bool $force = false, bool $dryRun = false): array
    {
        return $this->current->runAs($organization, function () use ($organization, $force, $dryRun) {
            $entities = LegalEntity::query()->get();
            $entity = $entities->first(fn (LegalEntity $e) => strtoupper((string) $e->rfc) === self::RFC);
            if ($entity === null && $entities->count() > 1) {
                return ['status' => 'skipped', 'changes' => [], 'conflicts' => [], 'message' => 'Hay varias razones sociales y ninguna tiene el RFC oficial: captúralo manualmente en Organización.'];
            }
            $entity ??= $entities->first() ?? new LegalEntity(['active' => true]);

            $plan = [
                ['legal_entity', 'legal_name', self::LEGAL_NAME],
                ['legal_entity', 'rfc', self::RFC],
                ['legal_entity', 'fiscal_address', self::FISCAL_ADDRESS],
                ['organization', 'name', self::TRADE_NAME],
            ];

            $changes = [];
            $conflicts = [];
            foreach ($plan as [$target, $field, $official]) {
                $model = $target === 'organization' ? $organization : $entity;
                $current = $model->getAttribute($field);
                $current = $current === null || trim((string) $current) === '' ? null : (string) $current;
                if ($current !== null && $this->same($current, $official)) {
                    continue;
                }
                if ($current !== null && ! $force) {
                    $conflicts[] = ['field' => $field, 'current' => $current, 'official' => $official];

                    continue;
                }
                $changes[] = ['field' => $field, 'before' => $current, 'after' => $official, 'target' => $target];
            }

            if ($dryRun || $changes === []) {
                return $this->result($changes, $conflicts, $dryRun);
            }

            DB::transaction(function () use ($organization, $entity, $changes) {
                foreach ($changes as $change) {
                    $model = $change['target'] === 'organization' ? $organization : $entity;
                    $model->setAttribute($change['field'], $change['after']);
                }
                $organization->save();
                $entity->save();

                $this->audit->log('organization.official_data_loaded', $entity, 'Datos oficiales de la empresa cargados', [
                    'changes' => array_map(fn ($c) => ['field' => $c['field'], 'before' => $c['before'], 'after' => $c['after']], $changes),
                ], $organization->id);
            });

            return $this->result($changes, $conflicts, false);
        });
    }

    private function same(string $a, string $b): bool
    {
        $normalize = fn (string $v) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($v)) ?? $v);

        return $normalize($a) === $normalize($b);
    }

    /**
     * @param  list<array{field:string, before:?string, after:string, target:string}>  $changes
     * @param  list<array{field:string, current:?string, official:string}>  $conflicts
     * @return array{status: 'applied'|'unchanged'|'conflict'|'skipped', changes: list<array{field:string, before:?string, after:string}>, conflicts: list<array{field:string, current:?string, official:string}>, message: string}
     */
    private function result(array $changes, array $conflicts, bool $dryRun): array
    {
        $clean = array_map(fn ($c) => ['field' => $c['field'], 'before' => $c['before'], 'after' => $c['after']], $changes);
        $status = $conflicts !== [] ? 'conflict' : ($changes === [] ? 'unchanged' : 'applied');
        $message = match ($status) {
            'conflict' => 'Hay valores capturados distintos a los oficiales; no se sobrescribieron (usa --forzar tras revisarlos).',
            'unchanged' => 'Los datos oficiales ya estaban cargados.',
            default => $dryRun ? 'Simulación: se cargarían los datos oficiales.' : 'Datos oficiales cargados.',
        };

        return ['status' => $status, 'changes' => $clean, 'conflicts' => $conflicts, 'message' => $message];
    }
}
