<?php

namespace App\Http\Requests\Admin;

use App\Enums\LeadStage;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Troca manual de situação. Regras pela etapa: para ir à etapa Agendada é preciso marcar o dia
 * (só dá para trocar entre situações dela se já estiver agendada); Compareceu/Não compareceu exigem dia marcado.
 */
class UpdateLeadStatusRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lead_status_id' => [
                'required',
                'integer',
                CurrentCompany::exists('lead_statuses')->where('active', true),
                function (string $attribute, mixed $value, Closure $fail) {
                    /** @var Lead $lead */
                    $lead = $this->route('lead');
                    $status = LeadStatus::find($value);
                    if ($status === null) {
                        return;
                    }
                    if ($status->stage === LeadStage::Scheduled && $lead->stage !== LeadStage::Scheduled) {
                        $fail('Para agendar, escolha o dia do exame.');
                    } elseif ($status->stage->needsAppointment() && $lead->appointment_date === null) {
                        $fail('Marque o dia do exame antes.');
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
            'lead_status_id.exists' => 'Escolha uma situação ativa.',
        ];
    }
}
