<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Disparo em massa de um modelo (CRM, fase 5, 12/11/2026): **pré-pronto**, só liga com número da API oficial
 * (`WhatsAppNumber::allowsBroadcast`). Lote, intervalo e agendamento ficam guardados para quando o envio for ligado.
 */
#[Fillable(['whatsapp_number_id', 'message_template_id', 'city_visit_id', 'user_id', 'filters', 'batch_size', 'interval_seconds', 'scheduled_at', 'status', 'total', 'sent', 'failed'])]
class WhatsAppBroadcast extends Model
{
    use BelongsToCompany;

    protected $table = 'whatsapp_broadcasts';

    protected function casts(): array
    {
        return ['filters' => 'array', 'scheduled_at' => 'datetime'];
    }
}
