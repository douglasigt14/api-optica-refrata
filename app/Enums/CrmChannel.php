<?php

namespace App\Enums;

/** Por onde foi o contato do CRM (12/11/2026). Sem ligação comum: só pelo WhatsApp (decisão do Douglas). */
enum CrmChannel: string
{
    case WhatsApp = 'whatsapp';
    case WhatsAppCall = 'whatsapp_call';
    case InPerson = 'in_person';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'Mensagem no WhatsApp',
            self::WhatsAppCall => 'Ligação pelo WhatsApp',
            self::InPerson => 'Pessoalmente',
            self::Other => 'Outro',
        };
    }
}
