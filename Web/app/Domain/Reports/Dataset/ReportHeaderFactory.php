<?php

namespace App\Domain\Reports\Dataset;

use App\Domain\Reports\Definition\ReportDefinition;
use App\Domain\Reports\ReportParameters;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Organization;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkCenter;
use Illuminate\Support\Facades\Storage;

/**
 * Construye el encabezado institucional con datos REALES de la organización:
 * razón social y RFC de la razón social aplicable, centro de trabajo según los
 * filtros, periodo, fecha/hora de generación, usuario y folio. Nada se inventa:
 * si un dato no está capturado se indica «Sin capturar».
 */
final class ReportHeaderFactory
{
    public function make(ReportDefinition $definition, ReportParameters $params, User $user, Organization $organization, ?string $folio): ReportHeader
    {
        [$legalName, $rfc] = $this->legalEntity($params, $organization);

        return new ReportHeader(
            title: $definition->title,
            description: $definition->description,
            organizationName: (string) $organization->name,
            legalName: $legalName,
            rfc: $rfc,
            workCenter: $this->workCenter($params),
            period: $params->from->format('d/m/Y').' al '.$params->to->format('d/m/Y'),
            generatedAt: now()->setTimezone($params->timezone)->format('d/m/Y H:i:s'),
            timezone: $params->timezone,
            generatedBy: (string) $user->name,
            generatedByEmail: $user->email,
            folio: $folio,
            filters: $this->describeFilters($params),
            observations: $params->observations,
            logoPath: $this->logoPath($organization),
        );
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function legalEntity(ReportParameters $params, Organization $organization): array
    {
        $entity = null;
        if ($params->employeeId !== null) {
            $entity = Employee::query()->find($params->employeeId)?->employmentOn($params->to)?->legalEntity;
        }
        if ($entity === null && $params->branchId !== null) {
            $entityId = Branch::query()->with('workCenter')->find($params->branchId)?->workCenter?->legal_entity_id;
            $entity = $entityId !== null ? LegalEntity::query()->find($entityId) : null;
        }
        if ($entity === null) {
            $active = LegalEntity::query()->where('active', true)->orderBy('id')->limit(2)->get();
            $entity = $active->count() === 1 ? $active->first() : null;
            if ($active->count() > 1) {
                return ['Varias razones sociales ('.$organization->name.')', null];
            }
        }

        return $entity !== null
            ? [(string) $entity->legal_name, $entity->rfc ?: null]
            : [(string) $organization->name, null];
    }

    private function workCenter(ReportParameters $params): string
    {
        if ($params->branchId !== null) {
            $branch = Branch::query()->with('workCenter')->find($params->branchId);
            if ($branch !== null) {
                return $branch->workCenter !== null
                    ? $branch->workCenter->name.' · Sucursal '.$branch->name
                    : 'Sucursal '.$branch->name;
            }
        }
        if ($params->employeeId !== null) {
            $assignment = Employee::query()->find($params->employeeId)?->assignmentOn($params->to);
            $center = $assignment?->workCenter ?? $assignment?->branch?->workCenter;
            if ($center !== null) {
                return (string) $center->name;
            }
            if ($assignment?->branch !== null) {
                return 'Sucursal '.$assignment->branch->name;
            }
        }
        if ($params->region !== null) {
            return 'Región '.$params->region;
        }
        $centers = WorkCenter::query()->where('active', true)->orderBy('name')->limit(2)->pluck('name');

        return $centers->count() === 1 ? (string) $centers->first() : 'Todos los centros de trabajo del alcance';
    }

    public function describeFilters(ReportParameters $p): string
    {
        $parts = [];
        if ($p->branchId) {
            $parts[] = 'Sucursal: '.(Branch::query()->whereKey($p->branchId)->value('name') ?? "#{$p->branchId}");
        }
        if ($p->region) {
            $parts[] = 'Región: '.$p->region;
        }
        if ($p->departmentId) {
            $parts[] = 'Departamento: '.(Department::query()->whereKey($p->departmentId)->value('name') ?? "#{$p->departmentId}");
        }
        if ($p->positionId) {
            $parts[] = 'Puesto: '.(Position::query()->whereKey($p->positionId)->value('name') ?? "#{$p->positionId}");
        }
        if ($p->employeeId) {
            $e = Employee::query()->find($p->employeeId);
            $parts[] = 'Colaborador: '.($e ? $e->fullName().' ('.$e->employee_number.')' : "#{$p->employeeId}");
        }
        if ($p->status) {
            $parts[] = 'Estado: '.$p->status;
        }
        if ($p->periodUnit !== 'week') {
            $parts[] = 'Periodicidad: '.($p->periodUnit === 'fortnight' ? 'quincenal' : 'mensual');
        }

        return implode(' · ', $parts);
    }

    /**
     * Logotipo de la organización si está cargado; si no, el logotipo oficial
     * (`public/brand/logo.png`, sin modificaciones).
     */
    private function logoPath(Organization $organization): ?string
    {
        $path = $organization->brand_logo_path;
        if ($path) {
            foreach (['public', 'local'] as $disk) {
                if (Storage::disk($disk)->exists($path) && preg_match('/\.(png|jpe?g)$/i', $path)) {
                    return Storage::disk($disk)->path($path);
                }
            }
        }
        $official = public_path('brand/logo.png');

        return is_file($official) ? $official : null;
    }
}
