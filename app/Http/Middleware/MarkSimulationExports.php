<?php

namespace App\Http\Middleware;

use App\Support\Simulation\Environment;
use App\Support\Simulation\ExportMarker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** In Simulation WIMS every document that leaves (print page, PDF, Excel, CSV) says it is a simulation. See ExportMarker. */
class MarkSimulationExports
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (! Environment::isSimulation()) {
            return $response;
        }
        $type = strtolower((string) $response->headers->get('Content-Type'));
        $disp = (string) $response->headers->get('Content-Disposition');
        $isFile = str_contains($disp, 'attachment') || str_contains($disp, 'inline') && str_contains($disp, 'filename');
        $kind = null;
        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disp, $m)) {
            $name = rawurldecode($m[1]);
            $kind = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) { 'xlsx' => 'xlsx', 'csv' => 'csv', default => null };
            $response->headers->set('Content-Disposition', \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition(str_contains($disp, 'inline') ? 'inline' : 'attachment', ExportMarker::filename($name), preg_replace('/[^\x20-\x7e]/', '_', ExportMarker::filename($name))));
        }
        if ($kind && $response instanceof StreamedResponse) {
            $original = (new \ReflectionProperty(StreamedResponse::class, 'callback'))->getValue($response);
            $response->setCallback(function () use ($original, $kind) {
                ob_start();
                $original();
                $bytes = (string) ob_get_clean();
                echo $kind === 'xlsx' ? ExportMarker::xlsx($bytes) : ExportMarker::csv($bytes);
            });
        } elseif ($kind && $response instanceof BinaryFileResponse) {
            $bytes = (string) @file_get_contents($response->getFile()->getPathname());
            if ($bytes !== '') {
                $tmp = tempnam(sys_get_temp_dir(), 'sim');
                file_put_contents($tmp, $kind === 'xlsx' ? ExportMarker::xlsx($bytes) : ExportMarker::csv($bytes));
                $response->setFile($tmp);
                $response->deleteFileAfterSend(true);
            }
        } elseif ($kind && ! $response instanceof BinaryFileResponse && ! $response instanceof StreamedResponse && method_exists($response, 'getContent') && ! $response->isRedirection()) {
            $c = (string) $response->getContent();
            $response->setContent($kind === 'xlsx' ? ExportMarker::xlsx($c) : ExportMarker::csv($c));
        } elseif (! $isFile && str_contains($type, 'text/html') && ! $response->isRedirection() && ! $response instanceof StreamedResponse && ! $response instanceof BinaryFileResponse && ! $request->headers->has('X-Inertia')) {
            $html = (string) $response->getContent();
            // a page that is meant to be printed or turned into a document (not an ordinary app page)
            if (! str_contains($html, 'data-page=') && (str_contains($html, '@page') || str_contains($html, 'window.print') || str_contains($html, 'class="letterhead"'))) {
                $response->setContent(ExportMarker::html($html));
            }
        }

        return $response;
    }
}
