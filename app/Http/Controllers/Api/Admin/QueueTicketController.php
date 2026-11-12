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
     * Emite a próxima senha do tipo. Duas emissões ao mesmo tempo: o índice único segura e a segunda tenta de novo.
     */
    public function store(Request $request, CityVisit $cityVisit): JsonResponse
    {
        $data = $request->validate(
            ['type' => ['required', Rule::in(array_keys(QueueTicket::TYPES))]],
            ['type.required' => 'Escolha senha normal ou prioridade.', 'type.in' => 'Escolha senha normal ou prioridade.'],
        );

        $ticket = retry(3, fn () => DB::transaction(function () use ($cityVisit, $data, $request) {
            $last = QueueTicket::where('city_visit_id', $cityVisit->id)->where('type', $data['type'])->lockForUpdate()->max('number');

            return QueueTicket::create([
                'city_visit_id' => $cityVisit->id,
                'type' => $data['type'],
                'number' => ((int) $last) + 1,
                'user_id' => $request->user()->id,
            ]);
        }), 50, fn ($e) => $e instanceof UniqueConstraintViolationException);

        return response()->json(['data' => self::ticketData($ticket)], 201);
    }

    /**
     * Chama a próxima senha: prioridade primeiro; dentro do tipo, a de menor número.
     */
    public function callNext(Request $request, CityVisit $cityVisit): JsonResponse
    {
        $ticket = DB::transaction(function () use ($cityVisit, $request) {
            $next = QueueTicket::where('city_visit_id', $cityVisit->id)->whereNull('called_at')
                ->orderByRaw("CASE WHEN type = 'priority' THEN 0 ELSE 1 END")->orderBy('number')
                ->lockForUpdate()->first();
            if (! $next) {
                throw ValidationException::withMessages(['ticket' => 'Não há senha aguardando.']);
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
