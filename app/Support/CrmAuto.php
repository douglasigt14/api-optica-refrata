<?php

namespace App\Support;

use App\Enums\CrmStage;
use App\Models\CrmEntry;
use App\Models\LabOrder;
use App\Models\Sale;

/**
 * Etapas automáticas do CRM (12/11/2026, decisão do Douglas: "automático + manual"): ficha ou pedido do cliente no
 * atendimento → "Foi ao atendimento"; ficha lançada → "Comprou de novo". Só mexe em quem o CRM já trabalhou naquele
 * atendimento (tem linha em `crm_entries`): quem veio sem contato do CRM não entra na conta dele.
 */
class CrmAuto
{
    public static function fromSale(Sale $sale): void
    {
        if (! $sale->city_visit_id || ! $sale->client_id || $sale->canceled_at) {
            return;
        }
        $launched = $sale->launched_at !== null;
        self::promote($sale->city_visit_id, $sale->client_id, $launched ? CrmStage::Bought : CrmStage::Attended, $launched ? 'Ficha lançada no atendimento' : 'Ficha aberta no atendimento');
    }

    public static function fromOrder(LabOrder $order): void
    {
        if ($order->city_visit_id && $order->client_id) {
            self::promote($order->city_visit_id, $order->client_id, CrmStage::Attended, 'Pedido no atendimento');
        }
    }

    private static function promote(int $visitId, int $clientId, CrmStage $stage, string $note): void
    {
        $entry = CrmEntry::withoutGlobalScopes()->where('city_visit_id', $visitId)->where('client_id', $clientId)->first();
        if (! $entry || $entry->stage === CrmStage::Bought || ($stage === CrmStage::Attended && $entry->stage->wentToVisit())) {
            return;
        }
        $entry->moveTo($stage, null, 'auto', $note);
    }
}
