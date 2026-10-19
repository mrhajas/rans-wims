<?php

namespace App\Exceptions;

use Throwable;
use Inertia\Inertia;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    protected $dontReport = [
        //
    ];

    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Delivery Logs: an email the mail server refused (or could not be reached for) is written to the log as FAILED, wherever it was sent from
        $this->reportable(function (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            \App\Services\WimsConnect\OutboundLog::mailFailed($e);
        });
    }

    public function render($request, Throwable $e)
    {
        // A date or time typed (or sent) in a form that cannot be read ("from=abc" on a report) is the person's mistake, not a server fault
        $inner = $e;
        while ($inner->getPrevious() && ! $inner instanceof \Carbon\Exceptions\InvalidFormatException) {
            $inner = $inner->getPrevious();
        }
        if ($inner instanceof \Carbon\Exceptions\InvalidFormatException && $request->route()) {
            $message = __('A date or time in your request is not valid.');
            if ($request->expectsJson() || $request->isMethod('GET')) {
                return $request->expectsJson() ? response()->json(['message' => $message], 422) : response($message, 422);
            }

            return back()->with('error', $message);
        }

        if ($e instanceof \Illuminate\Encryption\MissingAppKeyException) {
            return redirect()->to('/install');
        }

        // abort_if($condition, 422, 'message')/abort_unless(...) is used all
        // over this app's controllers to reject a business-rule violation
        // with a user-facing message (e.g. "This trip is linked to a
        // Payment Voucher and cannot be cancelled directly."). That throws
        // a plain HttpException, not a ValidationException — Inertia's own
        // middleware only auto-converts ValidationException into
        // page.props.errors, so a plain 422 HttpException fell through to
        // Laravel's default (non-Inertia) error response, which the
        // Inertia client can't render inline and instead shows as its
        // generic "Oops! An Error Occurred" overlay. Converting it into the
        // same back()->with('error', ...) flash every success message
        // already uses (see FlashMessages.vue) fixes that — this differs
        // from the deliberately-not-flashed codes below, which route
        // through failures in auth/session/CSRF state itself (401/403/419)
        // or an unhandled crash (500/503), not a healthy request hitting an
        // ordinary business-rule check.
        if (
            $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
            && $e->getStatusCode() === 422
            && ! $request->expectsJson()
            && $request->header('X-Inertia')
        ) {
            return back()->with('error', $e->getMessage() ?: __('The requested action could not be completed.'));
        }

        $response = parent::render($request, $e);

        // Rendered directly in this same response — deliberately not a
        // back()->with(flash) redirect. That path used to lose the flashed
        // message before the client ever saw it (session state didn't
        // survive the round trip), so errors failed completely silently.
        // Rendering here has no such dependency: nothing needs to survive
        // past this one response.
        if (
            ! app()->environment('local')
            && ! $request->expectsJson()
            && in_array($response->status(), [400, 401, 403, 404, 405, 419, 429, 500, 503])
        ) {
            return Inertia::render('Error', ['status' => $response->status()])
                ->toResponse($request)
                ->setStatusCode($response->status());
        }

        return $response;
    }
}
