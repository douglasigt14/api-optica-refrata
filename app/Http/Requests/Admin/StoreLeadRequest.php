<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Lead\StoreLeadRequest as PartnerStoreLeadRequest;
use App\Models\Prospector;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Nova indicação lançada pela equipe no painel (11/11/2026), em nome de um parceiro: as mesmas regras da indicação
 * feita no app dos parceiros, mais o parceiro (da empresa e não bloqueado).
 */
class StoreLeadRequest extends PartnerStoreLeadRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prospector_id' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) {
                    // Prospector tem o escopo da empresa: parceiro de outra empresa não aparece.
                    $prospector = Prospector::find($value);
                    if ($prospector === null) {
                        $fail('Escolha o parceiro.');
                    } elseif ($prospector->isBlocked()) {
                        $fail('Este parceiro está bloqueado e não pode indicar.');
                    }
                },
            ],
            ...parent::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...parent::messages(), 'prospector_id.required' => 'Escolha o parceiro.'];
    }
}
