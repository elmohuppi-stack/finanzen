<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinanceImport;
use App\Services\Import\CsvImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function __construct(private readonly CsvImportService $csvImportService) {}

    public function index(Request $request): JsonResponse
    {
        $imports = FinanceImport::query()
            ->where('user_id', $request->user()->id)
            ->with('account:id,name,account_type')
            ->withMin('transactions', 'booking_date')
            ->withMax('transactions', 'booking_date')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn(FinanceImport $import): array => [
                'id' => $import->id,
                'source_type' => $import->source_type,
                'file_name' => $import->file_name,
                'status' => $import->status,
                'imported_rows' => $import->imported_rows,
                'skipped_rows' => $import->skipped_rows,
                'error_rows' => $import->error_rows,
                'imported_at' => $import->finished_at?->toIso8601String() ?? $import->started_at?->toIso8601String(),
                'period_from' => $import->transactions_min_booking_date
                    ? substr((string) $import->transactions_min_booking_date, 0, 10)
                    : null,
                'period_to' => $import->transactions_max_booking_date
                    ? substr((string) $import->transactions_max_booking_date, 0, 10)
                    : null,
                'account_name' => $import->account?->name,
                'account_type' => $import->account?->account_type,
                'notes' => $import->notes,
            ])
            ->values();

        return response()->json([
            'imports' => $imports,
        ]);
    }

    public function detect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $file = $validated['file'];
        $content = (string) $file->get();
        $preview = $this->csvImportService->preview($content);
        $analysis = $this->csvImportService->inspectImport($request->user(), $content, $preview);

        return response()->json([
            ...$preview,
            'analysis' => $analysis,
            'file_name' => $file->getClientOriginalName(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $file = $validated['file'];
        $import = $this->csvImportService->import(
            $request->user(),
            $file->getClientOriginalName(),
            (string) $file->get(),
        );

        return response()->json([
            'message' => 'Import abgeschlossen.',
            'import' => [
                'id' => $import->id,
                'source_type' => $import->source_type,
                'file_name' => $import->file_name,
                'status' => $import->status,
                'imported_rows' => $import->imported_rows,
                'skipped_rows' => $import->skipped_rows,
                'error_rows' => $import->error_rows,
                'started_at' => $import->started_at?->toIso8601String(),
                'finished_at' => $import->finished_at?->toIso8601String(),
                'notes' => $import->notes,
                'account' => [
                    'id' => $import->account?->id,
                    'name' => $import->account?->name,
                    'account_type' => $import->account?->account_type,
                ],
            ],
        ], 201);
    }
}
