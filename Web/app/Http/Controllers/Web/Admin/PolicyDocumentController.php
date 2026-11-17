<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Models\Employee;
use App\Models\PolicyDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Documentos del sistema de registro para acuse de los colaboradores.
 * Un documento publicado es inmutable: los cambios requieren una nueva versión.
 */
class PolicyDocumentController extends AdminController
{
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
            ]);

        return Inertia::render('configuracion/Documents', [
            'documents' => $documents->values(),
            'active_employees' => $activeEmployees,
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

        $policyDocument->forceFill([
            'sha256' => hash('sha256', $policyDocument->body_markdown),
            'published_at' => now(),
        ])->save();

        $this->audit->log('policy_document.published', $policyDocument, "Documento publicado: {$policyDocument->title} v{$policyDocument->version}", ['code' => $policyDocument->code, 'sha256' => $policyDocument->sha256]);
        $this->toast('Documento publicado. A partir de ahora es inmutable.');

        return back();
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
