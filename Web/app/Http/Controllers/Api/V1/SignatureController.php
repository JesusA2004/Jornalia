<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Signatures\ElectronicSignatureService;
use App\Domain\Signatures\RegistrationDocuments;
use App\Domain\Signatures\SignaturePinService;
use App\Http\Controllers\Api\V1\Support\ApiContext;
use App\Http\Requests\Api\V1\SignaturePinRequest;
use App\Http\Requests\Api\V1\SignRequest;
use App\Http\Resources\V1\ApiFormat;
use App\Models\ElectronicSignature;
use App\Models\Employee;
use App\Models\PolicyDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * NIP de firma, convenio de registro electrónico y aviso de privacidad.
 *
 * Los PDF se entregan con URL firmada temporal (10 min) porque el navegador
 * del teléfono no puede enviar el token Bearer.
 */
final class SignatureController extends ApiController
{
    public const SIGNED_URL_MINUTES = 10;

    public function __construct(
        private readonly ApiContext $context,
        private readonly SignaturePinService $pins,
        private readonly RegistrationDocuments $documents,
    ) {}

    /**
     * POST /profile/signature-pin {pin, current_pin?} → {has_pin, set_at}
     */
    public function pin(SignaturePinRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $this->pins->set($user, (string) $request->validated('pin'), $request->validated('current_pin'));

        return $this->data(['has_pin' => true, 'set_at' => ApiFormat::dateTime($user->refresh()->signature_pin_set_at)]);
    }

    /**
     * GET /documents/pending → PendingDocument[] (convenio y aviso vigentes sin firmar).
     */
    public function pending(Request $request): JsonResponse
    {
        $employee = $this->context->employee($this->user($request));

        return $this->data(array_map(fn (PolicyDocument $d) => $this->presentDocument($d, $employee), $this->documents->pending($employee)));
    }

    /**
     * GET /documents → SignedDocument[] (convenio y aviso firmados por el colaborador, todas las versiones).
     */
    public function index(Request $request): JsonResponse
    {
        $employee = $this->context->employee($this->user($request));

        $signatures = ElectronicSignature::query()
            ->where('employee_id', $employee->id)
            ->where('signable_type', (new PolicyDocument)->getMorphClass())
            ->whereIn('purpose', array_values(RegistrationDocuments::PURPOSES))
            ->orderByDesc('signed_at')
            ->get();
        $docs = PolicyDocument::query()->withTrashed()->whereIn('id', $signatures->pluck('signable_id'))->get()->keyBy('id');

        return $this->data($signatures->map(function (ElectronicSignature $s) use ($docs) {
            $doc = $docs->get($s->signable_id);

            return $doc === null ? null : [
                'id' => $doc->id,
                'code' => $doc->code,
                'title' => $doc->title,
                'version' => $doc->version,
                'body_markdown' => $doc->body_markdown,
                'pdf_url' => self::pdfUrl($doc),
                'signed_at' => ApiFormat::dateTime($s->signed_at),
                'signature_method' => $s->method,
                'hash' => $s->hash,
            ];
        })->filter()->values()->all());
    }

    /**
     * POST /documents/{id}/sign {method, pin?, device_signature?} → {document_id, signed_at, method, hash}
     */
    public function sign(SignRequest $request, int $document): JsonResponse
    {
        $user = $this->user($request);
        $employee = $this->context->employee($user);
        $doc = PolicyDocument::query()->whereNotNull('published_at')->findOrFail($document);
        $device = $this->context->deviceByUuid($user, ApiContext::tokenDeviceUuid($request));

        $signature = $this->documents->sign(
            $user, $employee, $doc, (string) $request->validated('method'), $request->validated('pin'),
            $device, $request, $request->validated('device_signature'),
        );

        return $this->data([
            'document_id' => $doc->id,
            'code' => $doc->code,
            'signed_at' => ApiFormat::dateTime($signature->signed_at),
            'method' => $signature->method,
            'hash' => $signature->hash,
        ]);
    }

    /**
     * GET /documents/{id}/pdf (Bearer) → PDF del documento publicado.
     */
    public function pdf(Request $request, int $document): Response
    {
        $this->context->employee($this->user($request));
        $doc = PolicyDocument::query()->whereNotNull('published_at')->where('published_at', '<=', now())->findOrFail($document);

        return self::render($doc);
    }

    /**
     * GET /files/documents/{id} (URL firmada temporal, sin Bearer) → PDF.
     * La firma de la URL acredita el acceso; el documento es el mismo para
     * toda la organización y sólo se emite a colaboradores autenticados.
     */
    public function signedPdf(int $document): Response
    {
        $doc = PolicyDocument::query()->withoutGlobalScopes()->whereNotNull('published_at')->findOrFail($document);

        return self::render($doc);
    }

    public static function pdfUrl(PolicyDocument $doc): string
    {
        return URL::temporarySignedRoute('api.v1.files.document', now()->addMinutes(self::SIGNED_URL_MINUTES), ['document' => $doc->id]);
    }

    private static function render(PolicyDocument $doc): Response
    {
        $name = Str::slug($doc->code.'-'.$doc->version).'.pdf';

        // PDF oficial cargado por RH en Configuración › Documentos (versionado e inmutable).
        if ($doc->file_path && Storage::disk('local')->exists($doc->file_path)) {
            return Storage::disk('local')->response($doc->file_path, $name, ['Content-Type' => 'application/pdf']);
        }

        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;line-height:1.5}h1{font-size:16px}</style></head><body>'
            .'<h1>'.e($doc->title).'</h1><p>Versión '.e($doc->version).' · SHA-256 '.e($doc->sha256).'</p>'
            .Str::markdown((string) $doc->body_markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false])
            .'</body></html>';

        return Pdf::loadHTML($html)->setPaper('letter')->stream($name);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDocument(PolicyDocument $doc, Employee $employee): array
    {
        $statement = RegistrationDocuments::statement($doc);
        $purpose = RegistrationDocuments::PURPOSES[$doc->code];

        return [
            'id' => $doc->id,
            'code' => $doc->code,
            'purpose' => $purpose,
            'title' => $doc->title,
            'version' => $doc->version,
            'body_markdown' => $doc->body_markdown,
            'sha256' => $doc->sha256,
            'published_at' => ApiFormat::dateTime($doc->published_at),
            'pdf_url' => self::pdfUrl($doc),
            'signature_statement' => $statement,
            'signature_payload_sha256' => ElectronicSignatureService::payloadHashFor($purpose, $doc, (int) $employee->id, $statement),
        ];
    }
}
