<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cadastro e edição de usuário da equipe. Na edição a senha é opcional (vazia = mantém). O papel é o desta ótica.
 * No cadastro, o e-mail de alguém da equipe do mesmo grupo dá a ele acesso a esta ótica (sem senha nova); de outro grupo,
 * recusa.
 */
class TeamUserRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';
        $company = app(CurrentCompany::class)->require();
        $existing = $creating && is_string($this->input('email')) ? User::where('email', $this->input('email'))->whereNull('prospector_id')->first() : null;
        // Do mesmo grupo e ainda sem acesso a esta ótica: só ganha o acesso (com acesso já, é e-mail repetido).
        $sameGroup = $existing && $existing->group_id === $company->group_id && ! $existing->companies()->whereKey($company->id)->exists();

        return [
            'name' => [$creating && ! $sameGroup ? 'required' : 'sometimes', 'string', 'max:255'],
            'email' => [$required, 'email', 'max:255', ...($sameGroup ? [] : [Rule::unique('users', 'email')->ignore($this->route('user'))])],
            'role' => [$required, Rule::enum(UserRole::class)],
            'password' => [$creating && ! $sameGroup ? 'required' : 'nullable', 'string', 'min:8'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Já existe um usuário com este e-mail.',
            'password.min' => 'A senha precisa ter pelo menos 8 caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
