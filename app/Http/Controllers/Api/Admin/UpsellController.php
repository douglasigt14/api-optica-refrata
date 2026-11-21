<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CityVisit;
use App\Models\LabOrder;
use App\Services\Laboratory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Upsell do óculos (21/11/2026): o operador marca os tratamentos vendidos a mais (do catálogo do laboratório do pedido),
 * o limpa lentes (com a quantidade) e digita quem vendeu. Sem valor: não mexe no total da ficha nem no Financeiro.
 * O relatório do atendimento lista todos os upsells, para imprimir.
 */
class UpsellController extends Controller
{
    public function __construct(private Laboratory $laboratory) {}

    /** Salva o upsell do óculos; sem nada marcado, apaga. */
    public function update(Request $request, LabOrder $labOrder): JsonResponse
    {
        $data = $request->validate([
            'treatment_ids' => ['present', 'array'],
            'treatment_ids.*' => ['integer', 'distinct'],
            'lens_cleaner' => ['required', 'integer', 'min:0', 'max:99'],
            'seller' => ['nullable', 'string', 'max:120'],
        ]);
        if ($labOrder->status === 'canceled') {
            throw ValidationException::withMessages(['status' => 'Este óculos foi cancelado.']);
        }
        $empty = ! $data['treatment_ids'] && (int) $data['lens_cleaner'] === 0;
        if (! $empty && blank($data['seller'] ?? null)) {
            throw ValidationException::withMessages(['seller' => 'Informe quem vendeu o upsell.']);
        }

        $treatments = [];
        if ($data['treatment_ids']) {
            // O nome vem do catálogo do laboratório e fica guardado (o relatório não depende do laboratório no ar).
            try {
                $catalog = collect($this->laboratory->catalog($labOrder->lab)['treatments'] ?? [])->keyBy('id');
            } catch (RuntimeException $error) {
                throw ValidationException::withMessages(['connection' => $error->getMessage()]);
            }
            foreach ($data['treatment_ids'] as $id) {
                $treatment = $catalog->get($id) ?? throw ValidationException::withMessages(['treatment_ids' => 'Um dos tratamentos não está mais no catálogo do laboratório.']);
                $treatments[] = ['id' => (int) $treatment['id'], 'name' => $treatment['name']];
            }
        }

        $labOrder->forceFill($empty ? ['upsell' => null, 'upsell_at' => null, 'upsell_by' => null] : [
            'upsell' => ['treatments' => $treatments, 'lens_cleaner' => (int) $data['lens_cleaner'], 'seller' => trim($data['seller'])],
            'upsell_at' => now(),
            'upsell_by' => $request->user()->id,
        ])->saveQuietly();

        return response()->json(['data' => ['id' => $labOrder->id, 'upsell' => $labOrder->upsell]]);
    }

    /** Relatório do atendimento: todos os óculos com upsell (sem os cancelados), e quanto cada vendedor vendeu. */
    public function visit(CityVisit $cityVisit): JsonResponse
    {
        $cityVisit->load('city');
        $orders = LabOrder::query()->where('city_visit_id', $cityVisit->id)->whereNotNull('upsell')
            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'canceled'))
            ->with(['client', 'sale.client'])->orderBy('number')->get();

        $rows = $orders->map(fn (LabOrder $order) => [
            'id' => $order->id,
            'number' => $order->number,
            'lab_number' => $order->lab_number,
            'sale_id' => $order->sale_id,
            'buyer' => $order->sale?->client?->name,
            'patient' => $order->client?->name,
            'treatments' => array_column($order->upsell['treatments'] ?? [], 'name'),
            'lens_cleaner' => (int) ($order->upsell['lens_cleaner'] ?? 0),
            'seller' => $order->upsell['seller'] ?? null,
            'upsell_at' => $order->upsell_at?->toIso8601String(),
        ])->values();

        $sellers = $rows->groupBy(fn (array $row) => mb_strtolower(trim((string) $row['seller'])))->map(fn ($group) => [
            'seller' => $group->first()['seller'],
            'glasses' => $group->count(),
            'treatments' => $group->sum(fn (array $row) => count($row['treatments'])),
            'lens_cleaner' => $group->sum('lens_cleaner'),
        ])->sortByDesc('treatments')->values();

        return response()->json(['data' => [
            'visit' => [
                'id' => $cityVisit->id,
                'title' => $cityVisit->title,
                'city' => $cityVisit->city?->name,
                'visit_date' => $cityVisit->visit_date?->toDateString(),
                'end_date' => $cityVisit->end_date?->toDateString(),
            ],
            'upsells' => $rows,
            'sellers' => $sellers,
        ]]);
    }
}
