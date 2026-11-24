<?php

namespace App\Domain\Reports\Builders;

use App\Domain\Reports\ReportParameters;
use App\Domain\Reports\Support\ReportFormat;
use App\Models\Attachment;
use App\Models\ElectronicSignature;
use App\Models\Incidence;
use App\Models\RectificationRequest;
use App\Models\User;
use Generator;
use Illuminate\Support\Collection;

/**
 * «Omisiones e incidencias con evidencia»: por colaborador y fecha, cada
 * omisión de checada (sin entrada / sin salida / descanso sin cerrar) y cada
 * incidencia del periodo, con la evidencia adjunta (nombre y huella), la
 * rectificación vinculada y la firma electrónica del colaborador.
 */
final class EvidenceReport
{
    public function __construct(private readonly AttendanceDays $days) {}

    public function estimate(ReportParameters $p, User $user): int
    {
        return $this->days->estimate($p, $user);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(ReportParameters $p, User $user): Generator
    {
        $current = null;
        $omissions = [];
        foreach ($this->days->byEmployee($p, $user, withEvents: false) as $day) {
            if ($current !== null && $current['employee_id'] !== $day['employee_id']) {
                yield from $this->employeeRows($current, $omissions, $p);
                $omissions = [];
            }
            $current = $day;
            if ($day['omission'] !== '') {
                $omissions[] = $day;
            }
        }
        if ($current !== null) {
            yield from $this->employeeRows($current, $omissions, $p);
        }
    }

    /**
     * @param  array<string, mixed>  $employee  primera fila del colaborador (datos generales)
     * @param  list<array<string, mixed>>  $omissions
     * @return Generator<int, array<string, mixed>>
     */
    private function employeeRows(array $employee, array $omissions, ReportParameters $p): Generator
    {
        $from = $p->from->toDateString();
        $to = $p->to->toDateString();
        $employeeId = (int) $employee['employee_id'];

        $rectifications = RectificationRequest::query()->with(['attachments', 'signature.device'])
            ->where('employee_id', $employeeId)->whereBetween('date', [$from, $to])->orderBy('id')->get();
        $byDate = $rectifications->groupBy(fn (RectificationRequest $r) => $r->date->toDateString());

        $incidences = Incidence::query()->with(['type', 'attachments'])
            ->where('employee_id', $employeeId)
            ->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from)
            ->when($p->status, fn ($q) => $q->where('status', $p->status))
            ->orderBy('starts_on')->get();

        $rows = [];
        foreach ($omissions as $day) {
            /** @var Collection<int, RectificationRequest> $rects */
            $rects = $byDate->get($day['date'], collect());
            $rect = $rects->last();
            $rows[] = $this->base($employee) + [
                'date' => $day['date'],
                'kind_label' => 'Omisión de checada',
                'concept' => $day['omission'],
                'detail' => $day['schedule'] !== '—' ? 'Horario '.$day['schedule'] : '',
                'status_label' => $rect !== null ? ReportFormat::rectificationStage($rect) : 'Sin rectificar',
                ...$this->evidence($rect->attachments ?? collect()),
                'rectification' => $rect !== null ? 'RECT-'.$rect->id : '—',
                ...$this->signature($rect?->signature),
            ];
        }
        foreach ($incidences as $inc) {
            $rect = $rectifications->first(fn (RectificationRequest $r) => (int) $r->getAttribute('incidence_id') === $inc->id);
            $status = $inc->status->label();
            $range = $inc->starts_on->toDateString() === $inc->ends_on->toDateString()
                ? '' : 'Del '.$inc->starts_on->format('d/m/Y').' al '.$inc->ends_on->format('d/m/Y').'. ';
            $rows[] = $this->base($employee) + [
                'date' => max($inc->starts_on->toDateString(), $from),
                'kind_label' => 'Incidencia',
                'concept' => (string) ($inc->type->name ?? 'Incidencia'),
                'detail' => trim($range.($inc->reason ?? '')),
                'status_label' => $status,
                ...$this->evidence($inc->attachments),
                'rectification' => $rect !== null ? 'RECT-'.$rect->id : '—',
                ...$this->signature($rect?->signature),
            ];
        }
        usort($rows, fn ($a, $b) => [$a['date'], $a['kind_label']] <=> [$b['date'], $b['kind_label']]);
        foreach ($rows as $row) {
            yield $row;
        }
    }

    /**
     * @param  array<string, mixed>  $employee
     * @return array<string, mixed>
     */
    private function base(array $employee): array
    {
        return [
            'employee_number' => $employee['employee_number'],
            'employee_name' => $employee['employee_name'],
            'branch' => $employee['branch'],
            'department' => $employee['department'],
            'position' => $employee['position'],
            '_employee_key' => $employee['_employee_key'],
            '_employee_label' => $employee['_employee_label'],
        ];
    }

    /**
     * @param  Collection<int, Attachment>  $files
     * @return array{evidence:string, evidence_count:int}
     */
    private function evidence(Collection $files): array
    {
        return [
            'evidence' => $files->isEmpty()
                ? 'Sin evidencia'
                : $files->map(fn (Attachment $a) => $a->original_name.' ('.substr((string) $a->sha256, 0, 12).')')->implode('; '),
            'evidence_count' => $files->count(),
        ];
    }

    /**
     * @return array{signed_at:?string, signature_method:?string, signature_seal:?string}
     */
    private function signature(?ElectronicSignature $signature): array
    {
        if ($signature === null) {
            return ['signed_at' => null, 'signature_method' => 'Sin firma', 'signature_seal' => null];
        }

        return [
            'signed_at' => ReportFormat::iso($signature->signed_at),
            'signature_method' => $signature->methodLabel().($signature->device?->name ? ' · '.$signature->device->name : ''),
            'signature_seal' => $signature->hash,
        ];
    }
}
