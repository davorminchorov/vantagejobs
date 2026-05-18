<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tags;

use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class AutocompleteTagsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $needle = is_string($validated['q'] ?? null) ? trim((string) $validated['q']) : '';
        $limit = (int) ($validated['limit'] ?? 10);

        $query = Tag::query()
            ->select('tags.id', 'tags.slug', 'tags.name')
            ->selectSub(
                DB::table('taggables')->selectRaw('count(*)')->whereColumn('taggables.tag_id', 'tags.id'),
                'usage_count',
            )
            ->orderByDesc('usage_count')
            ->orderBy('tags.name');

        if ($needle !== '') {
            $pattern = '%'.$needle.'%';
            $query->where(function ($q) use ($pattern): void {
                $q->where('tags.slug', 'ilike', $pattern)
                    ->orWhere('tags.name', 'ilike', $pattern);
            });
        }

        $tags = $query->limit($limit)->get();

        return new JsonResponse([
            'tags' => $tags->map(fn (Tag $tag) => [
                'id' => $tag->id,
                'slug' => $tag->slug,
                'name' => $tag->name,
                'usage_count' => (int) $tag->getAttribute('usage_count'),
            ])->values(),
        ]);
    }
}
