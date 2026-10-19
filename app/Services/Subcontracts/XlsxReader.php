<?php

namespace App\Services\Subcontracts;

use RuntimeException;
use ZipArchive;

/**
 * A small, dependency free reader for .xlsx files that returns, for every cell, BOTH the stored (cached) value and the formula text.
 * (The spreadsheet libraries that ship with WIMS return values only; the subcontracts workbook keeps its payment instalments inside formulas such as
 * "=1500000+925000", so the formula text matters.) Nothing is ever executed: formulas are read as text.
 */
class XlsxReader
{
    private ZipArchive $zip;

    private string $path;

    /** @var array<int,string> */
    private array $strings = [];

    /** @var array<int,bool> style index => is a date format */
    private array $dateStyles = [];

    /** @var array<int,array{name:string,state:string,path:string}> */
    private array $sheets = [];

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->zip = new ZipArchive();
        if ($this->zip->open($path) !== true) {
            throw new RuntimeException('The file is not a valid .xlsx workbook.');
        }
        if ($this->zip->locateName('xl/workbook.xml') === false) {
            throw new RuntimeException('The file is not an Excel workbook (xl/workbook.xml is missing).');
        }
        $this->loadStrings();
        $this->loadStyles();
        $this->loadSheets();
    }

    public function __destruct()
    {
        @$this->zip->close();
    }

    /** @return array<int,array{name:string,state:string}> */
    public function sheets(): array
    {
        return array_map(fn ($s) => ['name' => $s['name'], 'state' => $s['state']], $this->sheets);
    }

    private function xml(string $name): ?\SimpleXMLElement
    {
        $st = $this->zip->statName($name);
        if ($st === false) {
            return null;
        }
        // a part that would unpack to more than 80 MB is refused (a workbook of 70 sheets is about 10 MB of XML)
        if (($st['size'] ?? 0) > 80 * 1024 * 1024) {
            throw new \RuntimeException('The workbook is too large to read safely.');
        }
        $raw = $this->zip->getFromName($name);
        if ($raw === false) {
            return null;
        }
        // an Office file never needs a DOCTYPE or entities: refusing them closes XML entity expansion ("billion laughs") and external entities
        if (stripos($raw, '<!DOCTYPE') !== false || stripos($raw, '<!ENTITY') !== false) {
            throw new \RuntimeException('The workbook contains a part that is not allowed (a DOCTYPE or entity declaration).');
        }
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($prev);

        return $x ?: null;
    }

    private function loadStrings(): void
    {
        $x = $this->xml('xl/sharedStrings.xml');
        if (! $x) {
            return;
        }
        foreach ($x->si as $si) {
            $t = '';
            if (isset($si->t)) {
                $t = (string) $si->t;
            } else {
                foreach ($si->r as $r) {
                    $t .= (string) $r->t;
                }
            }
            $this->strings[] = $t;
        }
    }

    private function loadStyles(): void
    {
        $x = $this->xml('xl/styles.xml');
        if (! $x) {
            return;
        }
        $custom = [];
        if (isset($x->numFmts)) {
            foreach ($x->numFmts->numFmt as $nf) {
                $custom[(int) $nf['numFmtId']] = (string) $nf['formatCode'];
            }
        }
        $i = 0;
        foreach ($x->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            $isDate = in_array($id, [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47], true);
            if (! $isDate && isset($custom[$id])) {
                $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $custom[$id]);
                $isDate = (bool) preg_match('/[dmyh]/i', $code) && ! preg_match('/0\.0|#,##|%/', $code);
            }
            $this->dateStyles[$i++] = $isDate;
        }
    }

    private function loadSheets(): void
    {
        $wb = $this->xml('xl/workbook.xml');
        $rels = $this->xml('xl/_rels/workbook.xml.rels');
        $map = [];
        foreach ($rels->Relationship ?? [] as $r) {
            $map[(string) $r['Id']] = (string) $r['Target'];
        }
        foreach ($wb->sheets->sheet ?? [] as $s) {
            $rid = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target = $map[$rid] ?? '';
            $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            $this->sheets[] = ['name' => (string) $s['name'], 'state' => (string) ($s['state'] ?: 'visible'), 'path' => $path];
        }
    }

    /**
     * @return array<int,array<int,array{v:mixed,f:?string,date:bool}>> row number => column number => cell
     */
    public function cells(string $sheetName): array
    {
        $total = 0;

        return $this->readCells($sheetName, null, $total);
    }

    /**
     * Only the first $keep rows with cells, and how many rows with cells the sheet has in all (counted without keeping them), for a preview.
     * @return array{0:array<int,array<int,array{v:mixed,f:?string,date:bool}>>,1:int}
     */
    public function previewCells(string $sheetName, int $keep): array
    {
        $total = 0;
        $rows = $this->readCells($sheetName, $keep, $total);

        return [$rows, $total];
    }

    /** The sheet is read row by row (a stream), so a very large sheet is never held as one tree in memory. */
    private function readCells(string $sheetName, ?int $keep, int &$total): array
    {
        $path = null;
        foreach ($this->sheets as $s) {
            if ($s['name'] === $sheetName) {
                $path = $s['path'];
            }
        }
        if (! $path) {
            throw new RuntimeException('No such sheet: ' . $sheetName);
        }
        $st = $this->zip->statName($path);
        if ($st === false) {
            return [];
        }
        if (($st['size'] ?? 0) > 80 * 1024 * 1024) {
            throw new \RuntimeException('The workbook is too large to read safely.');
        }
        $out = [];
        $shared = []; // shared formulas: si => [master formula text]
        $prev = libxml_use_internal_errors(true);
        $rd = new \XMLReader();
        if (! $rd->open('zip://' . $this->path . '#' . $path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            libxml_use_internal_errors($prev);

            return [];
        }
        $doc = new \DOMDocument();
        try {
            $go = @$rd->read();
            while ($go) {
                if ($rd->nodeType === \XMLReader::DOC_TYPE) {
                    throw new RuntimeException('The workbook contains a part that is not allowed (a DOCTYPE or entity declaration).');
                }
                if ($rd->nodeType !== \XMLReader::ELEMENT || $rd->localName !== 'row') {
                    $go = @$rd->read();

                    continue;
                }
                $node = $rd->expand();
                $row = $node ? simplexml_import_dom($doc->importNode($node, true)) : null;
                $go = @$rd->next(); // past this row and its cells
                $one = $row ? $this->rowCells($row, $shared) : [];
                if ($one === []) {
                    continue;
                }
                $total++;
                if ($keep === null || $total <= $keep) {
                    foreach ($one as $r => $cols) {
                        $out[$r] = array_replace($out[$r] ?? [], $cols);
                    }
                }
            }
        } finally {
            $rd->close();
            libxml_use_internal_errors($prev);
        }

        return $out;
    }

    /** The cells of one <row> element: row number => column number => cell. @return array<int,array<int,array{v:mixed,f:?string,date:bool}>> */
    private function rowCells(\SimpleXMLElement $row, array &$shared): array
    {
        $out = [];
        $r = (int) $row['r'];
        foreach ($row->c as $c) {
            $ref = (string) $c['r'];
            if (! preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                continue;
            }
            $col = self::colNumber($m[1]);
            $t = (string) $c['t'];
            $style = (int) $c['s'];
            $v = isset($c->v) ? (string) $c->v : null;
            $f = null;
            if (isset($c->f)) {
                $f = (string) $c->f;
                if ($f === '' && isset($c->f['si']) && isset($shared[(string) $c->f['si']])) {
                    $f = '(shared) ' . $shared[(string) $c->f['si']];
                } elseif ($f !== '' && isset($c->f['si'])) {
                    $shared[(string) $c->f['si']] = $f;
                }
            }
            $val = null;
            if ($t === 's' && $v !== null) {
                $val = $this->strings[(int) $v] ?? '';
            } elseif ($t === 'inlineStr') {
                $val = isset($c->is->t) ? (string) $c->is->t : '';
            } elseif ($t === 'b') {
                $val = $v === '1';
            } elseif ($t === 'str' || $t === 'e') {
                $val = $v;
            } elseif ($v !== null && $v !== '' && is_numeric($v)) {
                $val = (float) $v;
            }
            if ($val === null && $f === null) {
                continue;
            }
            $out[$r][$col] = ['v' => $val, 'f' => $f !== null && $f !== '' ? $f : null, 'date' => ($this->dateStyles[$style] ?? false) && is_float($val)];
        }

        return $out;
    }

    public static function colNumber(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n;
    }

    /** Excel serial date (1900 system) to Y-m-d. */
    public static function serialToDate(float $serial): ?string
    {
        if ($serial < 1 || $serial > 80000) {
            return null;
        }
        $d = (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int) floor($serial) . ' days');

        return $d->format('Y-m-d');
    }
}
