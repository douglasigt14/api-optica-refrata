<?php

namespace App\Enums;

/**
 * Etapa do **cliente** num atendimento, no CRM (12/11/2026). Não são as etapas da indicação (`LeadStage`): o CRM chama de
 * volta quem já comprou (ver "Indicação × Cliente" no HANDOFF).
 */
enum CrmStage: string
{
    case ToContact = 'to_contact';
    case Talking = 'talking';
    /** "Não atendeu": tentou e não conseguiu falar (não respondeu ou número errado; 12/11/2026). */
    case NoSuccess = 'no_success';
    case Booked = 'booked';
    /** Precisa de novo contato (só arrastando no quadro; 12/11/2026). */
    case Recontact = 'recontact';
    case Attended = 'attended';
    case Bought = 'bought';
    case NoShow = 'no_show';
    case NotInterested = 'not_interested';

    public function label(): string
    {
        return match ($this) {
            self::ToContact => 'A contatar',
            self::Talking => 'Em conversa',
            self::NoSuccess => 'Não atendeu',
            self::Booked => 'Marcou',
            self::Recontact => 'Re-contato',
            self::Attended => 'Foi ao atendimento',
            self::Bought => 'Comprou de novo',
            self::NoShow => 'Não foi',
            self::NotInterested => 'Sem interesse',
        };
    }

    /** Já foi ao atendimento (o contato depois disso não muda a etapa). */
    public function wentToVisit(): bool
    {
        return $this === self::Attended || $this === self::Bought;
    }

    /** Marcou em algum momento (conta em "Marcaram"). */
    public function hasBooked(): bool
    {
        return in_array($this, [self::Booked, self::Attended, self::Bought, self::NoShow], true);
    }
}
