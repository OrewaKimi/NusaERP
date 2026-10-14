<?php

use App\Http\Controllers\ApiAuthController;
use App\Http\Controllers\ApiAutomationController;
use App\Http\Controllers\ApiCustomerController;
use App\Http\Controllers\ApiDashboardController;
use App\Http\Controllers\ApiProductController;
use App\Http\Controllers\ApiPurchaseOrderController;
use App\Http\Controllers\ApiSalesController;
use App\Http\Controllers\ApiSupplierController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [ApiAuthController::class, 'login']);
Route::get('/external/exchange-rate', [ApiDashboardController::class, 'exchangeRate']);
Route::post('/payments/midtrans/notification', [ApiSalesController::class, 'midtransNotification']);
Route::get('/automation/low-stock', [ApiAutomationController::class, 'lowStock']);
Route::post('/automation/po/{purchaseOrder}/approve', [ApiAutomationController::class, 'approvePurchaseOrder']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [ApiAuthController::class, 'me']);
    Route::post('/logout', [ApiAuthController::class, 'logout']);

    Route::apiResource('customers', ApiCustomerController::class)
        ->only(['index', 'show']);
    Route::apiResource('customers', ApiCustomerController::class)
        ->except(['index', 'show'])
        ->middleware('role:admin,sales');
    Route::apiResource('suppliers', ApiSupplierController::class)
        ->only(['index', 'show']);
    Route::apiResource('suppliers', ApiSupplierController::class)
        ->except(['index', 'show'])
        ->middleware('role:admin,gudang');

    Route::get('/products', [ApiProductController::class, 'index']);
    Route::get('/products/{product}', [ApiProductController::class, 'show']);
    Route::get('/products/{product}/movements', [ApiProductController::class, 'movements']);
    Route::middleware('role:admin,gudang')->group(function () {
        Route::post('/products', [ApiProductController::class, 'store']);
        Route::put('/products/{product}', [ApiProductController::class, 'update']);
        Route::delete('/products/{product}', [ApiProductController::class, 'destroy']);
        Route::post('/products/{product}/adjust-stock', [ApiProductController::class, 'adjustStock']);
    });

    Route::get('/sales-orders', [ApiSalesController::class, 'index']);
    Route::get('/sales-orders/{salesOrder}', [ApiSalesController::class, 'show']);
    Route::get('/payments/midtrans/{orderId}/status', [ApiSalesController::class, 'syncMidtransStatus']);
    Route::middleware('role:admin,sales')->group(function () {
        Route::post('/sales-orders', [ApiSalesController::class, 'store']);
        Route::post('/sales-orders/{salesOrder}/invoice', [ApiSalesController::class, 'createInvoice']);
        Route::post('/invoices/{invoice}/pay', [ApiSalesController::class, 'pay']);
    });

    Route::get('/purchase-orders', [ApiPurchaseOrderController::class, 'index']);
    Route::get('/purchase-orders/{purchaseOrder}', [ApiPurchaseOrderController::class, 'show']);
    Route::middleware('role:admin,gudang')->group(function () {
        Route::post('/purchase-orders', [ApiPurchaseOrderController::class, 'store']);
        Route::post('/purchase-orders/{purchaseOrder}/submit', [ApiPurchaseOrderController::class, 'submit']);
        Route::post('/purchase-orders/{purchaseOrder}/receive', [ApiPurchaseOrderController::class, 'receive']);
    });
    Route::post('/purchase-orders/{purchaseOrder}/approve', [ApiPurchaseOrderController::class, 'approve'])
        ->middleware('role:admin');

    Route::get('/dashboard/summary', [ApiDashboardController::class, 'summary']);
    Route::get('/dashboard/sales-chart', [ApiDashboardController::class, 'salesChart']);
});
