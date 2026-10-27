<?php

namespace App\Http\Requests\Admin;

use App\Models\Prospector;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Cadastro (com senha inicial) e edição do parceiro pela equipe. Mesmas regras do cadastro pelo app.
 * O telefone é também o login: não pode repetir em outro parceiro nem em outro usuário.
 */
class ProspectorRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';
        /** @var Prospector|null $prospector */
        $prospector = $this->route('prospector');

        return [
            'name' => [$required, 'string', 'max:255'],
            'birth_date' => [$required, 'date', 'before:-18 years'],
            'phone_number' => [
                $required,
                'digits_between:10,11',
                Rule::unique('prospectors', 'phone_number')->ignore($prospector),
                Rule::unique('users', 'phone_number')->ignore($prospector?->user),
            ],
            'city_id' => [$required, 'integer', CurrentCompany::servedCity()],
            'pix_key' => [$required, 'string', 'max:255'],
            'instagram_handle' => ['nullable', 'string', 'max:30'],
            'trusted' => ['sometimes', 'boolean'],
            'password' => $creating ? ['required', 'string', Password::min(8)] : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'birth_date.before' => 'O parceiro precisa ter 18 anos ou mais.',
            'phone_number.unique' => 'Este telefone já está cadastrado.',
            'city_id.exists' => 'Escolha uma cidade ativa.',
            'password.prohibited' => 'Para trocar a senha, use "Definir nova senha".',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->phone_number)) {
            $this->merge(['phone_number' => preg_replace('/\D/', '', $this->phone_number)]);
        }

        if (is_string($this->instagram_handle)) {
            $handle = ltrim(trim($this->instagram_handle), '@');
            $this->merge(['instagram_handle' => $handle === '' ? null : $handle]);
        }
    }
}
