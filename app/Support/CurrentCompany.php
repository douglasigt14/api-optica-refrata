<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * A ótica em que a requisição trabalha (a do parceiro, ou a escolhida pela equipe, conferida em `UseCompany`). Os modelos com
 * `BelongsToCompany` filtram e preenchem por ela. Sem empresa (comandos, rotas da plataforma, links assinados), nada é
 * filtrado.
 */
class CurrentCompany
{
    private ?Company $company = null;

    public function set(?Company $company): void
    {
        $this->company = $company;
    }

    public function get(): ?Company
    {
        return $this->company;
    }

    public function id(): ?int
    {
        return $this->company?->id;
    }

    /**
     * A empresa, ou erro (o que só existe dentro de uma empresa: numeração, configurações, arquivos).
     */
    public function require(): Company
    {
        return $this->company ?? throw new \LogicException('Nenhuma empresa escolhida.');
    }

    /**
     * Roda `$callback` dentro da empresa e volta à anterior.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function within(Company $company, callable $callback): mixed
    {
        $previous = $this->company;
        $this->company = $company;
        try {
            return $callback();
        } finally {
            $this->company = $previous;
        }
    }

    /** `Rule::exists` dentro da empresa. */
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return (new Exists($table, $column))->where('company_id', app(self::class)->require()->id);
    }

    /** Cidade que a ótica escolhida atende (`company_city`). */
    public static function servedCity(): Exists
    {
        return (new Exists('company_city', 'city_id'))->where('company_id', app(self::class)->require()->id);
    }

    /** `Rule::unique` dentro da empresa. */
    public static function unique(string $table, string $column): Unique
    {
        return (new Unique($table, $column))->where('company_id', app(self::class)->require()->id);
    }
}
