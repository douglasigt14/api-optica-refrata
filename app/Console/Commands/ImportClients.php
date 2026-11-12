<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Company;
use App\Rules\Cpf;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Importa clientes de uma planilha (CSV) para uma ótica (12/11/2026; feito para os clientes antigos da Perfeita Visão).
 *
 * Colunas do CSV (cabeçalho na 1ª linha; só `cidade` e `nome` são obrigatórias): `origem` (de onde veio a linha, para o
 * relatório), `cidade`, `nome`, `telefone`, `endereco`, `data_compra` (AAAA-MM-DD), `valor`, `indicacao`, `os`,
 * `observacoes`, `cpf`, `nascimento` (AAAA-MM-DD).
 *
 * Regras (decididas com o Douglas):
 * - Nome com parênteses: nome e sobrenome dentro = o **responsável**, que vira o cliente (o de fora vai nas observações
 *   como paciente); anotação ("retinografia", "ACS", "Premiação"…) vai para as observações; uma palavra só = apelido
 *   (o de fora é o cliente; "Apelido: …" nas observações).
 * - Mesmo nome na mesma cidade = um cliente só: fica a compra mais recente (data e valor; no mesmo dia, soma, mas a
 *   mesma O.S. conta uma vez) e as
 *   observações juntam as linhas. Quem já existe na ótica (mesmo nome e cidade) é pulado: rodar de novo não duplica.
 * - Telefone com 8 dígitos ganha o DDD 88 e o 9 (9 dígitos começando com 9: só o 88). Outros tamanhos: sem telefone, e o
 *   número da planilha vai para as observações.
 * - Entra sem data de nascimento (a tela pede ao editar).
 */
#[Signature('clients:import {file : CSV com os clientes} {--company= : A ótica (console_id ou id daqui)} {--dry-run : Só simula, não grava nada} {--report= : Grava um CSV com cada cliente como vai ficar}')]
#[Description('Importa clientes de uma planilha (CSV) para uma ótica')]
class ImportClients extends Command
{
    /** Texto entre parênteses que é anotação, não pessoa. */
    private const NOTE_PATTERN = '/retino|^ret$|\bacs\b|avis|^avs\b|premia|gratuit|liga[cç][aã]o|desconto|lente|\bpag[ao]\b|\bpix\b|cart[aã]o|entrega|somente|conferir/iu';

    private const DEFAULT_DDD = '88';

    /** @var array<string, int> contagens para o resumo */
    private array $stats = [];

    /** @var list<string> */
    private array $nicknames = [];

    public function handle(): int
    {
        $company = $this->company();
        if (! $company) {
            return self::FAILURE;
        }

        $file = (string) $this->argument('file');
        if (! is_readable($file)) {
            $this->error("Arquivo não encontrado: {$file}");

            return self::FAILURE;
        }

        $cities = DB::table('company_city')->join('cities', 'cities.id', '=', 'company_city.city_id')
            ->where('company_city.company_id', $company->id)->pluck('cities.id', 'cities.name')
            ->mapWithKeys(fn ($id, $name) => [$this->key($name) => $id])->all();
        $cityNames = DB::table('cities')->whereIn('id', $cities)->pluck('name', 'id')->all();

        [$rows, $errors] = $this->readRows($file, $cities);
        $groups = $this->group($rows);

        $existing = Client::withoutGlobalScopes()->where('company_id', $company->id)->get(['id', 'city_id', 'name', 'cpf']);
        $existingKeys = $existing->mapWithKeys(fn (Client $c) => [$c->city_id.'|'.$this->key($c->name) => true])->all();
        $usedCpfs = $existing->pluck('cpf')->filter()->flip()->all();

        $records = [];
        foreach ($groups as $groupKey => $group) {
            if (isset($existingKeys[$groupKey])) {
                $this->count('Já existiam na ótica (pulados)');

                continue;
            }
            $record = $this->merge($group);
            if ($record['cpf'] !== null && isset($usedCpfs[$record['cpf']])) {
                $record['notes'] .= "\nCPF na planilha: {$record['cpf']} (já é de outro cliente)";
                $record['cpf'] = null;
            }
            if ($record['cpf'] !== null) {
                $usedCpfs[$record['cpf']] = true;
            }
            $records[] = $record;
        }

        $this->summary($company, count($rows) + count($errors), $records, $errors, $cityNames);

        if ($path = $this->option('report')) {
            $this->writeReport((string) $path, $records, $errors, $cityNames);
            $this->info("Relatório gravado em {$path}");
        }

        if ($this->option('dry-run')) {
            $this->warn('Simulação: nada foi gravado.');

            return self::SUCCESS;
        }

        $now = now();
        DB::transaction(function () use ($records, $company, $now) {
            foreach (array_chunk($records, 200) as $chunk) {
                Client::insert(array_map(fn (array $r) => [
                    'company_id' => $company->id,
                    'city_id' => $r['city_id'],
                    'name' => $r['name'],
                    'cpf' => $r['cpf'],
                    'birth_date' => $r['birth_date'],
                    'phone_number' => $r['phone_number'],
                    'address' => $r['address'],
                    'last_purchase_date' => $r['last_purchase_date'],
                    'last_purchase_amount' => $r['last_purchase_amount'],
                    'referred_by' => $r['referred_by'],
                    'notes' => $r['notes'],
                    'wears_glasses' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        });
        $this->info(count($records).' clientes gravados em '.$company->name.'.');

        return self::SUCCESS;
    }

    private function company(): ?Company
    {
        $option = (string) $this->option('company');
        if ($option === '') {
            $this->error('Informe a ótica: --company=<console_id ou id>.');

            return null;
        }
        $company = Company::query()->where('console_id', $option)->orWhere('id', ctype_digit($option) ? (int) $option : 0)->first();
        if (! $company) {
            $this->error('Ótica não encontrada.');
        }

        return $company;
    }

    /**
     * Lê o CSV e interpreta cada linha (nome, telefone, datas, valor).
     *
     * @param  array<string, int>  $cities  nome normalizado → id (só as atendidas)
     * @return array{0: list<array<string, mixed>>, 1: list<array{origin: string, message: string}>}
     */
    private function readRows(string $file, array $cities): array
    {
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle, escape: '');
        $header = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), $header ?: []);
        $rows = [];
        $errors = [];
        $line = 1;
        while (($values = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            if ($values === [null] || trim(implode('', $values)) === '') {
                continue;
            }
            $raw = array_map(fn ($v) => trim((string) $v), array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), '')));
            $origin = $raw['origem'] ?? '' ?: "linha {$line}";
            $cityId = $cities[$this->key($raw['cidade'] ?? '')] ?? null;
            if (! $cityId) {
                $errors[] = ['origin' => $origin, 'message' => 'Cidade não atendida pela ótica: "'.($raw['cidade'] ?? '').'" ('.($raw['nome'] ?? '').')'];

                continue;
            }
            $name = $this->parseName($raw['nome'] ?? '');
            if ($name['client'] === '') {
                $errors[] = ['origin' => $origin, 'message' => 'Sem nome'];

                continue;
            }
            [$phone, $phoneNote] = $this->parsePhone($raw['telefone'] ?? '');
            $notes = $name['notes'];
            if ($phoneNote) {
                $notes[] = $phoneNote;
            }
            if (($raw['os'] ?? '') !== '') {
                $notes[] = 'O.S. '.$raw['os'];
            }
            if (($raw['observacoes'] ?? '') !== '') {
                $notes[] = $raw['observacoes'];
            }
            $cpf = preg_replace('/\D/', '', $raw['cpf'] ?? '');
            $cpfValid = $cpf !== '' && Validator::make(['cpf' => $cpf], ['cpf' => ['digits:11', new Cpf]])->passes();
            if ($cpf !== '' && ! $cpfValid) {
                $notes[] = 'CPF na planilha: '.$raw['cpf'];
            }

            $rows[] = [
                'origin' => $origin,
                'city_id' => $cityId,
                'name' => $name['client'],
                'phone_number' => $phone,
                'address' => Str::limit(($raw['endereco'] ?? '') ?: '', 200, '') ?: null,
                'purchase_date' => $this->date($raw['data_compra'] ?? ''),
                'amount' => is_numeric($raw['valor'] ?? null) ? round((float) $raw['valor'], 2) : null,
                'referred_by' => ($raw['indicacao'] ?? '') ?: null,
                'os' => preg_replace('/\D+/', ' ', $raw['os'] ?? '') ?: null,
                'cpf' => $cpfValid ? $cpf : null,
                'birth_date' => $this->date($raw['nascimento'] ?? ''),
                'notes' => $notes,
            ];
        }
        fclose($handle);

        return [$rows, $errors];
    }

    /**
     * "Ana Mikaelle (Nayara Lima)" → cliente Nayara Lima, paciente Ana Mikaelle. Anotações e apelidos vão para as observações.
     *
     * @return array{client: string, notes: list<string>}
     */
    private function parseName(string $raw): array
    {
        $raw = $this->clean($raw);
        preg_match_all('/\(([^()]*)\)/u', $raw, $matches);
        $outer = $this->clean(str_replace(['(', ')'], ' ', preg_replace('/\([^()]*\)/u', ' ', $raw)));
        $responsible = null;
        $notes = [];
        foreach ($matches[1] as $inside) {
            $inside = $this->clean($inside);
            if ($inside === '') {
                continue;
            }
            if (preg_match(self::NOTE_PATTERN, $inside)) {
                $notes[] = $inside;
                $this->count('Nomes com anotação entre parênteses');
            } elseif (count(explode(' ', $inside)) === 1) {
                $notes[] = "Apelido: {$inside}";
                $this->nicknames[] = "{$outer} ({$inside})";
                $this->count('Nomes com apelido entre parênteses');
            } elseif ($responsible === null) {
                $responsible = $inside;
            } else {
                $notes[] = $inside;
            }
        }
        if ($responsible !== null && $outer !== '') {
            array_unshift($notes, "Paciente: {$outer}");
            $this->count('Responsável virou o cliente (o de fora é o paciente)');

            return ['client' => Str::limit($responsible, 120, ''), 'notes' => $notes];
        }

        return ['client' => Str::limit($outer !== '' ? $outer : (string) $responsible, 120, ''), 'notes' => $notes];
    }

    /**
     * @return array{0: ?string, 1: ?string} telefone (só dígitos) e, se não deu para usar, a nota com o da planilha
     */
    private function parsePhone(string $raw): array
    {
        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '') {
            $this->count('Sem telefone');

            return [null, null];
        }
        if (strlen($digits) === 8) {
            $this->count('Telefone sem DDD (ganhou 88 e o 9)');

            return [self::DEFAULT_DDD.'9'.$digits, null];
        }
        if (strlen($digits) === 9 && $digits[0] === '9') {
            $this->count('Telefone sem DDD (ganhou 88)');

            return [self::DEFAULT_DDD.$digits, null];
        }
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return [$digits, null];
        }
        $this->count('Telefone inválido (foi para as observações)');

        return [null, "Telefone na planilha: {$raw}"];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>> "cidade|nome normalizado" → linhas, a compra mais recente primeiro
     */
    private function group(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['city_id'].'|'.$this->key($row['name'])][] = $row;
        }
        foreach ($groups as &$group) {
            usort($group, fn ($a, $b) => strcmp((string) $b['purchase_date'], (string) $a['purchase_date']));
        }

        return $groups;
    }

    /**
     * Junta as linhas de um mesmo cliente.
     *
     * @param  list<array<string, mixed>>  $group
     * @return array<string, mixed>
     */
    private function merge(array $group): array
    {
        $first = $group[0];
        $lastDate = $first['purchase_date'];
        // Compras do mesmo dia somam; a mesma O.S. em duas linhas (ex.: nas abas Ligações e Clientes) conta uma vez.
        $sameDay = collect($group)->filter(fn ($r) => $r['purchase_date'] === $lastDate && $r['amount'] !== null)
            ->unique(fn ($r) => $r['os'] !== null ? 'os:'.trim($r['os']) : 'linha:'.$r['origin'])->all();
        $merged = count($group) > 1;
        if ($merged) {
            $this->count('Clientes juntados de mais de uma linha');
        }

        $lines = [];
        foreach ($group as $row) {
            $parts = $row['notes'];
            if ($merged && $row['purchase_date']) {
                $when = 'Compra de '.CarbonImmutable::parse($row['purchase_date'])->format('d/m/Y').($row['amount'] !== null ? ' (R$ '.number_format($row['amount'], 2, ',', '.').')' : '');
                array_unshift($parts, $when);
            }
            if ($parts) {
                $lines[] = implode(' · ', array_unique($parts));
            }
        }
        $phone = collect($group)->pluck('phone_number')->filter()->first();
        if (! $phone && ! collect($lines)->contains(fn ($l) => Str::contains(Str::lower($l), 'telefone'))) {
            $lines[] = 'Sem telefone';
        }

        return [
            'origins' => implode(', ', array_column($group, 'origin')),
            'city_id' => $first['city_id'],
            'name' => $this->bestName(array_column($group, 'name')),
            'phone_number' => $phone,
            'address' => collect($group)->pluck('address')->filter()->first(),
            'last_purchase_date' => $lastDate,
            'last_purchase_amount' => $sameDay ? round(array_sum(array_column($sameDay, 'amount')), 2) : null,
            'referred_by' => Str::limit(collect($group)->pluck('referred_by')->filter()->unique()->implode(', '), 120, '') ?: null,
            'cpf' => collect($group)->pluck('cpf')->filter()->first(),
            'birth_date' => collect($group)->pluck('birth_date')->filter()->first(),
            'notes' => $lines ? Str::limit(implode("\n", $lines), 4900, '…') : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<array{origin: string, message: string}>  $errors
     * @param  array<int, string>  $cityNames
     */
    private function summary(Company $company, int $lines, array $records, array $errors, array $cityNames): void
    {
        $this->info("Ótica: {$company->name} (id {$company->id})");
        $this->table(['', 'Quantidade'], [
            ['Linhas lidas', $lines],
            ['Clientes a criar', count($records)],
            ...collect($this->stats)->map(fn ($n, $label) => [$label, $n])->values()->all(),
            ['Linhas com erro (não entram)', count($errors)],
        ]);
        $byCity = collect($records)->countBy(fn ($r) => $cityNames[$r['city_id']] ?? $r['city_id'])->sortDesc();
        $this->table(['Cidade', 'Clientes a criar'], $byCity->map(fn ($n, $city) => [$city, $n])->values()->all());
        foreach ($errors as $error) {
            $this->warn("{$error['origin']}: {$error['message']}");
        }
        if ($this->nicknames && $this->output->isVerbose()) {
            $this->line('Apelidos (o de fora ficou como cliente):');
            foreach ($this->nicknames as $name) {
                $this->line("  {$name}");
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<array{origin: string, message: string}>  $errors
     * @param  array<int, string>  $cityNames
     */
    private function writeReport(string $path, array $records, array $errors, array $cityNames): void
    {
        $out = fopen($path, 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Situação', 'Cidade', 'Cliente', 'Telefone', 'Endereço', 'Última compra', 'Valor', 'Quem indicou', 'CPF', 'Nascimento', 'Observações', 'Linhas da planilha'], ';', escape: '');
        foreach ($records as $r) {
            fputcsv($out, [
                'Entra', $cityNames[$r['city_id']] ?? '', $r['name'], $r['phone_number'], $r['address'],
                $r['last_purchase_date'] ? CarbonImmutable::parse($r['last_purchase_date'])->format('d/m/Y') : '',
                $r['last_purchase_amount'] !== null ? number_format($r['last_purchase_amount'], 2, ',', '') : '',
                $r['referred_by'], $r['cpf'], $r['birth_date'], $r['notes'], $r['origins'],
            ], ';', escape: '');
        }
        foreach ($errors as $e) {
            fputcsv($out, ['Erro', '', $e['message'], '', '', '', '', '', '', '', '', $e['origin']], ';', escape: '');
        }
        fclose($out);
    }

    /**
     * Entre as grafias do mesmo nome, a mais bem escrita: mais palavras com maiúscula e mais acentos ("Antônio" ganha de
     * "antonio").
     *
     * @param  list<string>  $names
     */
    private function bestName(array $names): string
    {
        $score = fn (string $n) => preg_match_all('/(^|\s)\p{Lu}/u', $n) * 10 + preg_match_all('/[^\x00-\x7F]/u', $n);

        return collect($names)->sortByDesc($score)->first();
    }

    private function date(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->lte(today()) ? $date->toDateString() : null;
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    /** Chave para comparar nomes: sem acento, minúsculas e espaços simples. */
    private function key(string $value): string
    {
        return Str::lower(Str::ascii($this->clean($value)));
    }

    private function count(string $label): void
    {
        $this->stats[$label] = ($this->stats[$label] ?? 0) + 1;
    }
}
