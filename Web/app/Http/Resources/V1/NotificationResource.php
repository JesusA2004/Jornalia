<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Contrato `AppNotification`.
 *
 * @mixin DatabaseNotification
 */
final class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DatabaseNotification $n */
        $n = $this->resource;
        $data = (array) $n->data;

        return [
            'id' => (string) $n->id,
            'kind' => (string) ($data['kind'] ?? 'general'),
            'title' => (string) ($data['title'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'category' => self::category($data),
            'priority' => ($data['priority'] ?? 'normal') === 'high' ? 'high' : 'normal',
            // Record<string,string>: siempre objeto JSON (también vacío).
            'data' => (object) array_map(static fn ($v): string => (string) $v, array_filter((array) ($data['data'] ?? []), 'is_scalar')),
            'read_at' => ApiFormat::dateTime($n->read_at),
            'created_at' => ApiFormat::dateTime($n->created_at),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function category(array $data): string
    {
        $category = $data['category'] ?? null;
        if (in_array($category, ['message', 'reminder', 'request', 'attendance', 'system'], true)) {
            return $category;
        }

        // Avisos anteriores sin categoría.
        return str_starts_with((string) ($data['kind'] ?? ''), 'request.') ? 'request' : 'system';
    }
}
