<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Signatures\RegistrationDocuments;
use App\Models\Employee;
use App\Models\PolicyDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos del sistema de registro para acuse de los colaboradores.
 * Un documento publicado es inmutable (texto y PDF): los cambios requieren una
 * nueva versión. El aviso de privacidad (`AVISO_PRIVACIDAD`) exige PDF para
 * publicarse; el convenio (`CONVENIO_REGISTRO`) puede llevar PDF.
 */
class PolicyDocumentController extends AdminController
{
    /** Documentos del registro electrónico con plantilla en Configuración › Documentos. */
    public const REGISTRATION_CODES = [
        RegistrationDocuments::PRIVACY => 'Aviso de privacidad para colaboradores',
        RegistrationDocuments::AGREEMENT => 'Convenio de registro electrónico',
    ];

    /** Documentos que no pueden publicarse sin su PDF oficial. */
    public const PDF_REQUIRED = [RegistrationDocuments::PRIVACY];

    public const PDF_DISK = 'local';

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $activeEmployees = Employee::query()->where('status', 'active')->count();

        $documents = PolicyDocument::query()->withTrashed()->withCount('acknowledgements')->with('createdBy:id,name')
            ->orderBy('code')->orderByDesc('created_at')->get()
            ->map(fn (PolicyDocument $d) => [
                'id' => $d->id,
                'code' => $d->code,
                'title' => $d->title,
                'version' => $d->version,
                'body_markdown' => $d->body_markdown,
                'sha256' => $d->sha256,
                'requires_acknowledgement' => $d->requires_acknowledgement,
                'published_at' => $d->published_at?->toIso8601String(),
                'created_at' => $d->created_at?->toIso8601String(),
                'created_by' => $d->createdBy?->name,
                'acknowledgements_count' => $d->acknowledgements_count,
                'deleted' => $d->trashed(),
                'file_name' => $d->file_name,
                'file_sha256' => $d->file_sha256,
                'file_size' => $d->file_size !== null ? (int) $d->file_size : null,
                'file_url' => $d->file_path ? route('configuracion.documents.pdf', $d->id) : null,
            ]);

        return Inertia::render('configuracion/Documents', [
            'documents' => $documents->values(),
            'active_employees' => $activeEmployees,
            'registration_codes' => collect(self::REGISTRATION_CODES)->map(fn ($title, $code) => ['code' => $code, 'title' => $title, 'pdf_required' => in_array($code, self::PDF_REQUIRED, true)])->values(),
            'can' => ['manage' => $request->user()?->can('settings.documents') ?? false],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $doc = PolicyDocument::query()->create($data + [
            'sha256' => hash('sha256', $data['body_markdown']),
            'created_by' => $request->user()?->id,
        ]);
        $this->audit->log('policy_document.created', $doc, "Borrador creado: {$doc->title} v{$doc->version}", ['code' => $doc->code, 'sha256' => $doc->sha256]);
        $this->toast('Borrador guardado. Publícalo cuando esté listo para acuse.');

        return back();
    }

    public function update(Request $request, PolicyDocument $policyDocument): RedirectResponse
    {
        $this->ensureOwned($policyDocument);
        $this->ensureDraft($policyDocument);
        $data = $this->validated($request, $policyDocument);
        $policyDocument->update($data + ['sha256' => hash('sha256', $data['body_markdown'])]);

        $this->audit->log('policy_document.updated', $policyDocument, "Borrador actualizado: {$policyDocument->title} v{$policyDocument->version}", ['sha256' => $policyDocument->sha256]);
        $this->toast('Borrador actualizado.');

        return back();
    }

    public function publish(Request $request, PolicyDocument $policyDocument): RedirectResponse
    {
        $this->ensureOwned($policyDocument);
        $this->ensureDraft($policyDocument);
        if (trim($policyDocument->body_markdown) === '') {
            throw ValidationException::withMessages(['body_markdown' => 'El documento no tiene contenido.']);
        }
        if (in_array($policyDocument->code, self::PDF_REQUIRED, true) && $policyDocument->file_path === null) {
            throw ValidationException::withMessages(['file' => 'Sube el PDF del aviso de privacidad antes de publicarlo.']);
        }

        $policyDocument->forceFill([
            'sha256' => hash('sha256', $policyDocument->body_markdown),
            'published_at' => now(),
        ])->save();

        $this->audit->log('policy_document.published', $policyDocument, "Documento publicado: {$policyDocument->title} v{$policyDocument->version}", ['code' => $policyDocument->code, 'sha256' => $policyDocument->sha256, 'file_sha256' => $policyDocument->file_sha256]);
        $this->toast('Documento publicado. A partir de ahora es inmutable.');

        return back();
    }

    /**
     * Carga (o reemplaza, sólo en borrador) el PDF del documento. Los archivos
     * anteriores se conservan; el publicado es inmutable.
     */
    public function uploadPdf(Request $request, PolicyDocument $policyDocument): RedirectResponse
    {
        $this->ensureOwned($policyDocument);
        $this->ensureDraft($policyDocument);
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:10240'],
        ], ['file.mimes' => 'El archivo debe ser PDF.', 'file.mimetypes' => 'El archivo debe ser PDF.'], ['file' => 'PDF']);

        $file = $request->file('file');
        $dir = 'policy-documents/'.$this->organizationId();
        $path = $file->storeAs($dir, $policyDocument->code.'-'.$policyDocument->version.'-'.bin2hex(random_bytes(6)).'.pdf', self::PDF_DISK);
        $before = ['file_path' => $policyDocument->file_path, 'file_sha256' => $policyDocument->file_sha256];
        $policyDocument->forceFill([
            'file_path' => $path,
            'file_name' => mb_substr((string) $file->getClientOriginalName(), 0, 200),
            'file_sha256' => hash_file('sha256', Storage::disk(self::PDF_DISK)->path((string) $path)),
            'file_size' => $file->getSize(),
        ])->save();

        $this->audit->log('policy_document.pdf_uploaded', $policyDocument, "PDF cargado: {$policyDocument->title} v{$policyDocument->version}", [], null, $before, ['file_path' => $path, 'file_sha256' => $policyDocument->file_sha256]);
        $this->toast('PDF cargado.');

        return back();
    }

    public function pdf(Request $request, PolicyDocument $policyDocument): StreamedResponse
    {
        $this->ensureOwned($policyDocument);
        abort_if($policyDocument->file_path === null || ! Storage::disk(self::PDF_DISK)->exists($policyDocument->file_path), 404);

        return Storage::disk(self::PDF_DISK)->response($policyDocument->file_path, $policyDocument->code.'-'.$policyDocument->version.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function newVersion(Request $request, PolicyDocument $policyDocument): RedirectResponse
    {
        $this->ensureOwned($policyDocument);
        if ($policyDocument->published_at === null) {
            throw ValidationException::withMessages(['version' => 'Edita el borrador existente en lugar de crear otra versión.']);
        }
        $data = $request->validate([
            'version' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-]+$/', Rule::unique('policy_documents', 'version')->where('organization_id', $this->organizationId())->where('code', $policyDocument->code)],
        ], ['version.unique' => 'Esa versión ya existe para este documento.'], ['version' => 'versión']);

        $draft = PolicyDocument::query()->create([
            'code' => $policyDocument->code,
            'title' => $policyDocument->title,
            'version' => $data['version'],
            'body_markdown' => $policyDocument->body_markdown,
            'sha256' => hash('sha256', $policyDocument->body_markdown),
            'requires_acknowledgement' => $policyDocument->requires_acknowledgement,
            'created_by' => $request->user()?->id,
        ]);
        $this->audit->log('policy_document.versioned', $draft, "Nueva versión en borrador: {$draft->title} v{$draft->version}", ['from_id' => $policyDocument->id]);
        $this->toast("Borrador v{$draft->version} creado a partir de la versión publicada.");

        return back();
    }

    public function deactivate(Request $request, PolicyDocument $policyDocument): RedirectResponse
    {
        $this->ensureOwned($policyDocument);
        $this->ensureDraft($policyDocument);
        $policyDocument->delete();
        $this->audit->log('policy_document.discarded', $policyDocument, "Borrador descartado: {$policyDocument->title} v{$policyDocument->version}");
        $this->toast('Borrador archivado.');

        return back();
    }

    public function restore(Request $request, PolicyDocument $policyDocument): RedirectResponse
    {
        $this->ensureOwned($policyDocument);
        $policyDocument->restore();
        $this->audit->log('policy_document.restored', $policyDocument, "Borrador recuperado: {$policyDocument->title} v{$policyDocument->version}");
        $this->toast('Borrador recuperado.');

        return back();
    }

    private function ensureDraft(PolicyDocument $doc): void
    {
        if ($doc->published_at !== null) {
            throw ValidationException::withMessages(['document' => 'Un documento publicado no puede modificarse: crea una nueva versión.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PolicyDocument $doc): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/'],
            'title' => ['required', 'string', 'max:200'],
            'version' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-]+$/', Rule::unique('policy_documents', 'version')->where('organization_id', $this->organizationId())->where('code', $request->input('code'))->ignore($doc?->id)],
            'body_markdown' => ['required', 'string', 'max:200000'],
            'requires_acknowledgement' => ['required', 'boolean'],
        ], ['version.unique' => 'Esa versión ya existe para este documento.', 'code.regex' => 'La clave sólo admite mayúsculas, números, guion y guion bajo.'], [
            'code' => 'clave', 'title' => 'título', 'version' => 'versión', 'body_markdown' => 'contenido',
        ]);
    }
}
