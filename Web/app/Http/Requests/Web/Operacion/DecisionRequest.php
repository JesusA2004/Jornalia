<?php

namespace App\Http\Requests\Web\Operacion;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Aprobación o rechazo de una solicitud. El comentario es obligatorio al rechazar.
 */
class DecisionRequest extends FormRequest
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
            'comment' => ['nullable', 'string', 'max:1000', 'required_if:decision,reject'],
            'void_target' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['comment.required_if' => 'Indica el motivo del rechazo.'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['decision' => 'decisión', 'comment' => 'comentario'];
    }

    public function approves(): bool
    {
        return $this->validated('decision') === 'approve';
    }
}
