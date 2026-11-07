<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which sale lines a refund returns.
 *
 * A refund is recorded as an amount, which cannot be mapped back to
 * quantities. `refund_items` carries the returned quantity per sale line so
 * the ingredient reversal (`RecipeService::reverseSaleItems`) can put back
 * exactly what those lines consumed, on a partial refund as well as a full one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['sale_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_items');
    }
};
