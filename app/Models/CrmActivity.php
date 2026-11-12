<?php

namespace App\Models;

use App\Enums\CrmChannel;
use App\Enums\CrmResult;
use App\Enums\CrmStage;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Histórico do CRM (12/11/2026): `kind` = `contact` (contato registrado: canal, resultado, observação, retorno), `stage`
 * (etapa mudada à mão) ou `auto` (o sistema mudou: ficha ou pedido no atendimento, "Não foi" em lote).
 */
#[Fillable(['crm_entry_id', 'city_visit_id', 'client_id', 'user_id', 'kind', 'channel', 'result', 'from_stage', 'to_stage', 'notes', 'follow_up_at'])]
class CrmActivity extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'channel' => CrmChannel::class,
            'result' => CrmResult::class,
            'from_stage' => CrmStage::class,
            'to_stage' => CrmStage::class,
            'follow_up_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CityVisit, $this>
     */
    public function cityVisit(): BelongsTo
    {
        return $this->belongsTo(CityVisit::class);
    }
}
