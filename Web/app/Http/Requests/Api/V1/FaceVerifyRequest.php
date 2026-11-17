<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Contrato `FaceVerifyRequest` (multipart/form-data):
 * - challenge_id: uuid del reto
 * - frames[]: 6–10 JPEG (≤ 1.5 MB c/u), en orden de captura
 * - timestamps[]: ms desde que la app recibió el reto, estrictamente crecientes
 * - mirrored: "1" si los cuadros están espejados (vista selfie)
 */
final class FaceVerifyRequest extends FormRequest
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
        $min = (int) config('biometrics.verification.min_frames', 6);
        $max = (int) config('biometrics.verification.max_frames', 10);
        $kb = (int) config('biometrics.verification.max_frame_kb', 1500);

        return [
            'challenge_id' => ['required', 'uuid'],
            'frames' => ['required', 'array', "min:{$min}", "max:{$max}"],
            'frames.*' => ['required', 'file', 'mimetypes:image/jpeg', "max:{$kb}"],
            'timestamps' => ['required', 'array', 'size:'.count((array) $this->file('frames', []))],
            'timestamps.*' => ['required', 'integer', 'min:0', 'max:600000'],
            'mirrored' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Bytes de cada cuadro, en memoria. El archivo temporal de PHP se elimina
     * de inmediato tras leerlo (nunca se guarda en storage).
     *
     * @return list<string>
     */
    public function jpegFrames(): array
    {
        $out = [];
        foreach ((array) $this->file('frames', []) as $file) {
            if ($file instanceof UploadedFile) {
                $out[] = (string) $file->getContent();
                $path = $file->getRealPath();
                if (is_string($path) && is_file($path)) {
                    @unlink($path);
                }
            }
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    public function offsets(): array
    {
        return array_values(array_map('intval', (array) $this->input('timestamps', [])));
    }
}
