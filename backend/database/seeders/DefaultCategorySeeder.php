<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DefaultCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            ['name' => 'Gehalt', 'category_type' => 'income', 'color' => '#16a34a'],
            ['name' => 'Nebeneinkünfte', 'category_type' => 'income', 'color' => '#22c55e'],
            ['name' => 'Wohnen', 'category_type' => 'expense', 'color' => '#2563eb'],
            ['name' => 'Lebensmittel', 'category_type' => 'expense', 'color' => '#059669'],
            ['name' => 'Drogerie', 'category_type' => 'expense', 'color' => '#10b981'],
            ['name' => 'Mobilität', 'category_type' => 'expense', 'color' => '#f59e0b'],
            ['name' => 'Gesundheit', 'category_type' => 'expense', 'color' => '#ef4444'],
            ['name' => 'Freizeit', 'category_type' => 'expense', 'color' => '#8b5cf6'],
            ['name' => 'Familie / Kinder', 'category_type' => 'expense', 'color' => '#ec4899'],
            ['name' => 'Versicherungen', 'category_type' => 'expense', 'color' => '#0f766e'],
            ['name' => 'Reisen', 'category_type' => 'expense', 'color' => '#0891b2'],
            ['name' => 'Abos', 'category_type' => 'expense', 'color' => '#7c3aed'],
            ['name' => 'Software / SaaS', 'category_type' => 'expense', 'color' => '#4f46e5'],
            ['name' => 'Fachliteratur', 'category_type' => 'expense', 'color' => '#9333ea'],
            ['name' => 'Büromaterial', 'category_type' => 'expense', 'color' => '#a16207'],
            ['name' => 'Weiterbildung', 'category_type' => 'expense', 'color' => '#dc2626'],
            ['name' => 'Telefon / Internet beruflich', 'category_type' => 'expense', 'color' => '#0284c7'],
            ['name' => 'Steuern / Gebühren beruflich', 'category_type' => 'expense', 'color' => '#475569'],
            ['name' => 'Sonstiges', 'category_type' => 'expense', 'color' => '#6b7280'],
        ];

        foreach ($categories as $index => $category) {
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
