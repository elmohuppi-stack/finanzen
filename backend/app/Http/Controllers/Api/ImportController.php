<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Import\CsvImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function __construct(private readonly CsvImportService $csvImportService) {}

    public function detect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $file = $validated['file'];
        $content = (string) $file->get();
        $preview = $this->csvImportService->preview($content);

        return response()->json([
            ...$preview,
            'file_name' => $file->getClientOriginalName(),
        ]);
    }
}
