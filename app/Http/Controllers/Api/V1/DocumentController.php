<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'documents.view', $businessId);

        $query = Document::with('uploader:id,name')->where('business_id', $businessId);

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->input('outlet_id'));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $query->where(fn ($builder) => $builder->where('name', 'like', $term)
                ->orWhere('description', 'like', $term));
        }

        $documents = $query->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 25))
            ->through(fn (Document $document) => $this->row($document));

        return $this->success($documents);
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'documents.manage', $businessId);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(Document::TYPES)],
            'outlet_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $file = $request->file('file');
        $path = $file->store("documents/{$businessId}", 'local');

        $document = Document::create([
            'business_id' => $businessId,
            'outlet_id' => $data['outlet_id'] ?? $request->attributes->get('outlet_id'),
            'uploaded_by' => $request->user()->id,
            'name' => $data['name'] ?? $file->getClientOriginalName(),
            'type' => $data['type'] ?? 'general',
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'description' => $data['description'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);

        return $this->success(['document' => $this->row($document)], 'Document uploaded', 201);
    }

    public function download(Request $request, Document $document)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'documents.view', $businessId);
        abort_unless($document->business_id === $businessId, 404);

        abort_unless(Storage::disk($document->disk)->exists($document->path), 404, 'File not found');

        return Storage::disk($document->disk)->download($document->path, $document->name);
    }

    public function destroy(Request $request, Document $document)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'documents.manage', $businessId);
        abort_unless($document->business_id === $businessId, 404);

        Storage::disk($document->disk)->delete($document->path);
        $document->delete();

        return $this->success(null, 'Document deleted');
    }

    private function row(Document $document): array
    {
        return [
            'id' => (string) $document->id,
            'name' => $document->name,
            'type' => $document->type,
            'mime_type' => $document->mime_type,
            'size' => $document->size,
            'description' => $document->description,
            'metadata' => $document->metadata,
            'uploaded_by' => $document->uploader?->name,
            'created_at' => $document->created_at?->toIso8601String(),
            'download_url' => url("/api/v1/documents/{$document->id}/download"),
        ];
    }
}
