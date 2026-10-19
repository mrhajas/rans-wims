<?php

namespace App\Http\Controllers\Subcontracts;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Click-to-sort for the paged lists. The order is worked out for ALL the rows that match the filters (not only the page on screen) and then the
 * page is cut out of it. Only the columns a list names can be sorted; anything else is ignored, so the request can never choose a column or direction.
 */
trait SortsLists
{
    /** @return array{0:string,1:string} [column, 'asc'|'desc'], column '' when the usual order applies */
    protected function sortOf(Request $request, array $allowed): array
    {
        $k = $request->query('sort', '');
        $k = is_string($k) ? $k : '';

        return [in_array($k, $allowed, true) ? $k : '', $request->query('dir') === 'desc' ? 'desc' : 'asc'];
    }

    /** one value of one row for ordering: numbers as numbers, text lower-cased, empty = null (always last) */
    protected function sortValue($v)
    {
        if ($v === null || $v === '' || $v === '-') {
            return null;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }

        return mb_strtolower((string) $v);
    }

    /**
     * @param Collection $items every row that matches
     * @param callable $value fn ($item, string $column) => the value to order by
     */
    protected function sortedPage(Collection $items, callable $value, string $col, string $dir, Request $request, int $per): LengthAwarePaginator
    {
        $dirN = $dir === 'desc' ? -1 : 1;
        $sorted = $items->values()->map(fn ($it, $i) => [$it, $i, $this->sortValue($value($it, $col))])->sort(function ($a, $b) use ($dirN) {
            [$x, $y] = [$a[2], $b[2]];
            if ($x === null && $y === null) {
                return $a[1] <=> $b[1];
            }
            if ($x === null) {
                return 1;
            }
            if ($y === null) {
                return -1;
            }
            $c = is_string($x) || is_string($y) ? strnatcasecmp((string) $x, (string) $y) : $x <=> $y;

            return $c === 0 ? $a[1] <=> $b[1] : $c * $dirN;
        })->map(fn ($t) => $t[0])->values();
        $page = max(1, (int) $request->query('page', 1));

        return new LengthAwarePaginator($sorted->forPage($page, $per)->values(), $sorted->count(), $per, $page, ['path' => $request->url(), 'query' => $request->query()]);
    }
}
