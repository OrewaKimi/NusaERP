<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class ApiProductController extends Controller
{
    public function index()
    {
        return response()->json(Product::latest()->get());
    }

    public function store(Request $request, StockService $stockService)
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:100', 'unique:products,sku'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'min_stock' => ['required', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
        ]);

        $product = DB::transaction(function () use ($validated, $stockService) {
            $initialStock = $validated['stock'];
            $validated['stock'] = 0;
            $product = Product::create($validated);

            if ($initialStock > 0) {
                $stockService->recordMovement($product, 'in', $initialStock, 'opening_stock', $product->id, 'Opening stock');
            }

            return $product->fresh();
        });

        return response()->json($product, 201);
    }

    public function show(Product $product)
    {
        return response()->json($product->fresh());
    }

    public function update(Request $request, Product $product, StockService $stockService)
    {
        $validated = $request->validate([
            'sku' => ['sometimes', 'required', 'string', 'max:100', 'unique:products,sku,'.$product->id],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'required', 'integer', 'min:0'],
            'min_stock' => ['sometimes', 'required', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($product, $validated, $stockService) {
            $newStock = $validated['stock'] ?? (int) $product->stock;
            unset($validated['stock']);
            $product->update($validated);

            $stockDifference = $newStock - (int) $product->stock;
            if ($stockDifference !== 0) {
                $stockService->adjustStock(
                    $product,
                    $stockDifference > 0 ? 'in' : 'out',
                    abs($stockDifference),
                    'Stock updated from product form'
                );
            }
        });

        return response()->json($product->fresh());
    }

    public function destroy(Product $product)
    {
        if ($product->salesOrderItems()->exists() || $product->purchaseOrderItems()->exists() || $product->stockMovements()->exists()) {
            return response()->json([
                'message' => 'Produk tidak dapat dihapus karena sudah digunakan dalam transaksi.',
            ], 409);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    public function movements(Product $product)
    {
        return response()->json($product->stockMovements()->latest()->get());
    }

    public function adjustStock(Request $request, Product $product, StockService $stockService)
    {
        $validated = $request->validate([
            'direction' => ['required', 'in:in,out'],
            'qty' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $stockService->adjustStock(
            $product,
            $validated['direction'],
            $validated['qty'],
            $validated['note'] ?? ''
        );

        return response()->json([
            'message' => 'Penyesuaian stok berhasil dicatat.',
            'product' => $product->fresh(),
        ]);
    }
}
