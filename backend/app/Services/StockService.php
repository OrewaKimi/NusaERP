<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockService
{
    public function applyInvoiceSettlement(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            foreach ($invoice->salesOrder->items as $item) {
                $product = Product::findOrFail($item->product_id);
                $this->recordMovement(
                    $product,
                    'out',
                    (int) $item->qty,
                    'invoice',
                    $invoice->id,
                    'Invoice paid: '.$invoice->number
                );
            }
        });
    }

    public function applyPurchaseReceived(PurchaseOrder $purchaseOrder): void
    {
        DB::transaction(function () use ($purchaseOrder) {
            foreach ($purchaseOrder->items as $item) {
                $product = Product::findOrFail($item->product_id);
                $this->recordMovement(
                    $product,
                    'in',
                    (int) $item->qty,
                    'purchase_order',
                    $purchaseOrder->id,
                    'PO received: #'.$purchaseOrder->id
                );
            }
        });
    }

    public function adjustStock(Product $product, string $direction, int $qty, string $note = ''): void
    {
        DB::transaction(function () use ($product, $direction, $qty, $note) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($direction === 'out' && $qty > (int) $product->stock) {
                throw ValidationException::withMessages([
                    'qty' => 'Jumlah stok keluar melebihi stok tersedia.',
                ]);
            }

            $product->stock += $direction === 'out' ? -$qty : $qty;
            $product->save();

            StockMovement::create([
                'product_id' => $product->id,
                'type' => 'adjust',
                'qty' => $qty,
                'ref_type' => 'manual_adjustment',
                'ref_id' => $product->id,
                'note' => trim($direction.': '.$note),
            ]);
        });
    }

    public function recordMovement(Product $product, string $type, int $qty, string $refType, int $refId, string $note = ''): void
    {
        $direction = $type === 'out' ? -1 : 1;
        $product->stock = max(0, (int) $product->stock + ($qty * $direction));
        $product->save();

        StockMovement::create([
            'product_id' => $product->id,
            'type' => $type,
            'qty' => $qty,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'note' => $note,
        ]);
    }
}
