<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardChartTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_chart_returns_seven_calendar_days_including_zero_days(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/dashboard/sales-chart');

        $response->assertOk()->assertJsonCount(7);
        $chart = $response->json();
        $this->assertSame(7, count(array_unique(array_column($chart, 'date'))));
        $this->assertEquals(0, $chart[6]['total']);
    }
}
