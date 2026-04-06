<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DefaultCategorySeeder extends Seeder
{
    /**
     * @return array<int, array{name: string, category_type: string, color: string}>
     */
    public static function definitions(): array
    {
        return [
            ['name' => 'Gehalt', 'category_type' => 'income', 'color' => '#16a34a'],
            ['name' => 'Nebeneinkünfte', 'category_type' => 'income', 'color' => '#22c55e'],
            ['name' => 'Transfer', 'category_type' => 'transfer', 'color' => '#64748b'],
            ['name' => 'Wohnen', 'category_type' => 'expense', 'color' => '#2563eb'],
            ['name' => 'Lebensmittel', 'category_type' => 'expense', 'color' => '#059669'],
            ['name' => 'Drogerie', 'category_type' => 'expense', 'color' => '#10b981'],
            ['name' => 'Haushalt und Kleidung', 'category_type' => 'expense', 'color' => '#84cc16'],
            ['name' => 'Mobilität', 'category_type' => 'expense', 'color' => '#f59e0b'],
            ['name' => 'Gesundheit', 'category_type' => 'expense', 'color' => '#ef4444'],
            ['name' => 'Freizeit', 'category_type' => 'expense', 'color' => '#8b5cf6'],
            ['name' => 'Onlinekauf', 'category_type' => 'expense', 'color' => '#6366f1'],
            ['name' => 'Familie / Kinder', 'category_type' => 'expense', 'color' => '#ec4899'],
            ['name' => 'Versicherungen', 'category_type' => 'expense', 'color' => '#0f766e'],
            ['name' => 'Reisen', 'category_type' => 'expense', 'color' => '#0891b2'],
            ['name' => 'Abos', 'category_type' => 'expense', 'color' => '#7c3aed'],
            ['name' => 'Software / SaaS', 'category_type' => 'expense', 'color' => '#4f46e5'],
            ['name' => 'Telefon / Internet beruflich', 'category_type' => 'expense', 'color' => '#0284c7'],
            ['name' => 'Sonstiges', 'category_type' => 'expense', 'color' => '#6b7280'],
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::definitions() as $index => $category) {
            Category::query()->updateOrCreate(
                [
                    'user_id' => null,
                    'slug' => Str::slug($category['name']),
                ],
                [
                    'name' => $category['name'],
                    'category_type' => $category['category_type'],
                    'color' => $category['color'],
                    'is_system' => true,
                    'sort_order' => $index + 1,
                ],
            );
        }
    }
}
