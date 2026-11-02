<?php

namespace App\Support\BackgroundTasks;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * One kind of background job. A kind only wraps an EXISTING report action: it calls the very same controller method the page's own button calls, as the same
 * person, so the content and the permissions are exactly what they would have been on the page. Only READ-ONLY reports belong here (nothing that writes business
 * data, sends a message or moves stock).
 */
abstract class TaskKind
{
    abstract public function key(): string;

    /** The words shown in the toast. */
    abstract public function label(array $params): string;

    /** The ability the normal route asks for. Asked again when the task runs, not only when it is started. */
    abstract public function allowed(User $user): bool;

    /** The values this kind accepts, checked and cleaned (anything else is dropped). Throws a ValidationException for a wrong value. */
    abstract public function params(Request $request): array;

    /** @return array{content: string, name: string, mime: string} the finished file, made by the existing report action */
    abstract public function build(array $params, User $user): array;

    /** A condition that belongs to the person's session at start time (for example Login Logs must be unlocked). Return a message to refuse. */
    public function startRefusal(Request $request): ?string
    {
        return null;
    }

    /** A middleware class of the normal page that must also be satisfied when the file is downloaded (null = none). */
    public function downloadMiddleware(): ?string
    {
        return null;
    }

    /** Names the exact job: the same kind with the same cleaned values is the same job (and so cannot be started twice at once). */
    public function subject(array $params): string
    {
        ksort($params);

        return $this->key() . ':' . substr(sha1(json_encode($params)), 0, 20);
    }

    /** Calls an existing controller action in this process as `$user` and returns the file it produced. */
    protected function callAction(string $controller, string $method, array $query, User $user): array
    {
        Auth::guard('web')->setUser($user);
        Auth::shouldUse('web');
        $request = Request::create('/background-task', 'GET', $query);
        $request->setUserResolver(fn () => $user);
        app()->instance('request', $request);

        $response = app()->call([app($controller), $method], ['request' => $request]);
        if (! is_object($response) || ! method_exists($response, 'getContent')) {
            throw new TaskException('The report did not produce a file.');
        }
        $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
        if ($status >= 300) {
            throw new TaskException('The report was refused (status ' . $status . ').');
        }
        $content = (string) $response->getContent();
        if ($content === '') {
            throw new TaskException('The report came out empty.');
        }
        $disposition = (string) $response->headers->get('content-disposition');
        $name = preg_match('/filename="?([^";]+)"?/i', $disposition, $m) ? basename($m[1]) : $this->key() . '-' . now()->format('Ymd-His') . '.pdf';

        return ['content' => $content, 'name' => $name, 'mime' => (string) ($response->headers->get('content-type') ?: 'application/pdf')];
    }
}
