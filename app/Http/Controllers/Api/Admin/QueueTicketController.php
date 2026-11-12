<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CityVisit;
use App\Models\QueueTicket;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Senhas da fila do atendimento (12/11/2026), na tela de Pedidos: emitir (normal N001… ou prioridade P001…, contando do
 * 1 em cada atendimento) e chamar a próxima (a prioridade passa na frente; dentro do tipo, a mais antiga).
 */
class QueueTicketController extends Controller
{
    /**
     * As senhas do atendimento: a última chamada, as que aguardam (na ordem em que serão chamadas) e as já chamadas.
     */
    public function index(CityVisit $cityVisit): JsonResponse
    {
        return response()->json(['data' => $this->queue($cityVisit)]);
    }

    /**
     * Grava a próxima senha do tipo. O painel imprime antes e só grava depois que a pessoa confirma que saiu, mandando o
     * `number` impresso: se outro computador já gravou esse número, recusa (a senha precisa ser impressa de novo). Sem
     * `number`, grava a próxima. Duas gravações ao mesmo tempo: o índice único segura e a segunda tenta de novo.
     */
    public function store(Request $request, CityVisit $cityVisit): JsonResponse
    {
        $data = $request->validate(
            ['type' => ['required', Rule::in(array_keys(QueueTicket::TYPES))], 'number' => ['nullable', 'integer', 'min:1']],
            ['type.required' => 'Escolha senha normal ou prioridade.', 'type.in' => 'Escolha senha normal ou prioridade.'],
        );

        $ticket = retry(3, fn () => DB::transaction(function () use ($cityVisit, $data, $request) {
            $last = QueueTicket::where('city_visit_id', $cityVisit->id)->where('type', $data['type'])->lockForUpdate()->max('number');
            $next = ((int) $last) + 1;
            if (isset($data['number']) && (int) $data['number'] !== $next) {
                $printed = new QueueTicket(['type' => $data['type'], 'number' => (int) $data['number']]);
                $free = new QueueTicket(['type' => $data['type'], 'number' => $next]);
                throw ValidationException::withMessages(['number' => "A senha {$printed->code()} já foi emitida em outro computador. Imprima de novo: a próxima é {$free->code()}."]);
            }

            return QueueTicket::create([
                'city_visit_id' => $cityVisit->id,
                'type' => $data['type'],
                'number' => $next,
                'user_id' => $request->user()->id,
            ]);
        }), 50, fn ($e) => $e instanceof UniqueConstraintViolationException);

        return response()->json(['data' => self::ticketData($ticket)], 201);
    }

    /**
     * Chama a próxima senha. Com `type` (duas filas, 12/11/2026), a próxima daquela fila; sem, a prioridade primeiro.
     * Dentro do tipo, a de menor número.
     */
    public function callNext(Request $request, CityVisit $cityVisit): JsonResponse
    {
        $data = $request->validate(['type' => ['nullable', Rule::in(array_keys(QueueTicket::TYPES))]], ['type.in' => 'Escolha a fila normal ou prioridade.']);
        $type = $data['type'] ?? null;

        $ticket = DB::transaction(function () use ($cityVisit, $request, $type) {
            $next = QueueTicket::where('city_visit_id', $cityVisit->id)->whereNull('called_at')
                ->when($type, fn ($q) => $q->where('type', $type))
                ->orderByRaw("CASE WHEN type = 'priority' THEN 0 ELSE 1 END")->orderBy('number')
                ->lockForUpdate()->first();
            if (! $next) {
                $queue = $type ? ' na fila '.($type === 'priority' ? 'prioridade' : 'normal') : '';
                throw ValidationException::withMessages(['ticket' => "Não há senha aguardando{$queue}."]);
            }
            $next->update(['called_at' => now(), 'called_by' => $request->user()->id]);

            return $next;
        });

        return response()->json(['data' => self::ticketData($ticket)]);
    }

    /**
     * Chama uma senha escolhida (fora da ordem, ou de novo, quando a pessoa não ouviu).
     */
    public function call(Request $request, CityVisit $cityVisit, QueueTicket $ticket): JsonResponse
    {
        abort_unless($ticket->city_visit_id === $cityVisit->id, 404);
        $ticket->update(['called_at' => now(), 'called_by' => $request->user()->id]);

        return response()->json(['data' => self::ticketData($ticket)]);
    }

    /**
     * Zerar a fila (só o admin, 12/11/2026): apaga todas as senhas do atendimento; as duas filas voltam para 001.
     */
    public function reset(CityVisit $cityVisit): JsonResponse
    {
        QueueTicket::where('city_visit_id', $cityVisit->id)->delete();

        return response()->json(['data' => $this->queue($cityVisit)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function queue(CityVisit $cityVisit): array
    {
        $tickets = QueueTicket::where('city_visit_id', $cityVisit->id)->get();
        $waiting = $tickets->whereNull('called_at')->sortBy(fn (QueueTicket $t) => [$t->type === 'priority' ? 0 : 1, $t->number]);
        $called = $tickets->whereNotNull('called_at')->sortByDesc(fn (QueueTicket $t) => [$t->called_at->getTimestamp(), $t->id]);

        return [
            'current' => ($current = $called->first()) ? self::ticketData($current) : null,
            'waiting' => $waiting->map(fn (QueueTicket $t) => self::ticketData($t))->values()->all(),
            'called' => $called->map(fn (QueueTicket $t) => self::ticketData($t))->values()->all(),
            'issued' => [
                'normal' => $tickets->where('type', 'normal')->count(),
                'priority' => $tickets->where('type', 'priority')->count(),
            ],
            // A próxima a emitir de cada tipo (o painel imprime com ela e grava depois de confirmar).
            'next_number' => [
                'normal' => ((int) $tickets->where('type', 'normal')->max('number')) + 1,
                'priority' => ((int) $tickets->where('type', 'priority')->max('number')) + 1,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function ticketData(QueueTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'type' => $ticket->type,
            'number' => $ticket->number,
            'code' => $ticket->code(),
            'called_at' => $ticket->called_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];
    }
}
