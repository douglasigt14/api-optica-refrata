<?php

namespace App\Http\Requests\Admin;

use App\Models\Client;
use App\Rules\Cpf;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cliente da ótica (também o paciente dos pedidos ao laboratório): nome, telefone e cidade (uma das atendidas)
 * obrigatórios; nascimento (ou a idade), comorbidades e como conheceu a ótica opcionais.
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
            // Opcional: o paciente pode ser outra pessoa (ex.: uma criança). Quem paga a venda precisa (ver SaleRequest).
            'phone_number' => ['nullable', 'digits_between:10,11'],
            'cpf' => ['nullable', 'digits:11', new Cpf, CurrentCompany::unique('clients', 'cpf')->ignore($this->route('client'))],
            'address' => ['nullable', 'string', 'max:200'],
            'city_id' => ['required', 'integer', CurrentCompany::servedCity()],
            'birth_date' => ['nullable', 'date', 'before:today'],
            // Idade digitada: só vale sem data de nascimento (com a data, a idade sai dela).
            'age' => ['nullable', 'integer', 'min:0', 'max:120'],
            // { "diabetes": ["self", "family"], "glaucoma": ["family"] }: só as marcadas.
            'comorbidities' => ['nullable', 'array:'.implode(',', Client::COMORBIDITIES)],
            'comorbidities.*' => ['array'],
            'comorbidities.*.*' => ['string', Rule::in(Client::COMORBIDITY_WHO)],
            'referral_source' => ['nullable', 'string', Rule::in(Client::REFERRAL_SOURCES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome.',
            'cpf.digits' => 'CPF com 11 dígitos.',
            'cpf.unique' => 'Já há um cliente com este CPF.',
            'phone_number.digits_between' => 'Telefone com DDD: 10 ou 11 dígitos.',
            'city_id.required' => 'Escolha a cidade.',
            'city_id.exists' => 'Escolha uma cidade atendida pela ótica.',
            'birth_date.before' => 'A data de nascimento precisa ser antes de hoje.',
            'age.min' => 'Idade inválida.',
            'age.max' => 'Idade inválida.',
            'age.integer' => 'Idade inválida.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->phone_number)) {
            $this->merge(['phone_number' => preg_replace('/\D/', '', $this->phone_number) ?: null]);
        }
        if (is_string($this->cpf)) {
            $this->merge(['cpf' => preg_replace('/\D/', '', $this->cpf) ?: null]);
        }
        // Guarda só as comorbidades marcadas, sem repetir (vazio = nenhuma).
        if (is_array($this->comorbidities)) {
            $marked = array_filter(array_map(fn ($who) => is_array($who) ? array_values(array_unique($who)) : $who, $this->comorbidities), fn ($who) => $who !== []);
            $this->merge(['comorbidities' => $marked ?: null]);
        }
    }
}
