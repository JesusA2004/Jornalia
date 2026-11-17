<?php

namespace App\Http\Requests\Web\Operacion;

use Illuminate\Foundation\Http\FormRequest;

class AuthorizeOvertimeRequest extends FormRequest
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
            'decision' => ['required', 'in:approve,reject'],
            'authorized_minutes' => ['required_if:decision,approve', 'nullable', 'integer', 'min:0', 'max:1440'],
            'justification' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['authorized_minutes' => 'minutos autorizados', 'justification' => 'justificación', 'decision' => 'decisión'];
    }
}
