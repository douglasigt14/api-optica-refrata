<?php

namespace App\Enums;

/**
 * Resultado de um contato do CRM com o cliente (12/11/2026). O resultado puxa a etapa (`stage()`), como nos CRMs:
 * "Retornar depois" também cria o lembrete com data.
 */
enum CrmResult: string
{
    case Booked = 'booked';
    case Talked = 'talked';
    case NoAnswer = 'no_answer';
    case CallBack = 'call_back';
    case NotInterested = 'not_interested';
    case WrongNumber = 'wrong_number';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Marcou',
            self::Talked => 'Conversou',
            self::NoAnswer => 'Não respondeu',
            self::CallBack => 'Retornar depois',
            self::NotInterested => 'Sem interesse',
            self::WrongNumber => 'Número errado',
        };
    }

    public function stage(): CrmStage
    {
        return match ($this) {
            self::Booked => CrmStage::Booked,
            self::Talked, self::CallBack => CrmStage::Talking,
            // Tentou e não falou (12/11/2026, decisão do Douglas): coluna própria no quadro.
            self::NoAnswer, self::WrongNumber => CrmStage::NoSuccess,
            self::NotInterested => CrmStage::NotInterested,
        };
    }
}
