<?php

namespace App\Http\Requests\Admin;

use App\Support\CurrentCompany;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Pedido ao laboratório (multipart, por causa do anexo da receita). Aqui só o que é da ótica (laboratório ligado, cliente,
 * anexo) e os tipos dos campos; as regras da receita, da armação e da lente são as do laboratório, que responde com os
 * mesmos campos (o erro aparece no formulário). Paciente e nº do pedido vêm do cliente e do pedido.
 */
class LabOrderRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lab_connection_id' => ['required', 'integer', CurrentCompany::exists('lab_connections')->whereNotNull('token')],
            'client_id' => ['required', 'integer', CurrentCompany::exists('clients')],
            // Atendimento interno (na loja: OS avulsa, sem atendimento) ou externo (04/11/2026: atendimento obrigatório; com
            // lote aberto dele no laboratório, a OS entra no lote). Só confere aqui: não fica guardado nem vai ao laboratório.
            'service_place' => ['required', 'in:internal,external'],
            'city_visit_id' => [
                $this->input('service_place') === 'external' ? 'required' : 'prohibited',
                'nullable', 'integer', CurrentCompany::exists('city_visits')->whereNot('status', 'cancelled')->whereNull('orders_closed_at'),
            ],
            // Óculos de uma venda (a promissória, 07/11/2026): o paciente é o `client_id` e o valor é o `price`. O
            // atendimento tem de ser o da venda (vazio = venda da loja); o controller confere.
            'sale_id' => ['nullable', 'integer', CurrentCompany::exists('sales')->whereNull('canceled_at')->whereNull('delivered_at')],
            'price' => ['nullable', 'required_with:sale_id', 'numeric', 'gt:0', 'max:999999.99'],
            'prescription' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:10240'],
            'lens_type' => ['required', 'string', 'max:30'],
            'bifocal_style' => ['nullable', 'string', 'max:30'],
            'material' => ['nullable', 'string', 'max:30'],
            'refractive_index' => ['nullable', 'numeric'],
            'treatment_ids' => ['sometimes', 'array'],
            'treatment_ids.*' => ['integer'],
            'frame_type' => ['required', 'string', 'max:30'],
            'measure_frame_in_lab' => ['sometimes', 'boolean'],
            'frame_a' => ['nullable', 'numeric'],
            'frame_bridge' => ['nullable', 'numeric'],
            'frame_ed' => ['nullable', 'numeric'],
            'frame_b' => ['nullable', 'numeric'],
            'frame_color' => ['nullable', 'string', 'max:40'],
            'frame_brand' => ['nullable', 'string', 'max:60'],
            'frame_model' => ['nullable', 'string', 'max:60'],
            'standard_measures' => ['sometimes', 'boolean'],
            'plus_cylinder' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'eyes' => ['required', 'array', 'min:1', 'max:2'],
            'eyes.*.eye' => ['required', 'in:right,left', 'distinct'],
            'eyes.*.sphere' => ['required', 'numeric'],
            'eyes.*.cylinder' => ['nullable', 'numeric'],
            'eyes.*.axis' => ['nullable', 'integer'],
            'eyes.*.addition' => ['nullable', 'numeric'],
            'eyes.*.prism' => ['nullable', 'numeric'],
            'eyes.*.prism_base' => ['nullable', 'string', 'max:10'],
            'eyes.*.dnp' => ['nullable', 'numeric'],
            'eyes.*.near_dnp' => ['nullable', 'numeric'],
            'eyes.*.height' => ['nullable', 'numeric'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sale_id.exists' => 'Venda não encontrada (ou já entregue ou cancelada).',
            'price.required_with' => 'Informe o valor do óculos.',
            'price.gt' => 'Informe o valor do óculos.',
            'lab_connection_id.required' => 'Escolha o laboratório.',
            'lab_connection_id.exists' => 'Escolha um laboratório ligado a esta ótica.',
            'client_id.required' => 'Escolha o cliente.',
            'client_id.exists' => 'Escolha um cliente desta ótica.',
            'service_place.required' => 'Escolha se o atendimento é interno ou externo.',
            'service_place.in' => 'Escolha se o atendimento é interno ou externo.',
            'city_visit_id.required' => 'Atendimento externo: escolha a cidade e o atendimento.',
            'city_visit_id.prohibited' => 'Atendimento interno não leva atendimento de cidade.',
            'city_visit_id.exists' => 'Escolha um atendimento desta ótica que esteja recebendo pedidos (não cancelado nem encerrado).',
            'lens_type.required' => 'Informe o tipo de lente.',
            'frame_type.required' => 'Informe o tipo da armação.',
            'eyes.required' => 'Informe a receita de pelo menos um olho.',
            'eyes.*.sphere.required' => 'Informe o esférico.',
            'prescription.mimes' => 'Anexe foto (JPG, PNG) ou PDF.',
            'prescription.max' => 'O anexo pode ter até 10 MB.',
        ];
    }
}
