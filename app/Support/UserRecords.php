<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O que a pessoa já registrou aqui (para o Console saber se dá para apagá-la): toda tabela com chave para `users`, menos
 * as de acesso (apagadas junto, `cascade`). Lê as chaves do banco, então uma tabela nova entra sozinha.
 */
final class UserRecords
{
    /** Nome de cada tabela na mensagem; a que não estiver aqui sai com o nome da tabela. */
    private const LABELS = [
        'sales' => 'vendas',
        'sale_payments' => 'pagamentos de vendas',
        'lab_orders' => 'pedidos ao laboratório',
        'lead_contacts' => 'contatos com indicações',
        'lead_status_changes' => 'mudanças de etapa de indicações',
        'phone_reveals' => 'números de WhatsApp abertos',
        'queue_tickets' => 'senhas da fila',
        'crm_entries' => 'CRM',
        'crm_activities' => 'atividades do CRM',
        'whatsapp_messages' => 'mensagens de WhatsApp',
        'whatsapp_broadcasts' => 'envios em massa de WhatsApp',
    ];

    /**
     * @return array<string, int> tabela => quantos registros
     */
    public static function of(User $user): array
    {
        $found = [];
        foreach (Schema::getTableListing(Schema::getCurrentSchemaName(), false) as $table) {
            foreach (Schema::getForeignKeys($table) as $key) {
                if ($key['foreign_table'] !== 'users' || strtolower((string) $key['on_delete']) === 'cascade') {
                    continue;
                }
                $count = DB::table($table)->where($key['columns'][0], $user->id)->count();
                if ($count > 0) {
                    $found[$table] = ($found[$table] ?? 0) + $count;
                }
            }
        }

        return $found;
    }

    /**
     * @param  array<string, int>  $records
     */
    public static function describe(array $records): string
    {
        return collect($records)->map(fn (int $count, string $table) => (self::LABELS[$table] ?? str_replace('_', ' ', $table)))->values()->implode(', ');
    }
}
