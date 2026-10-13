<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;

class ApiDashboardController extends Controller
{
    public function summary()
    {
        $totalRevenue = Invoice::where('status', 'paid')->sum('amount');
        $totalOrders = SalesOrder::count();
        $unpaidInvoices = Invoice::where('status', '!=', 'paid')->count();
        $lowStockProducts = Product::whereColumn('stock', '<=', 'min_stock')->count();

        return response()->json([
            'total_revenue' => (float) $totalRevenue,
            'total_orders' => $totalOrders,
            'unpaid_invoices' => $unpaidInvoices,
            'low_stock_products' => $lowStockProducts,
        ]);
    }

    public function salesChart()
    {
        $startDate = Carbon::today()->subDays(6);
        $orders = SalesOrder::query()
            ->selectRaw('DATE(created_at) as date, SUM(total) as total')
            ->where('status', 'paid')
            ->where('created_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $totalsByDate = $orders->keyBy(fn ($order) => Carbon::parse($order->date)->toDateString());
        $chart = collect(range(0, 6))->map(function (int $day) use ($startDate, $totalsByDate) {
            $date = $startDate->copy()->addDays($day)->toDateString();

            return [
                'date' => $date,
                'total' => (float) ($totalsByDate->get($date)?->total ?? 0),
            ];
        });

        return response()->json($chart);
    }

    public function exchangeRate(Request $request)
    {
        $to = $request->query('to', 'USD');
        $response = Http::get('https://api.frankfurter.app/latest', [
            'from' => 'IDR',
            'to' => $to,
        ]);

        if (! $response->successful()) {
            return response()->json(['message' => 'Exchange rate service unavailable.'], 502);
        }

        return response()->json($response->json());
    }
}
