<?php

namespace App\Services\IdPass;

use App\Models\IdPassBatch;
use App\Models\IdPassCard;
use Illuminate\Support\Facades\Storage;

/**
 * Makes the PDF of a batch. The cards are cut into parts of 60 so a large batch never needs more memory or time than a small one; each part is its own file (a batch of 200 cards is 4 files).
 * Only active cards are made. Every card gets a line in the print history, pointing at the batch.
 */
class BatchRunner
{
    public const PART = 60;

    public const MAX = 2000;

    /** Cards of a batch, in a steady order: kind, category, number. */
    public static function cardsOf(IdPassBatch $b)
    {
        $ids = (array) $b->card_ids;
        $order = ['staff' => 1, 'compound' => 2, 'visitor' => 3, 'gate' => 4];

        return IdPassCard::query()->with(['category', 'staff'])->whereIn('id', $ids ?: [0])->where('status', IdPassCard::ACTIVE)->get()
            ->sortBy(fn ($c) => sprintf('%d-%08d-%s', $order[$c->kind] ?? 9, $c->category_id, $c->number))->values();
    }

    public static function dir(): string
    {
        return CardAssets::dir('batches');
    }

    public static function file(IdPassBatch $b, int $part): string
    {
        return self::dir() . '/' . $b->reference . '-p' . $part . '.pdf';
    }

    public static function run(IdPassBatch $b): void
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);
        $b->forceFill(['status' => 'Running', 'progress' => 1, 'error' => null])->save();
        try {
            $cards = self::cardsOf($b);
            if ($cards->isEmpty()) {
                throw new \RuntimeException(__('No active ID is left in this batch.'));
            }
            $landscape = ($b->filters['orientation'] ?? 'landscape') === 'landscape';
            $parts = $cards->chunk(self::PART);
            foreach ($parts as $i => $chunk) {
                $datas = $chunk->map(fn ($c) => CardRenderer::data($c))->all();
                $bytes = $b->layout === 'card' ? CardPdf::cards($datas) : CardPdf::a4($datas, $b->layout, $landscape);
                file_put_contents(self::file($b, $i + 1), $bytes);
                $b->forceFill(['progress' => (int) min(95, round(($i + 1) / $parts->count() * 95))])->save();
            }
            $action = $b->action === 'print' ? 'batch_print' : 'batch_pdf';
            $why = trim((string) (($b->filters['reason'] ?? '')));
            \DB::transaction(function () use ($cards, $action, $b, $why) {
                foreach ($cards as $c) {
                    $again = (int) $c->print_count > 0 && $why !== '';
                    \App\Services\IdPass\PrintLogger::record($c, $action, $b->layout, 1, $b, 'Generated', $again ? $b->reference . ' - ' . __('Printed again: :r', ['r' => $why]) : $b->reference);
                    if ($again) {
                        \App\Models\IdPassEvent::record($c, 'reprinted', null, null, ['reason' => $why, 'batch' => $b->reference]);
                    }
                }
            });
            $filters = (array) $b->filters;
            $filters['parts'] = $parts->count();
            $filters['made'] = $cards->count();
            $b->forceFill(['status' => 'Done', 'progress' => 100, 'filters' => $filters, 'cards' => $cards->count(), 'sheets' => $b->layout === 'card' ? $cards->count() * 2 : CardPdf::sheets($b->layout, $landscape, $cards->count()), 'completed_at' => now(), 'file_path' => 'idpass/batches/' . $b->reference])->save();
            PrintLogger::audit('RANS ID batch generated', ['batch' => $b->reference, 'action' => $b->action, 'layout' => $b->layout, 'cards' => $cards->count(), 'parts' => $parts->count()]);
        } catch (\Throwable $e) {
            \Log::error('ID batch failed', ['batch' => $b->reference, 'error' => $e->getMessage()]);
            $b->forceFill(['status' => 'Failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
        }
    }
}
