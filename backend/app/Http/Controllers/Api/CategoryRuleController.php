<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Services\CategoryRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $categories = Category::query()
            ->where(fn($query) => $query->whereNull('user_id')->orWhere('user_id', $user->id))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn(Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'category_type' => $category->category_type,
                'color' => $category->color,
                'is_system' => $category->is_system,
            ])
            ->values();

        $rules = CategoryRule::query()
            ->where('user_id', $user->id)
            ->with('category:id,name,color')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->map(fn(CategoryRule $rule): array => $this->serializeRule($rule))
            ->values();

        return response()->json([
            'categories' => $categories,
            'rules' => $rules,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'name' => ['nullable', 'string', 'max:120'],
            'pattern' => ['required', 'string', 'max:120'],
            'match_field' => ['nullable', Rule::in(['description', 'counterparty', 'both'])],
            'match_type' => ['nullable', Rule::in(['contains'])],
            'priority' => ['nullable', 'integer', 'between:0,1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category = Category::query()
            ->where('id', $validated['category_id'])
            ->where(fn($query) => $query->whereNull('user_id')->orWhere('user_id', $user->id))
            ->firstOrFail();

        $rule = CategoryRule::query()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'name' => $validated['name'] ?? null,
            'pattern' => trim($validated['pattern']),
            'match_field' => $validated['match_field'] ?? 'both',
            'match_type' => $validated['match_type'] ?? 'contains',
            'priority' => $validated['priority'] ?? 100,
            'is_active' => $validated['is_active'] ?? true,
        ])->load('category:id,name,color');

        return response()->json([
            'rule' => $this->serializeRule($rule),
        ], 201);
    }

    public function update(Request $request, int $ruleId): JsonResponse
    {
        $user = $request->user();

        $rule = CategoryRule::query()
            ->where('id', $ruleId)
            ->where('user_id', $user->id)
            ->with('category:id,name,color')
            ->firstOrFail();

        $validated = $request->validate([
            'category_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'pattern' => ['sometimes', 'string', 'max:120'],
            'match_field' => ['sometimes', Rule::in(['description', 'counterparty', 'both'])],
            'match_type' => ['sometimes', Rule::in(['contains'])],
            'priority' => ['sometimes', 'integer', 'between:0,1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('category_id', $validated)) {
            Category::query()
                ->where('id', $validated['category_id'])
                ->where(fn($query) => $query->whereNull('user_id')->orWhere('user_id', $user->id))
                ->firstOrFail();
        }

        $rule->fill($validated);

        if (array_key_exists('pattern', $validated)) {
            $rule->pattern = trim((string) $validated['pattern']);
        }

        $rule->save();
        $rule->load('category:id,name,color');

        return response()->json([
            'rule' => $this->serializeRule($rule),
        ]);
    }

    public function export(Request $request, CategoryRuleService $categoryRuleService)
    {
        $csv = $categoryRuleService->exportRulesForUser($request->user());

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="category-rules.csv"',
        ]);
    }

    public function import(Request $request, CategoryRuleService $categoryRuleService): JsonResponse
    {
        $validated = $request->validate([
            'csv_content' => ['required', 'string'],
            'mode' => ['nullable', Rule::in(['merge', 'replace'])],
        ]);

        $summary = $categoryRuleService->importRulesFromCsv(
            $request->user(),
            $validated['csv_content'],
            $validated['mode'] ?? 'merge',
        );

        return response()->json([
            'summary' => $summary,
        ]);
    }

    public function importDefaults(Request $request, CategoryRuleService $categoryRuleService): JsonResponse
    {
        $summary = $categoryRuleService->importDefaultRules($request->user());

        return response()->json([
            'summary' => $summary,
        ]);
    }

    public function preview(Request $request, CategoryRuleService $categoryRuleService): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'pattern' => ['required', 'string', 'max:120'],
            'match_field' => ['nullable', Rule::in(['description', 'counterparty', 'both'])],
            'match_type' => ['nullable', Rule::in(['contains'])],
        ]);

        if (isset($validated['category_id'])) {
            Category::query()
                ->where('id', $validated['category_id'])
                ->where(fn($query) => $query->whereNull('user_id')->orWhere('user_id', $request->user()->id))
                ->firstOrFail();
        }

        return response()->json($categoryRuleService->previewRuleForUser(
            $request->user(),
            $validated['category_id'] ?? null,
            $validated['pattern'],
            $validated['match_field'] ?? 'both',
        ));
    }

    public function reset(Request $request, CategoryRuleService $categoryRuleService): JsonResponse
    {
        $deletedRules = $categoryRuleService->resetRulesForUser($request->user());

        return response()->json([
            'deleted_rules' => $deletedRules,
        ]);
    }

    public function destroy(Request $request, int $ruleId): JsonResponse
    {
        CategoryRule::query()
            ->where('id', $ruleId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail()
            ->delete();

        return response()->json([
            'deleted' => true,
        ]);
    }

    public function apply(Request $request, CategoryRuleService $categoryRuleService): JsonResponse
    {
        $summary = $categoryRuleService->applyRulesForUser($request->user());

        return response()->json([
            'summary' => $summary,
        ]);
    }

    private function serializeRule(CategoryRule $rule): array
    {
        return [
            'id' => $rule->id,
            'category_id' => $rule->category_id,
            'category_name' => $rule->category?->name,
            'category_color' => $rule->category?->color,
            'name' => $rule->name,
            'pattern' => $rule->pattern,
            'match_field' => $rule->match_field,
            'match_type' => $rule->match_type,
            'priority' => $rule->priority,
            'is_active' => $rule->is_active,
            'created_at' => $rule->created_at?->toIso8601String(),
            'updated_at' => $rule->updated_at?->toIso8601String(),
        ];
    }
}
