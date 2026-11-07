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
            'notes' => $this->notes,
            'orders' => $this->orders->map(fn (LabOrder $order) => [
                'id' => $order->id,
                'patient' => $order->client ? ['id' => $order->client->id, 'name' => $order->client->name, 'birth_date' => $order->client->birth_date?->toDateString()] : null,
                'price' => $order->price,
                'pending' => $order->pending(),
                'sent' => $order->sent_at !== null,
                'status' => $order->status,
                'status_label' => $order->sent_at || $order->status === 'canceled' ? $order->status_label : 'Pendente de envio',
                'lab_number' => $order->lab_number,
            ])->values(),
            'payments' => $this->payments->map(fn (SalePayment $payment) => [
                'id' => $payment->id,
                'stage' => $payment->stage,
                'method' => $payment->method,
                'method_label' => SalePayment::METHODS[$payment->method],
                'amount' => $payment->amount,
                'installments' => $payment->installments,
                'paid_on' => $payment->paid_on->toDateString(),
            ])->values(),
            'total' => $money($this->totalCents()),
            'paid' => $money($this->paidCents()),
            'remaining' => $money($this->remainingCents()),
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
