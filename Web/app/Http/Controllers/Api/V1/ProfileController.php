<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Api\V1\Support\ApiContext;
use App\Http\Controllers\Api\V1\Support\ProfilePresenter;
use App\Http\Requests\Api\V1\AcknowledgeRequest;
use App\Models\DocumentAcknowledgement;
use App\Models\PolicyDocument;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class ProfileController extends ApiController
{
    public function __construct(
        private readonly ApiContext $context,
        private readonly ProfilePresenter $profiles,
        private readonly CurrentOrganization $current,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /profile → Profile
     */
    public function show(Request $request): JsonResponse
    {
        return $this->data($this->profiles->present($this->user($request), $this->current->require()));
    }

    /**
     * POST /acknowledgements/{id} → 204. Acuse inmutable del documento publicado.
     */
    public function acknowledge(AcknowledgeRequest $request, int $document): Response
    {
        $user = $this->user($request);
        $employee = $this->context->employee($user);

        $doc = PolicyDocument::query()
            ->whereKey($document)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->firstOrFail();

        $already = DocumentAcknowledgement::query()
            ->where('policy_document_id', $doc->id)
            ->where('employee_id', $employee->id)
            ->exists();

        if (! $already) {
            $device = $this->context->deviceByUuid($user, (string) $request->validated('device_uuid'));
            $at = CarbonImmutable::now('UTC');

            try {
                $ack = DocumentAcknowledgement::query()->create([
                    'organization_id' => $doc->organization_id,
                    'policy_document_id' => $doc->id,
                    'employee_id' => $employee->id,
                    'user_id' => $user->id,
                    'device_id' => $device?->id,
                    'document_sha256' => $doc->sha256,
                    'ip_address' => $request->ip(),
                    'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
                    'hash' => hash('sha256', implode('|', [$doc->sha256, $employee->id, $user->id, $at->format('Y-m-d\TH:i:s.u\Z')])),
                    'acknowledged_at' => $at,
                ]);
                $this->audit->log('document.acknowledged', $ack, "Acuse de «{$doc->title}» v{$doc->version}");
            } catch (UniqueConstraintViolationException) {
                // Envío concurrente: el acuse ya existe (idempotente).
            }
        }

        return response()->noContent();
    }
}
