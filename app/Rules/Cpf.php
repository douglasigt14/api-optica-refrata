<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CPF válido (11 dígitos e os dois dígitos verificadores). Chega já só com os números.
 */
class Cpf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::valid((string) $value)) {
            $fail('CPF inválido.');
        }
    }

    public static function valid(string $cpf): bool
    {
        if (! preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        for ($length = 9; $length < 11; $length++) {
            $sum = 0;
            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $cpf[$i] * ($length + 1 - $i);
            }
            if ((int) $cpf[$length] !== ((10 * $sum) % 11) % 10) {
                return false;
            }
        }

        return true;
    }
}
