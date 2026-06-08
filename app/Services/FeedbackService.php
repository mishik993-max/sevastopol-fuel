<?php

namespace App\Services;

use App\Enums\FeedbackStatus;
use App\Models\FeedbackMessage;
use Illuminate\Support\Collection;

class FeedbackService
{
    /** @return array<string, mixed> */
    public function format(FeedbackMessage $message): array
    {
        return [
            'id' => $message->id,
            'type' => $message->type->value,
            'type_label' => $message->type->label(),
            'message' => $message->message,
            'contact' => $message->contact,
            'status' => $message->status->value,
            'status_label' => $message->status->label(),
            'admin_note' => $message->admin_note,
            'created_at' => $message->created_at?->format('d.m.Y H:i'),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function allForAdmin(): Collection
    {
        return FeedbackMessage::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (FeedbackMessage $m) => $this->format($m));
    }

    public function newCount(): int
    {
        return FeedbackMessage::query()
            ->where('status', FeedbackStatus::New)
            ->count();
    }
}
