<?php

namespace App\Domain\Notifications\Fcm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente FCM HTTP v1 (adaptado de auditoria/ClienteFcm).
 *
 *  - OAuth2 con cuenta de servicio: JWT RS256 firmado con openssl → token de
 *    acceso, cacheado ~50 min bajo una clave derivada de un hash del proyecto.
 *  - Presupuesto de tiempo acotado; reintentos sólo para fallas transitorias
 *    (los decide la cola); TLS siempre verificado.
 *  - Nunca registra ni devuelve el token de acceso, la clave privada ni el
 *    token del dispositivo.
 *  - Sin interruptor, proyecto o credencial válida: deshabilitado (estado honesto).
 */
class FcmClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    /** @var array{client_email: string, private_key: string, token_uri: string}|null */
    private ?array $credential = null;

    private bool $credentialResolved = false;

    public function enabled(): bool
    {
        return (bool) config('fcm.enabled')
            && $this->projectId() !== null
            && $this->credential() !== null;
    }

    /**
     * Envía un mensaje (notification + data) a un token. Nunca lanza excepción.
     *
     * @param  array<string, string>  $data  claves y valores de texto, sin datos sensibles
     */
    public function send(string $token, string $title, string $body, array $data, bool $highPriority = true): FcmResult
    {
        if (! $this->enabled()) {
            return FcmResult::disabled();
        }

        try {
            $accessToken = $this->accessToken();
        } catch (Throwable $e) {
            Log::warning('fcm_oauth2_failed', ['reason' => class_basename($e)]);

            return FcmResult::permanent('oauth2');
        }
        if ($accessToken === null) {
            return FcmResult::permanent('credencial_invalida');
        }

        $budget = max(1, (int) config('fcm.budget_ms', 8000)) / 1000;
        $connect = max(1, (int) config('fcm.connect_ms', 2000)) / 1000;
        $endpoint = "https://fcm.googleapis.com/v1/projects/{$this->projectId()}/messages:send";

        $message = [
            'message' => [
                'token' => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => array_map(static fn ($v): string => (string) $v, $data),
                'android' => [
                    'priority' => $highPriority ? 'high' : 'normal',
                    'notification' => ['channel_id' => (string) config('fcm.android_channel_id', 'avisos')],
                ],
                'apns' => ['headers' => ['apns-priority' => $highPriority ? '10' : '5']],
            ],
        ];

        try {
            $request = Http::withToken($accessToken)
                ->timeout((int) ceil($budget))
                ->connectTimeout((int) ceil($connect))
                ->acceptJson();
            $ca = config('fcm.ca');
            if (is_string($ca) && $ca !== '') {
                $request = $request->withOptions(['verify' => $ca]);
            }
            $response = $request->post($endpoint, $message);
        } catch (ConnectionException) {
            return FcmResult::transient('conexion');
        } catch (Throwable) {
            return FcmResult::transient('red');
        }

        if ($response->successful()) {
            return FcmResult::sent((string) ($response->json('name') ?? ''));
        }

        $code = $this->errorCode($response->json());
        $status = $response->status();

        if ($status === 404 || in_array($code, ['UNREGISTERED', 'NOT_FOUND'], true)) {
            return FcmResult::deadToken($code ?? 'UNREGISTERED');
        }
        if ($code === 'INVALID_ARGUMENT' && $status === 400) {
            // Token con formato inválido: tampoco servirá en un reintento.
            return FcmResult::deadToken('INVALID_ARGUMENT');
        }
        if ($status === 429 || $status >= 500) {
            return FcmResult::transient($code ?? (string) $status);
        }
        if ($status === 401) {
            // Token de acceso vencido o revocado: el siguiente intento emite uno nuevo.
            Cache::forget($this->cacheKey());
        }

        return FcmResult::permanent($code ?? (string) $status);
    }

    /**
     * Diagnóstico de sólo lectura (sí/no). Nunca expone la credencial ni la ruta.
     *
     * @return array{switch: bool, project_id: bool, credential: string, enabled: bool}
     */
    public function diagnostics(): array
    {
        return [
            'switch' => (bool) config('fcm.enabled'),
            'project_id' => $this->projectId() !== null,
            'credential' => $this->inspectCredential()['state'],
            'enabled' => $this->enabled(),
        ];
    }

    private function errorCode(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }
        $details = $body['error']['details'] ?? [];
        foreach (is_array($details) ? $details : [] as $d) {
            if (is_array($d) && isset($d['errorCode']) && is_string($d['errorCode'])) {
                return $d['errorCode'];
            }
        }
        $status = $body['error']['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    private function cacheKey(): string
    {
        return 'fcm:oauth2:'.substr(hash('sha256', (string) $this->projectId()), 0, 16);
    }

    private function accessToken(): ?string
    {
        $cred = $this->credential();
        if ($cred === null) {
            return null;
        }

        $cached = Cache::get($this->cacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $jwt = $this->signJwt($cred);
        if ($jwt === null) {
            return null;
        }

        try {
            $response = Http::asForm()->timeout(10)->connectTimeout(5)->post($cred['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
        } catch (Throwable) {
            return null;
        }

        $token = $response->successful() ? $response->json('access_token') : null;
        if (! is_string($token) || $token === '') {
            return null;
        }
        $ttl = max(60, min(3000, (int) ($response->json('expires_in') ?? 3600) - 300));
        Cache::put($this->cacheKey(), $token, $ttl);

        return $token;
    }

    /**
     * @param  array{client_email: string, private_key: string, token_uri: string}  $cred
     */
    private function signJwt(array $cred): ?string
    {
        $now = time();
        $header = $this->base64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64url((string) json_encode([
            'iss' => $cred['client_email'],
            'scope' => self::SCOPE,
            'aud' => $cred['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signature = '';
        try {
            $ok = openssl_sign("{$header}.{$claims}", $signature, $cred['private_key'], OPENSSL_ALGO_SHA256);
        } catch (Throwable) {
            $ok = false;
        }

        return $ok ? "{$header}.{$claims}.".$this->base64url($signature) : null;
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function projectId(): ?string
    {
        $id = config('fcm.project_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @return array{client_email: string, private_key: string, token_uri: string}|null
     */
    private function credential(): ?array
    {
        if (! $this->credentialResolved) {
            $this->credentialResolved = true;
            $inspection = $this->inspectCredential();
            if ($inspection['state'] === 'formato_invalido') {
                Log::warning('fcm_credential_malformed');
            }
            $this->credential = $inspection['data'];
        }

        return $this->credential;
    }

    /**
     * state: ok | sin_credencial | ilegible | formato_invalido
     *
     * @return array{state: string, data: array{client_email: string, private_key: string, token_uri: string}|null}
     */
    private function inspectCredential(): array
    {
        $json = null;
        $inline = config('fcm.credentials_json');
        $path = config('fcm.credentials_path');

        if (is_string($inline) && $inline !== '') {
            $json = $inline;
        } elseif (is_string($path) && $path !== '') {
            if (! is_file($path)) {
                return ['state' => 'sin_credencial', 'data' => null];
            }
            $content = @file_get_contents($path);
            if ($content === false) {
                return ['state' => 'ilegible', 'data' => null];
            }
            $json = $content;
        }

        if ($json === null) {
            return ['state' => 'sin_credencial', 'data' => null];
        }

        $data = json_decode($json, true);
        if (! is_array($data) || ! is_string($data['client_email'] ?? null) || ! is_string($data['private_key'] ?? null)) {
            return ['state' => 'formato_invalido', 'data' => null];
        }

        return ['state' => 'ok', 'data' => [
            'client_email' => $data['client_email'],
            'private_key' => $data['private_key'],
            'token_uri' => is_string($data['token_uri'] ?? null) ? $data['token_uri'] : self::TOKEN_ENDPOINT,
        ]];
    }
}
