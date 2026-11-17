<?php

namespace App\Domain\Integrations\People;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Fuente HTTP de MR LANA People. Se configura con `services.people.url` y
 * `services.people.token`; si no están configurados queda deshabilitada.
 *
 * Contrato esperado: GET {url}/employees?page=N →
 * { data: [ {employee_number, first_name, last_name, ..., external_id} ], next_page_url: string|null }.
 */
final class HttpPeopleSource implements PeopleSource
{
    private const MAX_PAGES = 200;

    public function name(): string
    {
        return 'people_api';
    }

    public function externalSource(): string
    {
        return 'people';
    }

    public function isAvailable(): bool
    {
        return filled(config('services.people.url')) && filled(config('services.people.token'));
    }

    public function records(): iterable
    {
        if (! $this->isAvailable()) {
            throw new PeopleSourceException('La integración con People no está configurada.');
        }

        $url = rtrim((string) config('services.people.url'), '/').'/employees';
        $line = 0;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            try {
                $response = Http::withToken((string) config('services.people.token'))
                    ->acceptJson()->timeout(20)->retry(2, 500, throw: false)
                    ->get($url, ['page' => $page]);
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
                    yield PersonRecord::fromArray(++$line, $row);
                }
            }

            if (! $response->json('next_page_url')) {
                break;
            }
        }
    }
}
