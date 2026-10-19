<?php

namespace App\Support\Simulation;

use ZipArchive;

/**
 * "SIMULATION - NOT OFFICIAL" on everything that leaves Simulation WIMS as a document: print pages and PDFs (banner and faint watermark on every page),
 * Excel (a note row under the data and the file title) and CSV (a note row), and the file name itself. One place, so no report can forget it.
 */
final class ExportMarker
{
    public static function note(): string
    {
        $scenario = '';
        try {
            $scenario = (string) \Illuminate\Support\Facades\DB::table('simulation_scenarios')->where('is_current', 1)->value('name');
        } catch (\Throwable) {
        }

        return 'SIMULATION - NOT OFFICIAL' . ($scenario !== '' ? ' - Scenario: ' . $scenario : '') . ' - Generated ' . now()->format('d M Y H:i');
    }

    public static function filename(string $name): string
    {
        return str_starts_with($name, 'SIMULATION_') ? $name : 'SIMULATION_' . $name;
    }

    public static function html(string $html): string
    {
        if (str_contains($html, 'data-sim-mark') || ! preg_match('/<body[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $note = e(self::note());
        $mark = '<div data-sim-mark style="position:fixed;top:0;left:0;right:0;z-index:99999;text-align:center;padding:3px 8px;font:700 10px/1.3 Arial,Helvetica,sans-serif;letter-spacing:1px;color:#fff;background:#6258ff;">' . $note . '</div>'
            . '<div data-sim-mark style="position:fixed;top:38%;left:0;right:0;z-index:0;text-align:center;font:900 96px/1 Arial,Helvetica,sans-serif;letter-spacing:8px;color:#efeeff;transform:rotate(-28deg);">SIMULATION</div>'
            . '<div data-sim-mark style="position:fixed;bottom:0;left:0;right:0;z-index:99999;text-align:center;padding:2px 8px;font:700 9px/1.3 Arial,Helvetica,sans-serif;color:#6258ff;">' . $note . '</div>'
            . '<div data-sim-mark style="height:16px"></div>';
        $pos = $m[0][1] + strlen($m[0][0]);

        return substr($html, 0, $pos) . $mark . substr($html, $pos);
    }

    public static function csv(string $csv): string
    {
        $eol = str_contains($csv, "\r\n") ? "\r\n" : "\n";

        return rtrim($csv, "\r\n") . $eol . $eol . '"' . str_replace('"', '""', self::note()) . '"' . $eol;
    }

    /** Excel: one more line under the data in every sheet, and the file's own title. The sheet's cells and styles are not touched. */
    public static function xlsx(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sim');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);

            return $bytes;
        }
        $note = htmlspecialchars(self::note(), ENT_XML1);
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                    $xml = $zip->getFromIndex($i);
                    $last = 0;
                    if (preg_match_all('/<row[^>]*\sr="(\d+)"/', $xml, $r)) {
                        $last = (int) max($r[1]);
                    }
                    $n = $last + 2;
                    $row = '<row r="' . $n . '"><c r="A' . $n . '" t="inlineStr"><is><t>' . $note . '</t></is></c></row>';
                    if (str_contains($xml, '</sheetData>')) {
                        $xml = str_replace('</sheetData>', $row . '</sheetData>', $xml);
                        $xml = preg_replace('/(<dimension ref="[A-Z]+\d+:[A-Z]+)\d+"/', '${1}' . $n . '"', $xml, 1);
                        $zip->addFromString($name, $xml);
                    }
                } elseif ($name === 'docProps/core.xml') {
                    $core = $zip->getFromIndex($i);
                    $core = preg_match('#<dc:title>.*?</dc:title>#s', $core) ? preg_replace('#<dc:title>.*?</dc:title>#s', '<dc:title>SIMULATION - NOT OFFICIAL</dc:title>', $core) : str_replace('</cp:coreProperties>', '<dc:title>SIMULATION - NOT OFFICIAL</dc:title></cp:coreProperties>', $core);
                    $zip->addFromString($name, $core);
                }
            }
            $zip->close();
            $out = file_get_contents($tmp);
        } catch (\Throwable) {
            $out = $bytes;
        }
        @unlink($tmp);

        return $out !== false && $out !== '' ? $out : $bytes;
    }
}
