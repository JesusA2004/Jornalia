<?php

namespace App\Domain\Workforce;

use App\Domain\Signatures\RegistrationDocuments;
use App\Enums\DeviceStatus;
use App\Models\ElectronicSignature;
use App\Models\Employee;
use App\Models\PolicyDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Alta de registro electrónico (PDF RH pantalla 09).
 *
 * Un colaborador activo queda «Alta en proceso» (alta de registro incompleta) hasta
 * cumplir AMBOS requisitos:
 *  1. App vinculada: un dispositivo autorizado y vigente (vínculo uno a uno).
 *  2. Convenio de registro firmado: versión vigente publicada firmada en la app o
 *     convenio en papel adjunto al expediente (`Employee::hasSignedRegistrationAgreement()`).
 *
 * La regla individual delega en el helper de A2; aquí sólo se replica en SQL para
 * listados y filtros sin consultas N+1.
 */
final class RegistrationStatus
{
    public const FILTERS = ['complete', 'incomplete', 'no_app', 'no_agreement'];

    private ?int $agreementId = null;

    private bool $agreementResolved = false;

    public function __construct(private readonly RegistrationDocuments $documents) {}

    /**
     * @return array{app: bool, agreement: bool, complete: bool}
     */
    public function for(Employee $employee): array
    {
        $app = $employee->devices()->where('status', DeviceStatus::Approved->value)->exists();
        $agreement = $employee->hasSignedRegistrationAgreement();

        return ['app' => $app, 'agreement' => $agreement, 'complete' => $app && $agreement];
    }

    /**
     * Estado por lote (una consulta por requisito).
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array{app: bool, agreement: bool, complete: bool}>
     */
    public function forMany(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $withApp = Employee::query()->whereIn('employees.id', $employeeIds)->whereExists(fn ($q) => $this->appSubquery($q))->pluck('employees.id')->flip();
        $withAgreement = Employee::query()->whereIn('employees.id', $employeeIds)->where(fn (Builder $q) => $this->whereAgreement($q))->pluck('employees.id')->flip();

        $out = [];
        foreach ($employeeIds as $id) {
            $app = $withApp->has($id);
            $agreement = $withAgreement->has($id);
            $out[$id] = ['app' => $app, 'agreement' => $agreement, 'complete' => $app && $agreement];
        }

        return $out;
    }

    /**
     * Aplica el filtro «Registro electrónico».
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function applyFilter(Builder $query, string $filter): Builder
    {
        return match ($filter) {
            'complete' => $query->whereExists(fn ($q) => $this->appSubquery($q))->where(fn (Builder $q) => $this->whereAgreement($q)),
            'incomplete' => $this->whereIncomplete($query),
            'no_app' => $query->whereNotExists(fn ($q) => $this->appSubquery($q)),
            'no_agreement' => $query->whereNot(fn (Builder $q) => $this->whereAgreement($q)),
            default => $query,
        };
    }

    /**
     * Sin app vinculada o sin convenio firmado.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function whereIncomplete(Builder $query): Builder
    {
        return $query->where(fn (Builder $w) => $w
            ->whereNotExists(fn ($q) => $this->appSubquery($q))
            ->orWhereNot(fn (Builder $q) => $this->whereAgreement($q)));
    }

    private function appSubquery(QueryBuilder $q): void
    {
        $q->selectRaw('1')->from('devices as rs_d')
            ->whereColumn('rs_d.employee_id', 'employees.id')
            ->where('rs_d.status', DeviceStatus::Approved->value)
            ->whereNull('rs_d.deleted_at');
    }

    /**
     * @param  Builder<Employee>  $query
     */
    private function whereAgreement(Builder $query): void
    {
        $agreementId = $this->currentAgreementId();
        $employeeMorph = (new Employee)->getMorphClass();

        $query->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('attachments as rs_a')
            ->whereColumn('rs_a.attachable_id', 'employees.id')
            ->where('rs_a.attachable_type', $employeeMorph)
            ->where('rs_a.category', RegistrationDocuments::PAPER_AGREEMENT_CATEGORY));

        if ($agreementId !== null) {
            $query->orWhereExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('electronic_signatures as rs_s')
                ->whereColumn('rs_s.employee_id', 'employees.id')
                ->where('rs_s.signable_type', (new PolicyDocument)->getMorphClass())
                ->where('rs_s.signable_id', $agreementId)
                ->where('rs_s.purpose', ElectronicSignature::PURPOSE_REGISTRATION_AGREEMENT));
        }
    }

    private function currentAgreementId(): ?int
    {
        if (! $this->agreementResolved) {
            $this->agreementId = $this->documents->current(RegistrationDocuments::AGREEMENT)?->id;
            $this->agreementResolved = true;
        }

        return $this->agreementId;
    }
}
