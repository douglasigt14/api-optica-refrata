<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\WhatsApp\EvolutionProvider;
use App\Services\WhatsApp\MetaCloudProvider;
use App\Services\WhatsApp\Provider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Número de WhatsApp da ótica (CRM, 12/11/2026). `provider`: `evolution` (não oficial) ou `meta_cloud` (oficial, depois).
 * O endereço e a chave do servidor ficam aqui (a chave criptografada), configurados pela tela: nunca em `.env`.
 */
#[Fillable(['name', 'is_default', 'provider', 'instance', 'base_url', 'api_key', 'webhook_secret', 'status', 'own_phone', 'connected_at'])]
#[Hidden(['api_key', 'webhook_secret'])]
class WhatsAppNumber extends Model
{
    use BelongsToCompany;

    protected $table = 'whatsapp_numbers';

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'api_key' => 'encrypted', 'connected_at' => 'datetime'];
    }

    public function provider(): Provider
    {
        return $this->provider === 'meta_cloud' ? new MetaCloudProvider($this) : new EvolutionProvider($this);
    }

    /** Disparo em massa só com a API oficial (decisão do Douglas, 12/11/2026). */
    public function allowsBroadcast(): bool
    {
        return $this->provider === 'meta_cloud';
    }

    /** O número padrão da ótica escolhida (ou o primeiro). */
    public static function default(): ?self
    {
        return static::query()->orderByDesc('is_default')->orderBy('id')->first();
    }
}
