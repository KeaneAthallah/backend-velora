<?php

namespace Database\Factories;

use App\Models\RecipeIngredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipeIngredient>
 */
class RecipeIngredientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'ingredient_id' => Ingredient::factory(),
            'quantity_per_unit' => $this->faker->randomFloat(3, 0.01, 2),
            'unit' => 'litre',
        ];
    }

    public function forRecipeAndIngredient(Recipe $recipe, Ingredient $ingredient): static
    {
        return $this->state(fn (array $attributes) => [
            'recipe_id' => $recipe->id,
            'ingredient_id' => $ingredient->id,
        ]);
    }
}
