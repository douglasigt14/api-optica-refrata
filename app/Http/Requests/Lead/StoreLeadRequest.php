<?php

namespace App\Http\Requests\Lead;

use App\Http\Requests\Auth\NormalizesPhoneNumber;
use App\Models\CityVisit;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreLeadRequest extends FormRequest
{
    use NormalizesPhoneNumber;

    /**
     * Só quem tem cadastro de prospector pode indicar.
     */
    public function authorize(): bool
    {
        $prospector = $this->user()?->prospector;

        return $prospector !== null && ! $prospector->isBlocked();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'digits_between:10,11'],
            'city_id' => ['required', 'integer', CurrentCompany::servedCity()],
            'city_visit_id' => [
                'required',
                'integer',
                // o atendimento tem que ser da cidade escolhida e ainda estar aberto
                function (string $attribute, mixed $value, Closure $fail) {
                    $open = CityVisit::open()->whereKey($value)->where('city_id', $this->input('city_id'))->exists();
                    if (! $open) {
                        $fail('Escolha um atendimento marcado para esta cidade.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone_number.digits_between' => 'Informe o telefone com DDD.',
            'city_visit_id.required' => 'Escolha o atendimento.',
        ];
    }
}
