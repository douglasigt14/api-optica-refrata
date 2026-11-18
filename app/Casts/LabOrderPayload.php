<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * O `payload` do pedido com os tipos certos (18/11/2026). Salvar com anexo ou editar manda o formulário em multipart, e tudo
 * chegava como texto: `"0"` nas chaves (que a tela lia como ligado) e `"15"` nos tratamentos (que não batiam com o id do
 * catálogo: aparecia "#15" e a edição não marcava). Normaliza ao gravar e ao ler, o que conserta também os já gravados.
 *
 * @implements CastsAttributes<array<string, mixed>|null, array<string, mixed>|null>
 */
class LabOrderPayload implements CastsAttributes
{
    private const BOOLEANS = ['measure_frame_in_lab', 'standard_measures', 'plus_cylinder'];

    private const NUMBERS = ['refractive_index', 'frame_a', 'frame_bridge', 'frame_ed', 'frame_b'];

    private const EYE_NUMBERS = ['sphere', 'cylinder', 'addition', 'prism', 'dnp', 'near_dnp', 'height'];

    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value === null ? null : self::normalize(json_decode($value, true) ?? []);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : json_encode(self::normalize($value));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalize(array $payload): array
    {
        foreach (self::BOOLEANS as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = filter_var($payload[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }
        foreach (self::NUMBERS as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = self::number($payload[$field]);
            }
        }
        if (isset($payload['treatment_ids']) && is_array($payload['treatment_ids'])) {
            $payload['treatment_ids'] = array_values(array_map('intval', $payload['treatment_ids']));
        }
        if (isset($payload['eyes']) && is_array($payload['eyes'])) {
            $payload['eyes'] = array_map(function ($eye) {
                if (! is_array($eye)) {
                    return $eye;
                }
                foreach (self::EYE_NUMBERS as $field) {
                    if (array_key_exists($field, $eye)) {
                        $eye[$field] = self::number($eye[$field]);
                    }
                }
                if (array_key_exists('axis', $eye)) {
                    $eye['axis'] = $eye['axis'] === null || $eye['axis'] === '' ? null : (int) $eye['axis'];
                }

                return $eye;
            }, $payload['eyes']);
        }

        return $payload;
    }

    private static function number(mixed $value): int|float|null
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return $value + 0;
    }
}
