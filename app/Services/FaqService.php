<?php

namespace App\Services;

use App\Models\FaqItem;
use Illuminate\Support\Collection;

class FaqService
{
    /** @return array<string, mixed> */
    public function format(FaqItem $item): array
    {
        return [
            'id' => $item->id,
            'question' => $item->question,
            'answer' => $item->answer,
            'sort_order' => $item->sort_order,
            'is_published' => $item->is_published,
            'updated_at' => $item->updated_at?->format('d.m.Y H:i'),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function published(): Collection
    {
        return FaqItem::query()
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (FaqItem $item) => $this->format($item));
    }

    /** @return Collection<int, array<string, mixed>> */
    public function allForAdmin(): Collection
    {
        return FaqItem::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (FaqItem $item) => $this->format($item));
    }

    /** @param  array<int, int>  $ids */
    public function reorder(array $ids): void
    {
        foreach ($ids as $index => $id) {
            FaqItem::query()
                ->whereKey($id)
                ->update(['sort_order' => ($index + 1) * 10]);
        }
    }

    public function nextSortOrder(): int
    {
        $max = FaqItem::query()->max('sort_order');

        return ((int) $max) + 10;
    }
}
