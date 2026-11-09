<?php

namespace App\Http\Requests\Admin;

use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Venda (rascunho ou ajuste): o cliente que paga, o atendimento (vazio = venda da loja) e a entrega prevista.
 */
class SaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', CurrentCompany::exists('clients')],
            // Só na criação; depois não troca (os óculos já estão no atendimento).
            'city_visit_id' => ['nullable', 'integer', CurrentCompany::exists('city_visits')->whereNot('status', 'cancelled')->whereNull('orders_closed_at')],
            // Ficha do atendimento: a data é a do atendimento (09/11/2026); só a da loja informa a sua.
            'expected_delivery_date' => [Rule::requiredIf(fn () => ! $this->input('city_visit_id') && ! $this->route('sale')?->city_visit_id), 'nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.required' => 'Escolha o cliente que paga.',
            'city_visit_id.exists' => 'Escolha um atendimento que esteja recebendo pedidos.',
            'expected_delivery_date.required' => 'Informe a entrega prevista.',
        ];
    }
}
