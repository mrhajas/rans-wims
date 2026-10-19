<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('notifications')
            ->where('data', 'like', '%notice_board%')
            ->get(['id', 'data']);

        foreach ($rows as $row) {
            $data = json_decode($row->data, true);

            if (! is_array($data) || isset($data['notice_id'])) {
                continue;
            }

            $url = $data['url'] ?? '';
            if (! preg_match('/\/notices\/(\d+)/', $url, $matches)) {
                continue;
            }

            $data['notice_id'] = (int) $matches[1];

            DB::table('notifications')->where('id', $row->id)->update([
                'data' => json_encode($data),
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive backfill; nothing to revert.
    }
};
