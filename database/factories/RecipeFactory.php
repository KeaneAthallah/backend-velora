<?php

namespace Database\Factories;

use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recipe>
 */
class RecipeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'product_id' => Product::factory(),
            'name' => $this->faker->sentence(3),
            'yield_quantity' => 1,
        ];
    }

    public function forProduct(Product $product, ?Business $business = null): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $product->id,
            'business_id' => $business?->id ?? $product->business_id,
        ]);
    }
}
