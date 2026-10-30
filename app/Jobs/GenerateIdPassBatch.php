<?php

namespace App\Jobs;

use App\Models\IdPassBatch;
use App\Services\IdPass\BatchRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Makes the PDF of a large ID batch in the background. */
class GenerateIdPassBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 900;

    public $tries = 1;

    public function __construct(public int $batchId)
    {
    }

    public function handle(): void
    {
        if ($b = IdPassBatch::query()->find($this->batchId)) {
            if ($u = \App\Models\User::withoutGlobalScopes()->find($b->user_id)) {
                auth()->setUser($u);
            }
            BatchRunner::run($b);
        }
    }
}
