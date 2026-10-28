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
            'lab_connection_id.required' => 'Escolha o laboratório.',
            'lab_connection_id.exists' => 'Escolha um laboratório ligado a esta ótica.',
            'client_id.required' => 'Escolha o cliente.',
            'client_id.exists' => 'Escolha um cliente desta ótica.',
            'lens_type.required' => 'Informe o tipo de lente.',
            'frame_type.required' => 'Informe o tipo da armação.',
            'eyes.required' => 'Informe a receita de pelo menos um olho.',
            'eyes.*.sphere.required' => 'Informe o esférico.',
            'prescription.mimes' => 'Anexe foto (JPG, PNG) ou PDF.',
            'prescription.max' => 'O anexo pode ter até 10 MB.',
        ];
    }
}
