<?php

namespace App\Domain\Integrations\People;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP de la estructura y bajas de People (`services.people.url` y
 * `services.people.token`). Sin credenciales queda «Sin configurar»: nunca
 * simula una conexión productiva.
 */
final class HttpPeopleStructureSource implements PeopleStructureSource
{
    private const MAX_PAGES = 200;

    public function isConfigured(): bool
    {
        return filled(config('services.people.url')) && filled(config('services.people.token'));
    }

    public function departments(): iterable
    {
        return $this->paginate('departments');
    }

    public function positions(): iterable
    {
        return $this->paginate('positions');
    }

    public function branches(): iterable
    {
        return $this->paginate('branches');
    }

    public function terminations(?string $since): iterable
    {
        return $this->paginate('terminations', array_filter(['since' => $since]));
    }

    /**
     * @param  array<string, string>  $query
     * @return \Generator<int, array<string, mixed>>
     */
    private function paginate(string $resource, array $query = []): \Generator
    {
        if (! $this->isConfigured()) {
            throw new PeopleSourceException('La integración con People no está configurada.');
        }
        $url = rtrim((string) config('services.people.url'), '/').'/'.$resource;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            try {
                $response = Http::withToken((string) config('services.people.token'))
                    ->acceptJson()->timeout(20)->retry(2, 500, throw: false)
                    ->get($url, $query + ['page' => $page]);
            } catch (ConnectionException $e) {
                throw new PeopleSourceException("No fue posible conectar con People ({$resource}).", previous: $e);
            }
            if (! $response->successful()) {
                throw new PeopleSourceException("People respondió con estado {$response->status()} en {$resource}.");
            }
            $data = $response->json('data');
            if (! is_array($data)) {
                throw new PeopleSourceException("Respuesta de People sin el arreglo «data» en {$resource}.");
            }
            foreach ($data as $row) {
                if (is_array($row)) {
                    yield $row;
                }
            }
            if (! $response->json('next_page_url')) {
                return;
            }
        }
    }
}
