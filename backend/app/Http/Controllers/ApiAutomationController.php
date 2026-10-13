<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Services\AutomationService;
use Illuminate\Http\Request;

class ApiAutomationController extends Controller
{
    public function lowStock(Request $request)
    {
        $token = $request->header('X-Automation-Token');
        $expected = config('services.n8n.token');

        if (! $expected || ! hash_equals($expected, (string) $token)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json((new AutomationService())->lowStock());
    }

    public function approvePurchaseOrder(Request $request, PurchaseOrder $purchaseOrder)
    {
        $token = $request->header('X-Automation-Token');
        $expected = config('services.n8n.token');

        if (! $expected || ! hash_equals($expected, (string) $token)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $purchaseOrder = (new AutomationService())->approvePurchaseOrder($purchaseOrder);

        return response()->json([
            'message' => 'Purchase order approved by automation.',
            'purchase_order' => $purchaseOrder->fresh(['supplier', 'items.product']),
        ]);
    }
}
