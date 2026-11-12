<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Sigilo do telefone dos clientes e das indicações (CRM da Ótica, 12/11/2026): só o **admin** da ótica vê o número. Para os
 * outros, a API manda só o número mascarado ("(88) 9••••-••61") e se há telefone; quem conversa pelo CRM usa o id e o
 * servidor é que conhece o número. O telefone dos parceiros não entra nesta regra.
 */
class PhonePrivacy
{
    public static function canSee(?Request $request = null): bool
    {
        $user = ($request ?? request())->user();

        return $user instanceof User && $user->hasRole(UserRole::Admin);
    }

    /** "88994669561" → "(88) 9••••-••61"; "8899466956" → "(88) ••••-••56". */
    public static function mask(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) < 6) {
            return str_repeat('•', strlen($digits));
        }
        $ddd = substr($digits, 0, 2);
        $end = substr($digits, -2);

        return strlen($digits) === 11 ? "({$ddd}) {$digits[2]}••••-••{$end}" : "({$ddd}) ••••-••{$end}";
    }

    /**
     * Os campos do telefone para a resposta: `phone_number` (só para o admin; para os outros, null), `phone_masked` e
     * `has_phone`.
     *
     * @return array{phone_number: ?string, phone_masked: ?string, has_phone: bool}
     */
    public static function fields(?string $phone, ?Request $request = null): array
    {
        return [
            'phone_number' => self::canSee($request) ? $phone : null,
            'phone_masked' => self::mask($phone),
            'has_phone' => $phone !== null && $phone !== '',
        ];
    }
}
