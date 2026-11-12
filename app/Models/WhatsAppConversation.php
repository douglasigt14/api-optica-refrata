<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversa do WhatsApp com uma pessoa (CRM, 12/11/2026). O identificador dela no WhatsApp (`remote_jid`, com o número)
 * fica criptografado e nunca sai na API; `remote_hash` serve para achar a conversa. Ligada ao cliente ou à indicação
 * quando o número bate.
 */
#[Fillable(['whatsapp_number_id', 'client_id', 'lead_id', 'remote_jid', 'remote_hash', 'push_name', 'unread_count', 'last_message_at', 'last_message_preview'])]
#[Hidden(['remote_jid', 'remote_hash'])]
class WhatsAppConversation extends Model
{
    use BelongsToCompany;

    protected $table = 'whatsapp_conversations';

    protected function casts(): array
    {
        return ['remote_jid' => 'encrypted', 'last_message_at' => 'datetime'];
    }

    public static function hashFor(int $numberId, string $jid): string
    {
        return hash('sha256', $numberId.'|'.$jid);
    }

    /** Só os dígitos do número da pessoa (para enviar). */
    public function remoteDigits(): string
    {
        return preg_replace('/\D/', '', explode('@', $this->remote_jid)[0]);
    }

    /** @return BelongsTo<WhatsAppNumber, $this> */
    public function number(): BelongsTo
    {
        return $this->belongsTo(WhatsAppNumber::class, 'whatsapp_number_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return HasMany<WhatsAppMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'whatsapp_conversation_id');
    }
}
