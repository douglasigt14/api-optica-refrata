<?php

namespace App\Http\Requests\Auth;

use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Cadastro do parceiro numa ótica: `invite_code` é o código da ótica (vem no link de convite ou é digitado). A cidade tem
 * que ser uma das que a ótica atende.
 */
class RegisterRequest extends FormRequest
{
    use NormalizesPhoneNumber;

    private ?Company $company = null;

    /**
     * A ótica do convite (ativa e de grupo não bloqueado). Sem código e com uma ótica só na plataforma, é ela (o app
     * antigo, de antes do convite, não manda o código).
     */
    public function company(): ?Company
    {
        return $this->company ??= Company::findForInvite($this->input('invite_code'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $company = $this->company();
        if ($company) {
            app(CurrentCompany::class)->set($company);
        }

        return [
            'invite_code' => $company ? ['nullable', 'string'] : ['required', 'string', fn ($attribute, $value, $fail) => $fail('Código da ótica não encontrado.')],
            'name' => ['required', 'string', 'max:255'],
            'birth_date' => ['required', 'date', 'before:-18 years'],
            'phone_number' => ['required', 'digits_between:10,11', 'unique:users,phone_number', 'unique:prospectors,phone_number'],
            'city_id' => ['required', 'integer', ...($company ? [CurrentCompany::servedCity()] : [])],
            'pix_key' => ['required', 'string', 'max:255'],
            'instagram_handle' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'birth_date.before' => 'É preciso ter 18 anos ou mais.',
            'phone_number.unique' => 'Este telefone já está cadastrado.',
            'invite_code.required' => 'Informe o código da ótica.',
        ];
    }
}
