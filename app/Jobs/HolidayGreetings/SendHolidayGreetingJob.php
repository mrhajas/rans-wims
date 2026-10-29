<?php

namespace App\Jobs\HolidayGreetings;

use App\Models\HolidayDelivery;
use App\Services\HolidayGreetings\GreetingSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/** One job per person and channel. A failure is written on the delivery row (and can be retried from the page); it never stops the others. */
class SendHolidayGreetingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(protected int $deliveryId)
    {
    }

    public function handle(): void
    {
        @set_time_limit(0);
        $d = HolidayDelivery::query()->find($this->deliveryId);
        if ($d) {
            GreetingSender::deliver($d);
        }
    }

    public function failed(\Throwable $e): void
    {
        HolidayDelivery::query()->whereKey($this->deliveryId)->whereNotIn('status', ['Sent'])->update(['status' => 'Failed', 'error' => Str::limit(strip_tags($e->getMessage()), 300)]);
    }
}
