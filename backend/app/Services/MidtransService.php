<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class MidtransService
{
    public function createTransaction(Invoice $invoice, string $orderId): array
    {
        $serverKey = config('services.midtrans.server_key');

        if (! $serverKey) {
            throw new InvalidArgumentException('Midtrans server key is not configured.');
        }

        $invoice->loadMissing('salesOrder.customer');
        $customer = $invoice->salesOrder->customer;

        $response = Http::acceptJson()
            ->asJson()
            ->withBasicAuth($serverKey, '')
            ->timeout(20)
            ->post(config('services.midtrans.snap_url'), [
                'transaction_details' => [
                    'order_id' => $orderId,
                    'gross_amount' => (int) round((float) $invoice->amount),
                ],
                'item_details' => $invoice->salesOrder->items->map(fn ($item) => [
                    'id' => (string) $item->product_id,
                    'price' => (int) round((float) $item->price),
                    'quantity' => (int) $item->qty,
                    'name' => mb_substr($item->product->name, 0, 50),
                ])->values()->all(),
                'customer_details' => [
                    'first_name' => $customer->name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                ],
                'callbacks' => [
                    'finish' => rtrim(config('services.midtrans.frontend_url'), '/').'/sales?payment=finish',
                    'error' => rtrim(config('services.midtrans.frontend_url'), '/').'/sales?payment=error',
                    'pending' => rtrim(config('services.midtrans.frontend_url'), '/').'/sales?payment=pending',
                ],
            ])
            ->throw();

        return $response->json();
    }
}