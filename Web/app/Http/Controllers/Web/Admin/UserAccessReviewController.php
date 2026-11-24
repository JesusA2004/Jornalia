<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Security\UserAccessService;
use App\Http\Controllers\Web\Admin\Concerns\GuardsAdministrators;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAccessReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Revisión trimestral de usuarios activos (PDF pantalla 18, punto 4).
 */
class UserAccessReviewController extends AdminController
{
    use GuardsAdministrators;

    public function __construct(private readonly UserAccessService $access) {}

    public function index(Request $request): Response
    {
        $organization = $this->organization();
        $review = UserAccessReview::query()->latest('id')->first();

        return Inertia::render('usuarios/Revision', [
            'review' => $review ? [
                'id' => $review->id,
                'period' => $review->period,
                'due_on' => $review->due_on->toDateString(),
                'status' => $review->status,
                'decisions' => (object) ($review->decisions ?? []),
                'summary' => $review->summary,
                'completed_at' => $review->completed_at?->toIso8601String(),
                'completed_by' => $review->completedBy?->name,
            ] : null,
            'history' => UserAccessReview::query()->latest('id')->limit(8)->get(['id', 'period', 'status', 'completed_at'])
                ->map(fn (UserAccessReview $r) => ['id' => $r->id, 'period' => $r->period, 'status' => $r->status, 'completed_at' => $r->completed_at?->toIso8601String()])->values(),
            'users' => $this->access->reviewRows($organization),
            'inactive_after_days' => (int) config('security.inactive_after_days', 90),
            'can' => ['manage' => $request->user()?->can('users.manage') ?? false],
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $review = $this->access->openQuarterlyReview($this->organization());
        $this->toast($review->wasRecentlyCreated ? "Revisión {$review->period} abierta." : "La revisión {$review->period} ya estaba abierta.");

        return back();
    }

    public function decide(Request $request, UserAccessReview $review, User $user): RedirectResponse
    {
        $org = $this->organizationId();
        $this->ensureOwned($review);
        abort_unless(DB::table('organization_user')->where('organization_id', $org)->where('user_id', $user->id)->exists(), 404);
        if (! $review->isOpen()) {
            throw ValidationException::withMessages(['review' => 'La revisión ya está cerrada.']);
        }

        $data = $request->validate([
            'decision' => ['required', Rule::in(['keep', 'deactivate'])],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['decision' => 'decisión', 'note' => 'nota']);

        $actor = $this->actor($request);
        if ($data['decision'] === 'deactivate') {
            if ($user->id === $actor->id) {
                throw ValidationException::withMessages(['decision' => 'No puedes desactivar tu propio acceso.']);
            }
            $this->assertNotHigher($actor, $user, $org);
            $snapshot = $this->adminSnapshot($actor, $org);
            DB::transaction(function () use ($review, $user, $data, $actor, $org, $snapshot) {
                $this->access->decide($review, $user, 'deactivate', $actor, $data['note'] ?? null);
                $this->assertAdministratorsRemain($actor, $org, $snapshot);
            });
        } else {
            $this->access->decide($review, $user, 'keep', $actor, $data['note'] ?? null);
        }
        $this->toast('Decisión registrada.');

        return back();
    }

    public function complete(Request $request, UserAccessReview $review): RedirectResponse
    {
        $this->ensureOwned($review);
        if (! $review->isOpen()) {
            throw ValidationException::withMessages(['review' => 'La revisión ya está cerrada.']);
        }
        $this->access->complete($review, $this->actor($request), $this->organization());
        $this->toast("Revisión {$review->period} completada.");

        return back();
    }

    private function organization(): Organization
    {
        return Organization::query()->findOrFail($this->organizationId());
    }

    /**
     * Sin `users.roles` no puede desactivarse a quien tiene permisos que el actor no tiene.
     */
    private function assertNotHigher(User $actor, User $user, int $org): void
    {
        if ($actor->can('users.roles')) {
            return;
        }
        $theirs = Role::query()->where('organization_id', $org)
            ->whereIn('id', DB::table('model_has_roles')->where('organization_id', $org)->where('model_id', $user->id)->where('model_type', $user->getMorphClass())->select('role_id'))
            ->with('permissions:id,name')->get()->flatMap(fn (Role $r) => $r->permissions->pluck('name'))->unique();
        if ($theirs->diff($actor->getAllPermissions()->pluck('name'))->isNotEmpty()) {
            throw ValidationException::withMessages(['decision' => 'No puedes desactivar a un usuario con permisos superiores a los tuyos.']);
        }
    }
}
