<?php

namespace App\Http\Resources\Admin;

use App\Models\LabOrder;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sale
 */
class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $money = fn (int $cents) => number_format($cents / 100, 2, '.', '');

        return [
            'id' => $this->id,
            'status' => $this->status(),
            'locked' => $this->locked(),
            'city_visit' => $this->cityVisit ? [
                'id' => $this->cityVisit->id,
                'title' => $this->cityVisit->title,
                'city' => $this->cityVisit->city?->name,
                'visit_date' => $this->cityVisit->visit_date->toDateString(),
                'end_date' => $this->cityVisit->end_date?->toDateString(),
            ] : null,
            'client' => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'cpf' => $this->client->cpf,
                'phone_number' => $this->client->phone_number,
                'address' => $this->client->address,
                'city' => $this->client->city?->name,
            ] : null,
            'expected_delivery_date' => $this->expected_delivery_date->toDateString(),
            // Ficha do atendimento: a data é a do atendimento (troca-se lá); só a da loja edita a sua.
            'delivery_from_visit' => $this->city_visit_id !== null,
            'notes' => $this->notes,
            'orders' => $this->orders->map(fn (LabOrder $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'patient' => $order->client ? ['id' => $order->client->id, 'name' => $order->client->name, 'birth_date' => $order->client->birth_date?->toDateString()] : null,
                'price' => $order->price,
                'pending' => $order->pending(),
                'sent' => $order->sent_at !== null,
                'status' => $order->status,
                'status_label' => $order->sent_at || $order->status === 'canceled' ? $order->status_label : 'Pendente de envio',
                'lab_number' => $order->lab_number,
                // Para a lista do atendimento (vendas em acordeão, 09/11/2026).
                'lab' => $order->lab ? ['id' => $order->lab->id, 'name' => $order->lab->name] : null,
                'lab_batch' => $order->lab_batch,
                // Lente pedida (os códigos; o nome vem do catálogo do laboratório, na tela).
                'lens' => [
                    'lens_type' => $order->payload['lens_type'] ?? null,
                    'bifocal_style' => $order->payload['bifocal_style'] ?? null,
                    'material' => $order->payload['material'] ?? null,
                    'refractive_index' => $order->payload['refractive_index'] ?? null,
                ],
                'awaits_frame' => $order->awaits_frame,
                'created_at' => $order->created_at?->toIso8601String(),
            ])->values(),
            'payments' => $this->payments->map(fn (SalePayment $payment) => [
                'id' => $payment->id,
                'stage' => $payment->stage,
                'method' => $payment->method,
                'method_label' => SalePayment::METHODS[$payment->method],
                'amount' => $payment->amount,
                'installments' => $payment->installments,
                'paid_on' => $payment->paid_on->toDateString(),
                // Baixa feita no Financeiro (09/11/2026); enquanto não volta no estado da ficha, só se estorna lá.
                'from_financial' => $payment->from_financial,
                'removable' => $payment->financial_payment_id === null,
            ])->values(),
            'total' => $money($this->totalCents()),
            'paid' => $money($this->paidCents()),
            'remaining' => $money($this->remainingCents()),
            // Lançada e tudo pago (09/11/2026): "Quitado". Quitar não é entregar.
            'settled' => $this->status() === 'launched' && $this->totalCents() > 0 && $this->remainingCents() === 0,
            'launched_at' => $this->launched_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'canceled_at' => $this->canceled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'financial_synced_at' => $this->financial_synced_at?->toIso8601String(),
            'financial_error' => $this->financial_error,
            'user' => $this->user?->name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
