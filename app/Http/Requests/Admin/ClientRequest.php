<?php

namespace App\Http\Requests\Admin;

use App\Support\CurrentCompany;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cliente da ótica (também o paciente dos pedidos ao laboratório): nome, telefone e cidade (uma das atendidas)
 * obrigatórios; nascimento opcional.
 */
class ClientRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone_number' => ['required', 'digits_between:10,11'],
            'city_id' => ['required', 'integer', CurrentCompany::servedCity()],
            'birth_date' => ['nullable', 'date', 'before:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome.',
            'phone_number.required' => 'Informe o telefone.',
            'phone_number.digits_between' => 'Telefone com DDD: 10 ou 11 dígitos.',
            'city_id.required' => 'Escolha a cidade.',
            'city_id.exists' => 'Escolha uma cidade atendida pela ótica.',
            'birth_date.before' => 'A data de nascimento precisa ser antes de hoje.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->phone_number)) {
            $this->merge(['phone_number' => preg_replace('/\D/', '', $this->phone_number)]);
        }
    }
}
