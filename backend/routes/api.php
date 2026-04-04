<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryRuleController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/health', function (): JsonResponse {
    return response()->json([
        'status' => 'ok',
        'app' => 'finanzen-api',
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/imports', [ImportController::class, 'index']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/imports/detect', [ImportController::class, 'detect']);
    Route::post('/imports', [ImportController::class, 'store']);
    Route::get('/category-rules', [CategoryRuleController::class, 'index']);
    Route::get('/category-rules/export', [CategoryRuleController::class, 'export']);
    Route::post('/category-rules', [CategoryRuleController::class, 'store']);
    Route::post('/category-rules/import', [CategoryRuleController::class, 'import']);
    Route::post('/category-rules/import-defaults', [CategoryRuleController::class, 'importDefaults']);
    Route::post('/category-rules/preview', [CategoryRuleController::class, 'preview']);
    Route::post('/category-rules/apply', [CategoryRuleController::class, 'apply']);
    Route::patch('/category-rules/{ruleId}', [CategoryRuleController::class, 'update']);
    Route::delete('/category-rules/reset', [CategoryRuleController::class, 'reset']);
    Route::delete('/category-rules/{ruleId}', [CategoryRuleController::class, 'destroy']);
    Route::patch('/transactions/{transaction}/category', [TransactionController::class, 'updateCategory']);
});
