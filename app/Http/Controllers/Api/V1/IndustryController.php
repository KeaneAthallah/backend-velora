<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\IndustryService;
use Illuminate\Http\Request;

class IndustryController extends Controller
{
    public function __construct(protected IndustryService $industries) {}

    public function index(Request $request)
    {
        $this->industries->ensureSeeded();

        $industries = $this->industries->all()->map(function ($industry) {
            return [
                'slug' => $industry->slug,
                'name' => $industry->name,
                'short_name' => $industry->short_name,
                'description' => $industry->description,
                'sort_order' => $industry->sort_order,
                'is_active' => $industry->is_active,
                'examples' => $industry->metadata['examples'] ?? [],
                'benefits' => $industry->metadata['benefits'] ?? [],
                'icon' => $industry->metadata['icon'] ?? null,
                'terminology' => $industry->terminology ?? [],
                'modules' => $industry->capabilities ?? [],
            ];
        })->values()->all();

        return $this->success($industries);
    }
}
