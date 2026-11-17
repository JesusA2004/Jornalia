<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Utilidades comunes de los controladores de administración.
 */
abstract class AdminController extends Controller
{
    use AuthorizesRequests;

    protected function organizationId(): int
    {
        return app(CurrentOrganization::class)->require()->id;
    }

    protected function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }

    /**
     * Garantiza que un registro (resuelto por ruta) pertenezca a la organización activa.
     */
    protected function ensureOwned(Model $model): void
    {
        abort_unless((int) $model->getAttribute('organization_id') === $this->organizationId(), 404);
    }

    /**
     * @param  iterable<Model>  $items
     * @return list<array{value:string, label:string, description?:string}>
     */
    protected function options(iterable $items, string $label = 'name', ?string $description = 'code'): array
    {
        $out = [];
        foreach ($items as $item) {
            $row = ['value' => (string) $item->getKey(), 'label' => (string) $item->getAttribute($label)];
            if ($description !== null && $item->getAttribute($description)) {
                $row['description'] = (string) $item->getAttribute($description);
            }
            $out[] = $row;
        }

        return $out;
    }
}
