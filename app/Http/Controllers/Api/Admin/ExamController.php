<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\SaleResource;
use App\Models\Exam;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Exames (24/11/2026): o cadastro (nome e valor; todos veem, o admin cria, edita e apaga) e os exames em que o cliente
 * da ficha tem interesse, feitos na entrega. Sem mexer no total da ficha nem no Financeiro.
 */
class ExamController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Exam::query()->orderBy('name')->get()->map(fn (Exam $exam) => $exam->only(['id', 'name', 'price']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $exam = Exam::create($this->validated($request));

        return response()->json(['data' => $exam->only(['id', 'name', 'price'])], 201);
    }

    public function update(Request $request, Exam $exam): JsonResponse
    {
        $exam->update($this->validated($request));

        return response()->json(['data' => $exam->only(['id', 'name', 'price'])]);
    }

    /** Apagar do cadastro não tira das fichas (elas guardam a cópia). */
    public function destroy(Exam $exam): Response
    {
        $exam->delete();

        return response()->noContent();
    }

    /** Os exames de interesse da ficha (a lista inteira; vazia tira todos). Até a entrega; cancelada não muda. */
    public function sale(Request $request, Sale $sale): SaleResource
    {
        $data = $request->validate([
            'exam_ids' => ['present', 'array'],
            'exam_ids.*' => ['integer', 'distinct'],
        ]);
        if (in_array($sale->status(), ['delivered', 'canceled'], true)) {
            throw ValidationException::withMessages(['exam_ids' => 'Esta ficha já foi entregue ou cancelada.']);
        }
        $catalog = Exam::query()->whereKey($data['exam_ids'])->get()->keyBy('id');
        // O que já estava na ficha fica como estava (nome e valor da época), mesmo se mudou ou saiu do cadastro.
        $current = collect($sale->exams ?? [])->keyBy('id');
        $exams = [];
        foreach ($data['exam_ids'] as $id) {
            $exam = $current->get($id) ?? (($found = $catalog->get($id)) ? ['id' => $found->id, 'name' => $found->name, 'price' => $found->price] : null);
            if (! $exam) {
                throw ValidationException::withMessages(['exam_ids' => 'Um dos exames não está mais no cadastro.']);
            }
            $exams[] = $exam;
        }
        $sale->forceFill(['exams' => $exams ?: null])->saveQuietly();

        return new SaleResource($sale->load(SaleController::RELATIONS));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999'],
        ]);
    }
}
