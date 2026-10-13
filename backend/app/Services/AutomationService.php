<?php

namespace App\Services;

use App\Models\AutomationLog;
use App\Models\Product;
use App\Models\PurchaseOrder;
use Illuminate\Support\Carbon;

class AutomationService
{
    public function lowStock(): array
    {
        $items = Product::whereColumn('stock', '<=', 'min_stock')->get();

        AutomationLog::create([
            'event' => 'low_stock_alert',
            'target' => 'inventory',
            'status' => 'sent',
            'payload' => [
                'count' => $items->count(),
                'items' => $items->map(fn ($i) => ['sku' => $i->sku, 'stock' => $i->stock, 'min_stock' => $i->min_stock]),
            ],
        ]);

        return [
            'count' => $items->count(),
            'items' => $items->map(fn ($i) => ['sku' => $i->sku, 'stock' => $i->stock, 'min_stock' => $i->min_stock])->all(),
        ];
    }

    public function approvePurchaseOrder(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        if ($purchaseOrder->status !== 'submitted') {
            abort(409, 'Hanya PO submitted yang dapat disetujui.');
        }

        $purchaseOrder->update([
            'status' => 'approved',
            'approved_at' => Carbon::now(),
        ]);

        AutomationLog::create([
            'event' => 'purchase_order_approved',
            'target' => 'purchase_order:'.$purchaseOrder->id,
            'status' => 'success',
            'payload' => ['purchase_order_id' => $purchaseOrder->id],
        ]);

        return $purchaseOrder;
    }
}
