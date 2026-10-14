<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class MidtransService
{
    public function createTransaction(Invoice $invoice, string $orderId): array
    {
        $this->assertConfiguration();
        $serverKey = config('services.midtrans.server_key');
        $snapUrl = (string) config('services.midtrans.snap_url');

        $invoice->loadMissing('salesOrder.customer');
        $customer = $invoice->salesOrder->customer;

        $response = Http::acceptJson()
            ->asJson()
            ->withBasicAuth($serverKey, '')
            ->timeout(20)
            ->post($snapUrl, [
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

    public function assertConfiguration(): string
    {
        $serverKey = (string) config('services.midtrans.server_key');
        $clientKey = (string) config('services.midtrans.client_key');
        $snapUrl = (string) config('services.midtrans.snap_url');

        if ($serverKey === '') {
            throw new InvalidArgumentException('Midtrans server key is not configured.');
        }

        $serverEnvironment = $this->keyEnvironment($serverKey, 'server');
        $endpointEnvironment = match ([strtolower((string) parse_url($snapUrl, PHP_URL_SCHEME)), strtolower((string) parse_url($snapUrl, PHP_URL_HOST))]) {
            ['https', 'app.sandbox.midtrans.com'] => 'sandbox',
            ['https', 'app.midtrans.com'] => 'production',
            default => null,
        };

        if (! $serverEnvironment || ! $endpointEnvironment || $serverEnvironment !== $endpointEnvironment) {
            throw new InvalidArgumentException('Midtrans server key and Snap URL must use the same environment.');
        }

        if ($clientKey !== '' && $this->keyEnvironment($clientKey, 'client') !== $serverEnvironment) {
            throw new InvalidArgumentException('Midtrans client key and server key must use the same environment.');
        }

        return $serverEnvironment;
    }

    private function keyEnvironment(string $key, string $type): ?string
    {
        $prefix = $type === 'server' ? 'Mid-server-' : 'Mid-client-';

        return match (true) {
            str_starts_with($key, 'SB-'.$prefix) => 'sandbox',
            str_starts_with($key, $prefix) => 'production',
            default => null,
        };
    }
}
