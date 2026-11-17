<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Workforce\MexicanStates;
use App\Models\Branch;
use App\Models\Department;
use App\Models\EmploymentAssignment;
use App\Models\Geofence;
use App\Models\LegalEntity;
use App\Models\Position;
use App\Models\WorkCenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Estructura de la organización: razones sociales, centros de trabajo,
 * sucursales, departamentos, puestos y geocercas.
 */
class OrganizationController extends AdminController
{
    public function index(Request $request): Response
    {
        $today = CarbonImmutable::today()->toDateString();
        $active = fn (string $column) => EmploymentAssignment::query()
            ->whereNotNull($column)
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->selectRaw("{$column} as k, count(*) as total")->groupBy($column)->pluck('total', 'k');

        $byBranch = $active('branch_id');
        $byDepartment = $active('department_id');
        $byPosition = $active('position_id');
        $byCenter = $active('work_center_id');

        return Inertia::render('organizacion/Index', [
            'tab' => $request->query('tab', 'legal-entities'),
            'legal_entities' => LegalEntity::query()->withTrashed()->withCount('workCenters')->orderBy('legal_name')->get()
                ->map(fn (LegalEntity $e) => [
                    'id' => $e->id, 'legal_name' => $e->legal_name, 'rfc' => $e->rfc, 'employer_registration' => $e->employer_registration,
                    'fiscal_address' => $e->fiscal_address, 'active' => $e->active && ! $e->trashed(), 'deleted' => $e->trashed(), 'work_centers_count' => $e->work_centers_count,
                ]),
            'work_centers' => WorkCenter::query()->withTrashed()->with(['legalEntity' => fn ($q) => $q->withTrashed()->select('id', 'legal_name')])->withCount('branches')->orderBy('name')->get()
                ->map(fn (WorkCenter $w) => [
                    'id' => $w->id, 'name' => $w->name, 'code' => $w->code, 'address' => $w->address, 'state_code' => $w->state_code,
                    'state' => MexicanStates::ALL[$w->state_code ?? ''] ?? null, 'timezone' => $w->timezone, 'active' => $w->active && ! $w->trashed(), 'deleted' => $w->trashed(),
                    'legal_entity_id' => $w->legal_entity_id, 'legal_entity' => $w->legalEntity?->legal_name,
                    'branches_count' => $w->branches_count, 'employees_count' => (int) ($byCenter[$w->id] ?? 0),
                ]),
            'branches' => Branch::query()->withTrashed()->with(['workCenter' => fn ($q) => $q->withTrashed()->select('id', 'name')])->withCount('geofences')->orderBy('name')->get()
                ->map(fn (Branch $b) => [
                    'id' => $b->id, 'name' => $b->name, 'code' => $b->code, 'region' => $b->region, 'address' => $b->address,
                    'latitude' => $b->latitude, 'longitude' => $b->longitude, 'active' => $b->active && ! $b->trashed(), 'deleted' => $b->trashed(),
                    'work_center_id' => $b->work_center_id, 'work_center' => $b->workCenter?->name,
                    'geofences_count' => $b->geofences_count, 'employees_count' => (int) ($byBranch[$b->id] ?? 0),
                ]),
            'departments' => Department::query()->withTrashed()->with(['parent' => fn ($q) => $q->withTrashed()->select('id', 'name')])->withCount('positions')->orderBy('name')->get()
                ->map(fn (Department $d) => [
                    'id' => $d->id, 'name' => $d->name, 'code' => $d->code, 'active' => $d->active && ! $d->trashed(), 'deleted' => $d->trashed(),
                    'parent_id' => $d->parent_id, 'parent' => $d->parent?->name,
                    'positions_count' => $d->positions_count, 'employees_count' => (int) ($byDepartment[$d->id] ?? 0),
                ]),
            'positions' => Position::query()->withTrashed()->with(['department' => fn ($q) => $q->withTrashed()->select('id', 'name')])->orderBy('name')->get()
                ->map(fn (Position $p) => [
                    'id' => $p->id, 'name' => $p->name, 'code' => $p->code, 'active' => $p->active && ! $p->trashed(), 'deleted' => $p->trashed(),
                    'department_id' => $p->department_id,
                    'auto_clock_out_at' => $p->auto_clock_out_at ? substr((string) $p->auto_clock_out_at, 0, 5) : null, 'department' => $p->department?->name,
                    'employees_count' => (int) ($byPosition[$p->id] ?? 0),
                ]),
            'geofences' => Geofence::query()->withTrashed()->with(['branch' => fn ($q) => $q->withTrashed()->select('id', 'name')])->orderBy('name')->get()
                ->map(fn (Geofence $g) => [
                    'id' => $g->id, 'name' => $g->name, 'latitude' => $g->latitude, 'longitude' => $g->longitude,
                    'radius_meters' => (int) $g->radius_meters, 'active' => $g->active && ! $g->trashed(), 'deleted' => $g->trashed(),
                    'branch_id' => $g->branch_id, 'branch' => $g->branch?->name,
                ]),
            'states' => MexicanStates::options(),
            'timezones' => MexicanStates::timezones(),
            'can' => ['manage' => $request->user()?->can('organization.manage') ?? false],
        ]);
    }
}
