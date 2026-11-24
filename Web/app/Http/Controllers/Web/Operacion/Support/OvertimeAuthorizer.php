<?php

namespace App\Http\Controllers\Web\Operacion\Support;

use App\Domain\Approvals\ApprovalWorkflow;
use App\Domain\Legal\WeeklyOvertimeCalculator;
use App\Enums\ApprovalStatus;
use App\Models\OvertimeRecord;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Autorización de tiempo extraordinario mediante ApprovalWorkflow (niveles
 * configurados en approval_flows, no auto-aprobación, alcance, bitácora).
 *
 * Nunca se ocultan ni reducen los excedentes registrados: en la aprobación
 * final sólo se fija cuánto se autoriza (≤ excedente registrado) con su
 * justificación.
 */
final class OvertimeAuthorizer
{
    public function __construct(private readonly ApprovalWorkflow $workflow) {}

    public function decide(User $user, OvertimeRecord $record, bool $approve, ?int $minutes, string $justification): OvertimeRecord
    {
        $minutes = $approve ? (int) $minutes : 0;
        // CPEUM art. 123-A fr. XI y LFT art. 178: prohibido el tiempo extra de menores
        // de 18 años. La salida real queda registrada; sólo se bloquea autorizarlo.
        $employee = $record->employee()->withoutGlobalScopes()->first();
        if ($approve && $employee !== null && WeeklyOvertimeCalculator::isMinorOn($employee, $record->date) === true) {
            throw ValidationException::withMessages(['authorized_minutes' => 'Prohibido autorizar tiempo extra a menores de 18 años. Sólo puede justificarse.']);
        }
        if ($minutes > (int) $record->recorded_excess_minutes) {
            throw ValidationException::withMessages(['authorized_minutes' => 'No puedes autorizar más minutos que el excedente registrado ('.$record->recorded_excess_minutes.' min).']);
        }
        if ($approve && $minutes <= 0) {
            throw ValidationException::withMessages(['authorized_minutes' => 'Indica los minutos a autorizar.']);
        }

        $final = $this->workflow->decide($user, $record, 'overtime', $approve, $justification, function (OvertimeRecord $r) use ($user, $minutes, $justification) {
            $r->forceFill([
                'authorized_minutes' => $minutes,
                'authorized_by' => $user->id,
                'authorized_at' => now(),
                'justification' => $justification,
            ])->save();
        });

        $record->refresh();
        if ($final && $record->status === ApprovalStatus::Rejected) {
            $record->forceFill(['authorized_minutes' => 0, 'justification' => $justification])->save();
        }

        return $record;
    }
}
