<?php

namespace App\Http\Controllers\Web\Operacion\Support;

use App\Domain\Approvals\ApprovalWorkflow;
use App\Enums\ApprovalStatus;
use App\Models\ApprovalDecision;
use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Serialización de flujos de aprobación, decisiones y evidencias.
 */
final class ApprovalPresenter
{
    /**
     * @param  Collection<int, Attachment>  $attachments
     * @param  callable(Attachment): string  $url
     * @return list<array<string, mixed>>
     */
    public static function attachments(Collection $attachments, callable $url): array
    {
        return array_values($attachments->map(fn (Attachment $a) => [
            'id' => $a->id,
            'name' => $a->original_name,
            'mime' => $a->mime,
            'size' => (int) $a->size,
            'sha256' => $a->sha256,
            'uploaded_by' => $a->uploadedBy?->name,
            'created_at' => OperacionScope::iso($a->created_at),
            'url' => $url($a),
        ])->all());
    }

    /**
     * @param  Collection<int, ApprovalDecision>  $decisions
     * @return list<array<string, mixed>>
     */
    public static function decisions(Collection $decisions): array
    {
        return array_values($decisions->sortBy('decided_at')->map(fn (ApprovalDecision $d) => [
            'id' => $d->id,
            'level' => (int) $d->level,
            'decision' => $d->decision,
            'decision_label' => $d->decision === 'approved' ? 'Aprobó' : 'Rechazó',
            'comment' => $d->comment,
            'decided_by' => $d->decidedBy?->name,
            'decided_at' => OperacionScope::iso($d->decided_at),
        ])->all());
    }

    /**
     * Niveles del flujo con su estado (completado, actual, pendiente, rechazado).
     *
     * @param  list<array{level:int, permission:string, label:string}>|null  $levels  niveles propios (p. ej. por tipo de incidencia)
     * @return list<array{level:int,label:string,state:string}>
     */
    public static function levels(ApprovalWorkflow $workflow, string $subject, Model $model, ?array $levels = null): array
    {
        $current = (int) ($model->getAttribute('current_level') ?? 1);
        $status = $model->getAttribute('status');

        return array_map(function (array $l) use ($current, $status) {
            $state = match (true) {
                $status === ApprovalStatus::Approved => 'done',
                $status === ApprovalStatus::Rejected && $l['level'] === $current => 'rejected',
                $status === ApprovalStatus::Cancelled => 'cancelled',
                $l['level'] < $current => 'done',
                $l['level'] === $current && $status === ApprovalStatus::Pending => 'current',
                default => 'pending',
            };

            return ['level' => $l['level'], 'label' => $l['label'], 'state' => $state];
        }, $workflow->levels($subject, $levels));
    }
}
