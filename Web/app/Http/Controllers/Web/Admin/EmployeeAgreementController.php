<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Signatures\RegistrationDocuments;
use App\Models\Attachment;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Convenio de registro electrónico firmado en papel, adjunto al expediente
 * (PDF RH pantalla 09, punto 4). Cuenta como convenio firmado. Sin borrado:
 * cada archivo queda en el expediente con su huella SHA-256.
 */
class EmployeeAgreementController extends AdminController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('update', $employee);
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [], ['file' => 'convenio firmado']);

        $file = $request->file('file');
        $path = (string) $file->store("expedientes/{$employee->organization_id}/convenios", 'local');

        $attachment = Attachment::query()->create([
            'organization_id' => $employee->organization_id,
            'attachable_type' => $employee->getMorphClass(),
            'attachable_id' => $employee->id,
            'category' => RegistrationDocuments::PAPER_AGREEMENT_CATEGORY,
            'disk' => 'local',
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', Storage::disk('local')->path($path)),
            'uploaded_by' => $request->user()?->id,
        ]);

        $this->audit->log('employee.registration_agreement_attached', $employee, 'Convenio de registro firmado adjunto al expediente de '.$employee->fullName(), [
            'attachment_id' => $attachment->id, 'sha256' => $attachment->sha256,
        ]);
        $this->toast('Convenio adjunto al expediente.');

        return back();
    }

    public function download(Request $request, Employee $employee, int $attachment): StreamedResponse
    {
        $this->authorize('view', $employee);
        $file = Attachment::query()
            ->where('attachable_type', $employee->getMorphClass())
            ->where('attachable_id', $employee->id)
            ->where('category', RegistrationDocuments::PAPER_AGREEMENT_CATEGORY)
            ->whereKey($attachment)->firstOrFail();
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->download($file->path, $file->original_name);
    }
}
