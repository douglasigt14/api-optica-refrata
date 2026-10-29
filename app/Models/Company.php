<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Ótica (pessoa jurídica) de um grupo, espelho do Console (`console_id`): parceiros, indicações, atendimentos e
 * situações são de uma ótica. Criada e atualizada pelo Console (`/api/platform/companies/{uid}`); inativa, ninguém dela
 * entra (equipe nem parceiros). `invite_code`: o código que o parceiro usa no cadastro (link de convite).
 * `financial_url`: painel do financeiro, quando a ótica o usa (item Financeiro no menu do admin).
 */
#[Fillable(['group_id', 'console_id', 'name', 'trade_name', 'document', 'invite_code', 'active', 'financial_url'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Equipe com acesso, com o papel nesta ótica (`pivot.role`).
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(CompanyUser::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Cidades que a ótica atende (as que aparecem no cadastro e nas indicações dos parceiros dela).
     *
     * @return BelongsToMany<City, $this>
     */
    public function cities(): BelongsToMany
    {
        return $this->belongsToMany(City::class, 'company_city')->using(CompanyCity::class)->withTimestamps();
    }

    /** Pode ser usada agora: ativa e de grupo não bloqueado. */
    public function available(): bool
    {
        return $this->active && ! $this->group?->blocked();
    }

    /** Nome que aparece no painel e no app (fantasia, senão a razão social). */
    public function displayName(): string
    {
        return $this->trade_name ?: $this->name;
    }

    /**
     * A ótica do código de convite (sem diferença de maiúsculas), se puder receber parceiros. Sem código e com uma ótica só
     * disponível na plataforma, é ela (o app antigo, de antes do convite, não manda o código).
     */
    public static function findForInvite(?string $code): ?self
    {
        $code = Str::upper(trim((string) $code));
        $companies = static::with('group')->where('active', true)->when($code !== '', fn ($q) => $q->where('invite_code', $code))->limit(2)->get()
            ->filter(fn (self $company) => $company->available());

        return $code !== '' || $companies->count() === 1 ? $companies->first() : null;
    }

    /**
     * Código de convite novo a partir do nome ("Otica Exclusive" → EXCLUSIVE; "Ótica Bem Estar" → BEMESTAR), sem repetir.
     */
    public static function inviteCodeFor(string $name): string
    {
        $words = collect(preg_split('/\s+/', Str::upper(Str::ascii($name))))
            ->map(fn (string $word) => preg_replace('/[^A-Z0-9]/', '', $word))
            ->reject(fn (string $word) => $word === '' || in_array($word, ['OTICA', 'OTICAS', 'OPTICA', 'LTDA', 'ME', 'EIRELI', 'SA', 'DE', 'DA', 'DO', 'DOS', 'DAS', 'E'], true));
        $base = Str::limit($words->implode('') ?: 'OTICA', 20, '');
        $code = $base;
        for ($i = 2; static::where('invite_code', $code)->exists(); $i++) {
            $code = $base.$i;
        }

        return $code;
    }
}
