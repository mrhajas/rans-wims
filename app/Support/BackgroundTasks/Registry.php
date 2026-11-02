<?php

namespace App\Support\BackgroundTasks;

use App\Support\BackgroundTasks\Kinds\DownloadPrintLogsPdf;
use App\Support\BackgroundTasks\Kinds\ExecutiveReportPreview;
use App\Support\BackgroundTasks\Kinds\LoginLogsPdf;

/** The only jobs that may run in the background. A kind not listed here is refused at the start and again when it would run. */
class Registry
{
    /** Tests replace the list with their own kinds (null puts the real list back). */
    public static ?array $override = null;

    /** @return array<string, TaskKind> */
    public static function all(): array
    {
        if (self::$override !== null) {
            return self::$override;
        }
        $kinds = [new LoginLogsPdf(), new DownloadPrintLogsPdf(), new ExecutiveReportPreview()];

        return collect($kinds)->keyBy(fn (TaskKind $k) => $k->key())->all();
    }

    public static function get(?string $key): ?TaskKind
    {
        return $key ? (self::all()[$key] ?? null) : null;
    }
}
