<?php

namespace App\Http\Controllers\Travels\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tecdiary\Laravel\Attachments\Attachment;

/** The supporting documents of a travel or of an agent payment: save, list, view / download and remove, each logged by the caller. */
trait ManagesFiles
{
    protected function fileTypes(): string
    {
        return 'pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt';
    }

    protected function filesOf($model)
    {
        return $model->attachments()->orderBy('id')->get()->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'size' => (int) $a->filesize, 'mime' => $a->filetype, 'at' => optional($a->created_at)->toDateTimeString()])->values();
    }

    /** Saves the files of an upload; returns how many. */
    protected function saveFiles($model, Request $request, string $group): int
    {
        $n = 0;
        foreach ((array) $request->file('attachments', []) as $f) {
            if ($f && $f->isValid()) {
                $model->attach($f, ['title' => mb_substr($f->getClientOriginalName(), 0, 90), 'disk' => 'local', 'group' => $group]);
                $n++;
            }
        }

        return $n;
    }

    protected function fileRules(): array
    {
        return ['attachments' => 'required|array|min:1|max:10', 'attachments.*' => 'file|max:8192|mimes:' . $this->fileTypes()];
    }

    protected function fileOf($model, int $file): Attachment
    {
        $a = $model->attachments()->whereKey($file)->first();
        abort_unless($a, 404);

        return $a;
    }

    /** ?inline=1 shows an image or a PDF in the browser (the preview); anything else is a download. */
    protected function sendFile(Attachment $a, bool $inline)
    {
        if ($inline && in_array(strtolower(pathinfo($a->title, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            $mime = strtolower(pathinfo($a->title, PATHINFO_EXTENSION)) === 'pdf' ? 'application/pdf' : ($a->filetype ?: 'application/octet-stream');

            return response()->file(Storage::disk($a->disk ?: 'local')->path($a->filepath), ['Content-Type' => $mime, 'Content-Disposition' => 'inline; filename="' . addslashes($a->title) . '"', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox"]);
        }

        return Storage::disk($a->disk ?: 'local')->download($a->filepath, $a->title, ['X-Content-Type-Options' => 'nosniff']);
    }
}
