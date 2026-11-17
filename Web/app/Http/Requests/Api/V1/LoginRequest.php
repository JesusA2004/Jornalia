<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrato `LoginRequest`.
 */
final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device' => ['required', 'array'],
            'device.device_uuid' => ['required', 'uuid'],
            'device.hardware_id' => ['nullable', 'string', 'max:128'],
            'device.name' => ['required', 'string', 'max:255'],
            'device.platform' => ['required', 'in:android,ios,web'],
            'device.os_version' => ['nullable', 'string', 'max:40'],
            'device.app_version' => ['required', 'string', 'max:40'],
            'device.model' => ['nullable', 'string', 'max:120'],
            'device.biometric_available' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array{device_uuid:string, name:string, platform:string, os_version:?string, app_version:string, model:?string, biometric_available:bool}
     */
    public function deviceInfo(): array
    {
        $d = (array) $this->validated('device');

        return [
            'device_uuid' => (string) $d['device_uuid'],
            'hardware_id' => isset($d['hardware_id']) ? (string) $d['hardware_id'] : null,
            'name' => (string) $d['name'],
            'platform' => (string) $d['platform'],
            'os_version' => $d['os_version'] ?? null,
            'app_version' => (string) $d['app_version'],
            'model' => $d['model'] ?? null,
            'biometric_available' => (bool) $d['biometric_available'],
        ];
    }
}
