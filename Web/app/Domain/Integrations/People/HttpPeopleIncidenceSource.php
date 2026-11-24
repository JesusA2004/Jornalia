<?php

namespace App\Domain\Integrations\People;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP de incidencias aprobadas en People (`services.people.url` y
 * `services.people.token`). Sin credenciales queda «Sin configurar»: nunca
 * simula una conexión productiva.
 *
 * Contrato esperado: GET {url}/incidences?status=approved&since=YYYY-MM-DD&page=N
 * → { data: [...], next_page_url: string|null }.
 */
final class HttpPeopleIncidenceSource implements PeopleIncidenceSource
{
    private const MAX_PAGES = 200;

    public function isConfigured(): bool
    {
        return filled(config('services.people.url')) && filled(config('services.people.token'));
    }

    public function approvedSince(?string $since): iterable
    {
        if (! $this->isConfigured()) {
            throw new PeopleSourceException('La integración con People no está configurada.');
        }

        $url = rtrim((string) config('services.people.url'), '/').'/incidences';

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            try {
                $response = Http::withToken((string) config('services.people.token'))
                    ->acceptJson()->timeout(20)->retry(2, 500, throw: false)
                    ->get($url, array_filter(['status' => 'approved', 'since' => $since, 'page' => $page]));
            } catch (ConnectionException $e) {
                throw new PeopleSourceException('No fue posible conectar con People.', previous: $e);
            }

            if (! $response->successful()) {
                throw new PeopleSourceException('People respondió con estado '.$response->status().'.');
            }

            $data = $response->json('data');
            if (! is_array($data)) {
                throw new PeopleSourceException('Respuesta de People sin el arreglo «data».');
            }

            foreach ($data as $row) {
                if (is_array($row)) {
                    yield $row;
                }
            }

            if (! $response->json('next_page_url')) {
                break;
            }
        }
    }
}
