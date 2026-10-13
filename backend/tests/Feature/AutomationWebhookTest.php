<?php

namespace Tests\Feature;

use App\Models\AutomationLog;
use App\Services\AutomationWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutomationWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_dispatch_posts_payload_and_records_success(): void
    {
        config([
            'services.n8n.token' => 'test-token',
            'services.n8n.webhooks.payment_success' => 'http://n8n.test/webhook/payment-success',
        ]);
        Http::fake(['http://n8n.test/*' => Http::response(['accepted' => true], 200)]);

        app(AutomationWebhookService::class)->dispatch('payment_success', ['order_id' => 'NUSA-1']);

        Http::assertSent(fn ($request) =>
            $request->url() === 'http://n8n.test/webhook/payment-success'
            && $request['order_id'] === 'NUSA-1'
            && $request->hasHeader('X-Automation-Token', 'test-token')
        );
        $this->assertDatabaseHas('automation_logs', [
            'event' => 'payment_success',
            'status' => 'sent',
        ]);
    }

    public function test_failed_n8n_delivery_is_logged_without_throwing(): void
    {
        config([
            'services.n8n.token' => 'test-token',
            'services.n8n.webhooks.po_submitted' => 'http://n8n.test/webhook/po-submitted',
        ]);
        Http::fake(['http://n8n.test/*' => Http::response(['error' => 'unavailable'], 503)]);

        app(AutomationWebhookService::class)->dispatch('po_submitted', ['purchase_order_id' => 22]);

        $this->assertDatabaseHas('automation_logs', [
            'event' => 'po_submitted',
            'status' => 'failed',
        ]);
    }
}
