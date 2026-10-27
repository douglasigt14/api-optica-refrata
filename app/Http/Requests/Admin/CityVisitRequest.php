<?php

namespace App\Http\Requests\Admin;

use App\Enums\CityVisitStatus;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cadastro e edição de atendimento. Na edição (PATCH) só valida os campos enviados.
 */
class CityVisitRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'city_id' => [$required, 'integer', CurrentCompany::servedCity()],
            'title' => [$required, 'string', 'max:255'],
            'visit_date' => [$required, 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$this->startDate()],
            'status' => ['sometimes', Rule::enum(CityVisitStatus::class)],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'city_id.exists' => 'Escolha uma cidade ativa.',
            'end_date.after_or_equal' => 'A data de fim não pode ser antes da data de início.',
        ];
    }

    /**
     * Data de início para comparar com o fim: a enviada ou, na edição, a que já está salva.
     */
    private function startDate(): string
    {
        return $this->input('visit_date') ?? $this->route('cityVisit')?->visit_date?->toDateString() ?? 'visit_date';
    }
}
