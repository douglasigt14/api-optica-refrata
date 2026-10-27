<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * As situações iniciais da indicação, uma por etapa, todas padrão. Cada ótica nova ganha as suas (o admin edita na tela
 * Situações). `[etapa, nome no painel, nome para o parceiro, cor]`.
 */
class DefaultLeadStatuses
{
    public const LIST = [
        ['new', 'Nova', 'Recebida', 'sky'],
        ['contacting', 'Em contato', 'Em contato', 'amber'],
        ['scheduled', 'Agendada', 'Exame marcado', 'emerald'],
        ['attended', 'Compareceu', 'Fez o exame', 'green'],
        ['no_show', 'Não compareceu', 'Não compareceu', 'rose'],
        ['discarded', 'Descartada', 'Não seguiu', 'zinc'],
    ];

    public static function createFor(Company $company): void
    {
        $now = now();
        foreach (self::LIST as $order => [$stage, $name, $partnerName, $color]) {
            DB::table('lead_statuses')->insert([
                'company_id' => $company->id,
                'name' => $name,
                'partner_name' => $partnerName,
                'stage' => $stage,
                'color' => $color,
                'sort_order' => ($order + 1) * 10,
                'is_default' => true,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
