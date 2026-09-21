<?php

namespace Flc\Media\Infrastructure\Scheduling;

use App\Models\MediaItem;
use Carbon\Carbon;

class MediaScheduleService
{
    public function initialNextListenAt(string $frequency): Carbon
    {
        return $this->advance(now(), $frequency);
    }

    public function advance(Carbon $from, string $frequency): Carbon
    {
        return match ($frequency) {
            'weekly' => $from->copy()->addWeek(),
            'monthly' => $from->copy()->addMonth(),
            default => $from->copy()->addDay(),
        };
    }

    public function markListened(MediaItem $item, bool $snoozeOneHour = false): void
    {
        if ($snoozeOneHour) {
            $item->next_listen_at = now()->addHour();
        } else {
            $item->next_listen_at = $this->advance(now(), $item->frequency);
        }

        $item->save();
    }
}
