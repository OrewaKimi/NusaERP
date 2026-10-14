<?php

namespace App\Services;

use App\Models\AutomationLog;
use Illuminate\Support\Facades\Http;
use Throwable;

class AutomationWebhookService
{
    public function dispatch(string $event, array $payload): void
    {
        $url = config('services.n8n.webhooks.'.$event);

        if (! $url) {
            return;
        }

        $log = AutomationLog::create([
            'event' => $event,
            'target' => $url,
            'status' => 'queued',
            'payload' => $payload,
        ]);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders(['X-Automation-Token' => config('services.n8n.token')])
                ->timeout(5)
                ->post($url, $payload);

            $log->update(['status' => $response->successful() ? 'sent' : 'failed']);
        } catch (Throwable $exception) {
            $log->update([
                'status' => 'failed',
                'payload' => $payload + ['dispatch_error' => $exception->getMessage()],
            ]);
        }
    }
}
