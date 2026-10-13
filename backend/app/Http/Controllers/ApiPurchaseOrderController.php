<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\AutomationWebhookService;
use App\Services\AutomationService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class ApiPurchaseOrderController extends Controller
{
    public function index()
    {
        return response()->json(
            PurchaseOrder::with(['supplier', 'items.product'])->latest()->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.cost' => ['required', 'numeric', 'min:0'],
        ]);

        $total = 0;
        foreach ($validated['items'] as $item) {
            $total += $item['qty'] * $item['cost'];
        }

        $purchaseOrder = PurchaseOrder::create([
            'supplier_id' => $validated['supplier_id'],
            'status' => 'draft',
            'total' => $total,
        ]);

        foreach ($validated['items'] as $item) {
            $purchaseOrder->items()->create([
                'product_id' => $item['product_id'],
                'qty' => $item['qty'],
                'cost' => $item['cost'],
            ]);
        }

        return response()->json([
            'message' => 'Purchase order berhasil dibuat.',
            'purchase_order' => $purchaseOrder->load(['supplier', 'items.product']),
        ], 201);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        return response()->json($purchaseOrder->load(['supplier', 'items.product']));
    }

    public function approve(PurchaseOrder $purchaseOrder, AutomationService $automationService)
    {
        $purchaseOrder = $automationService->approvePurchaseOrder($purchaseOrder);

        return response()->json([
            'message' => 'Purchase order disetujui.',
            'purchase_order' => $purchaseOrder->fresh(['supplier', 'items.product']),
        ]);
    }

    public function submit(PurchaseOrder $purchaseOrder, AutomationWebhookService $automationWebhook)
    {
        return DB::transaction(function () use ($purchaseOrder, $automationWebhook) {
            $purchaseOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);

            if ($purchaseOrder->status !== 'draft') {
                return response()->json(['message' => 'Hanya PO draft yang dapat dikirim untuk approval.'], 409);
            }

            $purchaseOrder->update(['status' => 'submitted']);
            $payload = [
                'purchase_order_id' => $purchaseOrder->id,
                'supplier' => $purchaseOrder->supplier()->value('name'),
                'total' => (float) $purchaseOrder->total,
            ];
            DB::afterCommit(fn () => $automationWebhook->dispatch('po_submitted', $payload));

            return response()->json([
                'message' => 'Purchase order dikirim untuk approval.',
                'purchase_order' => $purchaseOrder->fresh(['supplier', 'items.product']),
            ]);
        });
    }

    public function receive(PurchaseOrder $purchaseOrder, StockService $stockService)
    {
        return DB::transaction(function () use ($purchaseOrder, $stockService) {
            $purchaseOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);

            if ($purchaseOrder->status !== 'approved') {
                return response()->json(['message' => 'PO harus berstatus approved sebelum barang diterima.'], 409);
            }

            $purchaseOrder->update([
                'status' => 'received',
                'received_at' => now(),
            ]);

            $stockService->applyPurchaseReceived($purchaseOrder->load('items'));

            return response()->json([
                'message' => 'Barang diterima dan stok ditambah.',
                'purchase_order' => $purchaseOrder->fresh(['supplier', 'items.product']),
            ]);
        });
    }
}
