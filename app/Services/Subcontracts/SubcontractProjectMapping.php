<?php

namespace App\Services\Subcontracts;

use App\Models\SubcontractProjectMapping as Map;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Project Name Mapping. The work groups of the imported accounts carry project / location names typed in the owner's Excel ("ORANGE K/SAMAKI").
 * The owner matches each distinct name to a Project(s) / Warehouse(s) (a warehouse) and then CONFIRMS. Until the confirmation a match is only a draft and no group changes.
 * After it every group of that name points to the project (subcontract_work_groups.warehouse_id), and an agreement whose groups all point to ONE project gets that project.
 *
 * A mapping row is in one of three states: none (nothing chosen), pending (chosen but not confirmed, or changed / cleared after it was confirmed), confirmed.
 */
class SubcontractProjectMapping
{
    public const MAX_UPLOAD_BYTES = 4 * 1024 * 1024;

    public const SHEET = 'Match projects';

    /** An upper-cased, whitespace collapsed name: "orange  k/samaki " and "ORANGE K/SAMAKI" are the same name. */
    public static function key(string $name): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /** SQL for the name to show for a work group: the mapped project's name, else the name typed in the Excel. Join warehouses as $wh on $group.warehouse_id (a LEFT JOIN, no deleted_at test). */
    public static function displayNameSql(string $group = 'g', string $wh = 'w'): string
    {
        return "COALESCE({$wh}.name, {$group}.name)";
    }

    /** The name to show for a group object / array that carries the group's own name and (optionally) its project's name. */
    public static function displayName(?string $groupName, ?string $projectName): string
    {
        return ($projectName !== null && trim($projectName) !== '') ? $projectName : (string) $groupName;
    }

    // -----------------------------------------------------------------------------------------------------------------

    /** @return array<string,array{name:string,n:int}> key => most frequent spelling */
    private static function spellings(): array
    {
        $by = [];
        foreach (DB::table('subcontract_work_groups as g')->join('subcontract_agreements as a', 'a.id', '=', 'g.agreement_id')->whereNull('g.deleted_at')->whereNull('a.deleted_at')->orderBy('g.id')->get(['g.name']) as $g) {
            $k = self::key((string) $g->name);
            if ($k === '') {
                continue;
            }
            $s = trim((string) preg_replace('/\s+/u', ' ', (string) $g->name));
            $by[$k][$s] = ($by[$k][$s] ?? 0) + 1;
        }
        $out = [];
        foreach ($by as $k => $spell) {
            arsort($spell); // the most frequent spelling; on a tie the one met first
            $out[$k] = (string) array_key_first($spell);
        }

        return $out;
    }

    private static function stateOf(?object $m): string
    {
        if (! $m) {
            return 'none';
        }
        if ($m->warehouse_id) {
            return $m->confirmed_at ? 'confirmed' : 'pending';
        }

        return (! $m->confirmed_at && $m->confirmed_by_user_id) ? 'pending' : 'none'; // cleared after it was confirmed: the groups still have to be reset
    }

    /**
     * Every distinct work group name with where it is used and how it is mapped.
     * Counted: live groups of live agreements (archived agreements included, flagged); items that count (active, and approved when added by a variation).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function names(): array
    {
        $groups = DB::table('subcontract_work_groups as g')->join('subcontract_agreements as a', 'a.id', '=', 'g.agreement_id')->whereNull('g.deleted_at')->whereNull('a.deleted_at')
            ->orderBy('a.id')->orderBy('g.sort_order')->orderBy('g.id')->get(['g.id', 'g.name', 'g.warehouse_id', 'a.id as aid', 'a.reference', 'a.title', 'a.status', 'a.currency']);
        $fig = [];
        foreach (DB::table('subcontract_work_items as i')->leftJoin('subcontract_variations as v', 'v.id', '=', 'i.variation_id')->whereNull('i.deleted_at')->where('i.status', 'active')->whereNotNull('i.group_id')
            ->where(fn ($w) => $w->whereNull('i.variation_id')->orWhere('v.status', 'Approved'))->groupBy('i.group_id')->selectRaw('i.group_id gid, COUNT(*) n, SUM(i.amount) s')->get() as $r) {
            $fig[$r->gid] = $r;
        }
        $maps = Map::query()->get()->keyBy('name_key');
        $spell = self::spellings();
        $out = [];
        foreach ($groups as $g) {
            $k = self::key((string) $g->name);
            if ($k === '') {
                continue;
            }
            $o = &$out[$k];
            $o ??= ['key' => $k, 'excel_name' => $spell[$k] ?? trim((string) $g->name), 'groups' => 0, 'agreements' => 0, 'archived' => 0, 'agreement_list' => [], 'items' => 0, 'totals' => [], '_seen' => []];
            $o['groups']++;
            if (! isset($o['_seen'][$g->aid])) {
                $o['_seen'][$g->aid] = true;
                $o['agreements']++;
                $g->status === 'Archived' && $o['archived']++;
                if (count($o['agreement_list']) < 12) {
                    $o['agreement_list'][] = ['id' => (int) $g->aid, 'reference' => $g->reference, 'title' => $g->title, 'status' => $g->status];
                }
            }
            if (isset($fig[$g->id])) {
                $o['items'] += (int) $fig[$g->id]->n;
                $o['totals'][$g->currency] = bcadd($o['totals'][$g->currency] ?? '0', self::dec($fig[$g->id]->s), 2);
            }
            unset($o);
        }
        foreach ($out as $k => &$o) {
            unset($o['_seen']);
            ksort($o['totals']);
            $m = $maps[$k] ?? null;
            $o['warehouse_id'] = $m && $m->warehouse_id ? (int) $m->warehouse_id : null;
            $o['state'] = self::stateOf($m);
            $o['confirmed_at'] = $m && $m->confirmed_at ? $m->confirmed_at->toDateTimeString() : null;
        }
        unset($o);
        $rows = array_values($out);
        usort($rows, fn ($a, $b) => strnatcasecmp($a['excel_name'], $b['excel_name']));

        return $rows;
    }

    /** A SQL SUM as a clean decimal string with 2 places. */
    private static function dec($v): string
    {
        return is_numeric((string) $v) ? bcadd((string) $v, '0', 2) : '0.00';
    }

    /** Which of these warehouse ids exist (not deleted). */
    private static function existing(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids ? DB::table('warehouses')->whereIn('id', $ids)->whereNull('deleted_at')->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
    }

    /**
     * Save a DRAFT mapping: nothing about any group changes until confirm(). A null warehouse clears the choice.
     *
     * @throws InvalidArgumentException when the name is not a work group name or the project does not exist
     */
    public static function set(string $key, ?int $warehouseId, ?int $userId): void
    {
        self::saveMany([['key' => $key, 'warehouse_id' => $warehouseId]], $userId);
    }

    /**
     * Save several drafts in one transaction.
     *
     * @param  array<int,array{key:string,warehouse_id:?int}>  $mappings
     * @return int how many rows were changed
     */
    public static function saveMany(array $mappings, ?int $userId): int
    {
        $spell = self::spellings();
        $wanted = [];
        foreach ($mappings as $m) {
            $w = $m['warehouse_id'] ?? null;
            $wanted[] = $w === null || $w === '' ? null : (int) $w;
        }
        $ok = array_flip(self::existing($wanted));
        $changed = 0;
        DB::transaction(function () use ($mappings, $spell, $ok, $userId, &$changed) {
            foreach ($mappings as $m) {
                $k = self::key((string) ($m['key'] ?? ''));
                $w = ($m['warehouse_id'] ?? null) === null || ($m['warehouse_id'] ?? '') === '' ? null : (int) $m['warehouse_id'];
                $row = Map::query()->where('name_key', $k)->lockForUpdate()->first();
                if (! isset($spell[$k]) && ! $row) {
                    throw new InvalidArgumentException(__('":n" is not a project name used in any work group.', ['n' => mb_substr((string) ($m['key'] ?? ''), 0, 60)]));
                }
                if ($w !== null && ! isset($ok[$w])) {
                    throw new InvalidArgumentException(__('The project chosen for ":n" does not exist.', ['n' => $spell[$k] ?? $k]));
                }
                if (! $row) {
                    if ($w === null) {
                        continue; // nothing chosen, nothing to remember
                    }
                    Map::query()->create(['name_key' => $k, 'excel_name' => mb_substr($spell[$k] ?? $k, 0, 190), 'warehouse_id' => $w, 'account_id' => 1]);
                    $changed++;
                    continue;
                }
                if ((int) $row->warehouse_id === (int) $w) {
                    continue; // the same choice: a confirmed mapping stays confirmed
                }
                $row->forceFill(['warehouse_id' => $w, 'confirmed_at' => null])->save(); // confirmed_by stays: it tells that the groups still hold the old project
                if ($w === null && ! $row->confirmed_by_user_id) {
                    $row->delete(); // never confirmed and now empty
                }
                $changed++;
            }
        });
        if ($changed) {
            log_activity('Subcontract project mapping draft saved', ['rows' => $changed], null, 'Subcontracts');
        }

        return $changed;
    }

    /**
     * Apply every waiting mapping in ONE transaction: the groups of each name get the project, the mapping is marked confirmed, a cleared mapping resets its groups.
     * Then every agreement whose groups all point to the same project gets that project (only when it had none, or got it from a mapping before).
     *
     * @return array{groups:int,mappings:int,cleared:int,agreements_with_project:int,agreements_several:int,agreements_set:int}
     */
    public static function confirm(int $userId): array
    {
        $res = ['groups' => 0, 'mappings' => 0, 'cleared' => 0, 'agreements_with_project' => 0, 'agreements_several' => 0, 'agreements_set' => 0];
        DB::transaction(function () use ($userId, &$res) {
            $rows = Map::query()->lockForUpdate()->get();
            $ids = [];
            foreach (DB::table('subcontract_work_groups as g')->join('subcontract_agreements as a', 'a.id', '=', 'g.agreement_id')->whereNull('g.deleted_at')->whereNull('a.deleted_at')->get(['g.id', 'g.name', 'g.warehouse_id']) as $g) {
                $ids[self::key((string) $g->name)][] = $g;
            }
            $live = self::existing($rows->pluck('warehouse_id')->all());
            foreach ($rows as $m) {
                $state = self::stateOf($m);
                $want = $m->warehouse_id && in_array((int) $m->warehouse_id, $live, true) ? (int) $m->warehouse_id : null;
                if ($m->warehouse_id && $want === null) {
                    continue; // its project was deleted meanwhile: left as a draft for the person to fix
                }
                if ($state === 'none') {
                    continue;
                }
                // a confirmed mapping is applied again too, so groups made by a later import follow it
                $todo = array_values(array_filter($ids[$m->name_key] ?? [], fn ($g) => ($g->warehouse_id === null ? null : (int) $g->warehouse_id) !== $want));
                if ($todo) {
                    DB::table('subcontract_work_groups')->whereIn('id', array_map(fn ($g) => $g->id, $todo))->update(['warehouse_id' => $want, 'updated_at' => now()]);
                    $res['groups'] += count($todo);
                }
                if ($state === 'pending') {
                    $m->forceFill(['confirmed_at' => now(), 'confirmed_by_user_id' => $userId])->save();
                    $res['mappings']++;
                    $want === null && $res['cleared']++;
                }
            }
            self::deriveAgreementProjects($res);
        });
        log_activity('Subcontract project mapping confirmed', $res, null, 'Subcontracts');

        return $res;
    }

    /** The agreement's own project follows its groups: all groups on ONE project give the agreement that project (when it had none or got it this way before); otherwise a derived project is taken back. */
    private static function deriveAgreementProjects(array &$res): void
    {
        $byAg = [];
        foreach (DB::table('subcontract_work_groups as g')->join('subcontract_agreements as a', 'a.id', '=', 'g.agreement_id')->whereNull('g.deleted_at')->whereNull('a.deleted_at')->orderBy('g.id')->get(['g.agreement_id', 'g.warehouse_id']) as $g) {
            $byAg[$g->agreement_id][] = $g->warehouse_id;
        }
        $ags = DB::table('subcontract_agreements')->whereNull('deleted_at')->whereIn('id', array_keys($byAg) ?: [0])->get(['id', 'warehouse_id', 'extra_attributes'])->keyBy('id');
        foreach ($byAg as $aid => $whs) {
            $a = $ags[$aid] ?? null;
            if (! $a) {
                continue;
            }
            $extra = $a->extra_attributes ? (array) json_decode((string) $a->extra_attributes, true) : [];
            $derived = ! empty($extra['project_from_mapping']);
            $distinct = array_values(array_unique($whs, SORT_REGULAR));
            $allMapped = ! in_array(null, $whs, true);
            $same = $allMapped && count($distinct) === 1 ? (int) $distinct[0] : null;
            if ($same !== null) {
                if ($a->warehouse_id === null || ($derived && (int) $a->warehouse_id !== $same)) {
                    $extra['project_from_mapping'] = true;
                    DB::table('subcontract_agreements')->where('id', $aid)->update(['warehouse_id' => $same, 'extra_attributes' => json_encode($extra), 'updated_at' => now()]);
                    $res['agreements_set']++;
                }
            } elseif ($derived) {
                unset($extra['project_from_mapping']);
                DB::table('subcontract_agreements')->where('id', $aid)->update(['warehouse_id' => null, 'extra_attributes' => $extra ? json_encode($extra) : null, 'updated_at' => now()]);
                $res['agreements_set']++;
            }
            if ($allMapped && count($distinct) > 1) {
                $res['agreements_several']++;
            }
        }
        $res['agreements_with_project'] = DB::table('subcontract_agreements')->whereNull('deleted_at')->whereIn('id', array_keys($byAg) ?: [0])->whereNotNull('warehouse_id')->count();
    }

    // -----------------------------------------------------------------------------------------------------------------
    // The workbook the owner matched: first sheet "Match projects", header on row 3, Excel name in column B, Project(s) / Warehouse(s) in column I (or under a header that says so)

    /**
     * Read a matched workbook (.xlsx or .csv) and say what each row would become. Nothing is saved.
     *
     * @return array<int,array{row:int,excel_name:string,key:string,typed:string,warehouse_id:?int,warehouse_name:?string,status:string}> status: matched | unmatched | blank | unknown_name
     *
     * @throws InvalidArgumentException a message fit to show the person
     */
    public static function importWorkbook(string $path, ?string $fileName = null): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException(__('The file could not be read.'));
        }
        $size = (int) filesize($path);
        if ($size === 0) {
            throw new InvalidArgumentException(__('The file is empty.'));
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException(__('The file is larger than 4 MB.'));
        }
        $head = (string) file_get_contents($path, false, null, 0, 4);
        $ext = strtolower(pathinfo((string) ($fileName ?: $path), PATHINFO_EXTENSION));
        if (str_starts_with($head, "PK\x03\x04")) {
            if ($ext !== '' && $ext !== 'xlsx') {
                throw new InvalidArgumentException(__('Upload an Excel .xlsx or a .csv file.'));
            }
            $cells = self::cellsOfXlsx($path);
        } elseif ($ext === 'csv') {
            $cells = self::cellsOfCsv($path);
        } else {
            throw new InvalidArgumentException(__('Upload an Excel .xlsx or a .csv file.'));
        }

        // the header: a row (within the first 10) that names the Project(s) / Warehouse(s) column; else row 3 with columns B and I
        $headerRow = 3;
        $colName = 2;
        $colWims = 9;
        for ($r = 1; $r <= 10; $r++) {
            $found = [];
            foreach ($cells[$r] ?? [] as $c => $v) {
                $t = mb_strtolower(self::norm($v));
                if (preg_match('/^(wims project|wims project name|project\(s\) ?\/ ?warehouse\(s\)|mapped project|project in wims)/u', $t)) {
                    $found['wims'] = $c;
                } elseif (preg_match('/^(excel project name|excel name|excel project)/u', $t)) {
                    $found['name'] = $c;
                }
            }
            if (isset($found['wims'])) {
                $headerRow = $r;
                $colWims = $found['wims'];
                $colName = $found['name'] ?? 2;
                break;
            }
        }

        $whs = DB::table('warehouses')->whereNull('deleted_at')->orderByDesc('active')->orderBy('id')->get(['id', 'name', 'code']);
        $byName = [];
        foreach ($whs as $w) {
            $byName[self::key((string) $w->name)] ??= $w;
        }
        foreach ($whs as $w) {
            if ($w->code !== null && trim((string) $w->code) !== '') {
                $byName[self::key((string) $w->code)] ??= $w;
            }
        }
        $known = array_flip(array_keys(self::spellings()));

        $out = [];
        $rowsSorted = array_keys($cells);
        sort($rowsSorted);
        foreach ($rowsSorted as $r) {
            if ($r <= $headerRow) {
                continue;
            }
            $name = self::norm($cells[$r][$colName] ?? null);
            if ($name === '') {
                continue;
            }
            $typed = self::norm($cells[$r][$colWims] ?? null);
            $key = self::key($name);
            $w = $typed !== '' ? ($byName[self::key($typed)] ?? null) : null;
            $status = ! isset($known[$key]) ? 'unknown_name' : ($typed === '' ? 'blank' : ($w ? 'matched' : 'unmatched'));
            $out[] = ['row' => $r, 'excel_name' => mb_substr($name, 0, 190), 'key' => $key, 'typed' => mb_substr($typed, 0, 190), 'warehouse_id' => $status === 'matched' ? (int) $w->id : null, 'warehouse_name' => $status === 'matched' ? $w->name : null, 'status' => $status];
        }

        return $out;
    }

    private static function norm($v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_array($v)) {
            $v = $v['v'] ?? '';
        }
        if (is_float($v)) {
            $v = $v == floor($v) ? (string) (int) $v : (string) $v;
        }

        // the template writes a name that starts with = + - or @ with a leading quote (so Excel never reads it as a formula): the quote is taken off again here
        return trim((string) preg_replace("/^'(?=[=+\-@])/", '', trim((string) preg_replace('/\s+/u', ' ', (string) $v))));
    }

    /** @return array<int,array<int,mixed>> row => col => value */
    private static function cellsOfXlsx(string $path): array
    {
        try {
            $x = new XlsxReader($path);
            $sheets = $x->sheets();
            $name = null;
            foreach ($sheets as $s) {
                if (mb_strtolower(trim($s['name'])) === mb_strtolower(self::SHEET)) {
                    $name = $s['name'];
                    break;
                }
            }
            $name ??= collect($sheets)->first(fn ($s) => $s['state'] === 'visible')['name'] ?? ($sheets[0]['name'] ?? null);
            if ($name === null) {
                throw new InvalidArgumentException(__('The workbook has no sheet.'));
            }
            $out = [];
            foreach ($x->cells($name) as $r => $cols) {
                foreach ($cols as $c => $cell) {
                    $out[$r][$c] = $cell['v'];
                }
            }

            return $out;
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(__('The file is not a readable Excel workbook.'));
        }
    }

    /** @return array<int,array<int,mixed>> */
    private static function cellsOfCsv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $first = strtok($raw, "\n") ?: '';
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $out = [];
        $r = 0;
        while (($line = fgetcsv($fh, 0, $delim)) !== false && $r < 20000) {
            $r++;
            foreach ($line as $i => $v) {
                if ($v !== null && $v !== '') {
                    $out[$r][$i + 1] = $v;
                }
            }
        }
        fclose($fh);

        return $out;
    }
}
