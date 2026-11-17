<?php

namespace App\Http\Requests\Web\Operacion;

use App\Enums\PunchType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WebPunchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(PunchType::class)],
            'client_uuid' => ['required', 'uuid'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy_meters' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
