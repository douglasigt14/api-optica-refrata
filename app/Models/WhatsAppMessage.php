<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mensagem do WhatsApp (CRM, 12/11/2026): `direction` `in` (da pessoa) ou `out` (da ótica, com quem mandou); `type`
 * `text`, `audio`, `image` ou `other`; áudio e imagem guardados no disco privado.
 */
#[Fillable(['whatsapp_conversation_id', 'direction', 'user_id', 'type', 'body', 'media_path', 'media_mime', 'external_id', 'status', 'error'])]
class WhatsAppMessage extends Model
{
    use BelongsToCompany;

    protected $table = 'whatsapp_messages';

    /** @return BelongsTo<WhatsAppConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'whatsapp_conversation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
