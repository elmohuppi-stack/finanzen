<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): JsonResponse {
    return response()->json([
        'name' => 'finanzen-api',
        'status' => 'ok',
        'message' => 'Finance backend scaffold is running.',
    ]);
});
