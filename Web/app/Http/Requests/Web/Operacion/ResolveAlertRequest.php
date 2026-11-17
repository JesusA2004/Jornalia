<?php

namespace App\Http\Requests\Web\Operacion;

use Illuminate\Foundation\Http\FormRequest;

class ResolveAlertRequest extends FormRequest
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
            'status' => ['required', 'in:acknowledged,resolved'],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['status' => 'estado', 'note' => 'nota'];
    }
}
