<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\DashboardCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function store(Request $request, DashboardCacheService $dashboardCacheService): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category_type' => ['nullable', Rule::in(['income', 'expense', 'transfer'])],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $category = Category::query()->create([
            'user_id' => $user->id,
            'name' => trim($validated['name']),
            'slug' => $this->generateUniqueSlug($user->id, $validated['name']),
            'category_type' => $validated['category_type'] ?? 'expense',
            'color' => trim((string) ($validated['color'] ?? '')) ?: null,
            'is_system' => false,
            'sort_order' => ((int) Category::query()->where('user_id', $user->id)->max('sort_order')) + 10,
        ]);

        $dashboardCacheService->invalidateUser($user->id);

        return response()->json([
            'category' => $this->serializeCategory($category),
        ], 201);
    }

    public function update(Request $request, int $categoryId, DashboardCacheService $dashboardCacheService): JsonResponse
    {
        $category = $this->findEditableCategory($request, $categoryId);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'category_type' => ['sometimes', Rule::in(['income', 'expense', 'transfer'])],
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        if (array_key_exists('name', $validated)) {
            $category->name = trim((string) $validated['name']);
            $category->slug = $this->generateUniqueSlug($category->user_id, $category->name, $category->id);
        }

        if (array_key_exists('category_type', $validated)) {
            $category->category_type = $validated['category_type'];
        }

        if (array_key_exists('color', $validated)) {
            $category->color = trim((string) ($validated['color'] ?? '')) ?: null;
        }

        $category->save();
        $dashboardCacheService->invalidateUser($request->user()->id);

        return response()->json([
            'category' => $this->serializeCategory($category),
        ]);
    }

    public function destroy(Request $request, int $categoryId, DashboardCacheService $dashboardCacheService): JsonResponse
    {
        $this->findEditableCategory($request, $categoryId)->delete();
        $dashboardCacheService->invalidateUser($request->user()->id);

        return response()->json([
            'deleted' => true,
        ]);
    }

    private function findEditableCategory(Request $request, int $categoryId): Category
    {
        return Category::query()
            ->where('id', $categoryId)
            ->where('user_id', $request->user()->id)
            ->where('is_system', false)
            ->firstOrFail();
    }

    private function generateUniqueSlug(int $userId, string $name, ?int $ignoreCategoryId = null): string
    {
        $baseSlug = Str::slug(trim($name));
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'kategorie';
        $slug = $baseSlug;
        $suffix = 2;

        while (Category::query()
            ->where('user_id', $userId)
            ->when($ignoreCategoryId !== null, fn($query) => $query->whereKeyNot($ignoreCategoryId))
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function serializeCategory(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'category_type' => $category->category_type,
            'color' => $category->color,
            'is_system' => $category->is_system,
            'sort_order' => $category->sort_order,
        ];
    }
}
