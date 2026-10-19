<?php

namespace App\Services\WimsConnect;

use App\Models\User;
use App\Models\WimsConnectLog;
use App\Models\WimsConnectRecipientChannel;
use App\Support\CommunicationEventRegistry;
use App\Support\Workflow\WorkflowModules;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Str;

/**
 * The centre of everything WIMS sends out (the app bell is not included): every SMS, WhatsApp and Meta message is written by the WIMS
 * Connect sender, and every email, whichever part of WIMS sent it, is written here when Laravel sends it. The Delivery Logs page reads
 * this one table. Codes and links that are secrets (sign-in codes, password reset links) are logged without their content.
 */
class OutboundLog
{
    public const SENSITIVE_HEADER = 'X-WIMS-Sensitive';
    public const EVENT_HEADER = 'X-WIMS-Event';

    /** The email Laravel is handing to the mail server right now (kept so a failed delivery can still be written to the log). */
    private static ?array $inflight = null;

    /** An email is about to be handed to the mail server. */
    public static function mailSending(MessageSending $event): void
    {
        self::$inflight = ['message' => $event->message, 'at' => time()];
    }

    /**
     * The mail server refused or could not be reached (reported through the exception handler, so it covers emails sent directly, from
     * a queue worker or from a notification). Writes one FAILED row per recipient of the email that was being sent. Never throws.
     */
    public static function mailFailed(\Throwable $e): void
    {
        try {
            $in = self::$inflight;
            self::$inflight = null;
            if (! $in || time() - $in['at'] > 120) {
                return;
            }
            $msg = $in['message'];
            $headers = $msg->getHeaders();
            $sensitive = $headers->has(self::SENSITIVE_HEADER);
            $eventKey = $headers->has(self::EVENT_HEADER) ? trim($headers->get(self::EVENT_HEADER)->getBodyAsString()) : null;
            $to = $msg->getTo() ?: $msg->getBcc();
            foreach ($to as $addr) {
                self::record([
                    'event_key' => $eventKey ?: 'email', 'channel' => 'email', 'address' => $addr->getAddress(), 'recipient_user_id' => self::userIdForEmail($addr->getAddress()),
                    'subject' => (string) $msg->getSubject(), 'message' => $sensitive ? __('(content hidden for security)') : Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) ($msg->getHtmlBody() ?: $msg->getTextBody())))), 3000, ''),
                    'status' => 'FAILED', 'error_message' => Str::limit(strip_tags($e->getMessage()), 300) ?: __('The email could not be sent.'),
                    'meta' => ['sensitive' => $sensitive],
                ]);
            }
        } catch (\Throwable $x) {
            // logging must never hide the original problem
        }
    }

    /** Every email that leaves WIMS. Never throws: a logging problem must not stop a message. */
    public static function mailSent(MessageSent $event): void
    {
        try {
            self::$inflight = null;
            $msg = $event->message;
            $headers = $msg->getHeaders();
            $sensitive = $headers->has(self::SENSITIVE_HEADER);
            $eventKey = $headers->has(self::EVENT_HEADER) ? trim($headers->get(self::EVENT_HEADER)->getBodyAsString()) : null;
            if (! $eventKey && ! empty($event->data['__laravel_notification'])) {
                $eventKey = Str::kebab(Str::beforeLast(class_basename($event->data['__laravel_notification']), 'Notification') ?: class_basename($event->data['__laravel_notification']));
            }
            $html = $msg->getHtmlBody();
            $text = $msg->getTextBody() ?: trim(preg_replace('/\s+/', ' ', strip_tags((string) $html)));
            $attachments = collect($msg->getAttachments())->map(fn ($a) => $a->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename') ?: 'attachment')->values()->all();
            $from = collect($msg->getFrom())->map(fn ($a) => $a->getAddress())->implode(', ');

            foreach ($msg->getTo() ?: $msg->getBcc() as $to) {
                self::record([
                    'event_key' => $eventKey ?: 'email', 'channel' => 'email', 'address' => $to->getAddress(), 'recipient_user_id' => self::userIdForEmail($to->getAddress()),
                    'subject' => (string) $msg->getSubject(),
                    'message' => $sensitive ? __('(content hidden for security)') : Str::limit($text, 3000, ''),
                    'body_html' => $sensitive ? null : $html,
                    'meta' => ['from' => $from, 'attachments' => $attachments, 'sensitive' => $sensitive, 'cc' => collect($msg->getCc())->map(fn ($a) => $a->getAddress())->all(), 'bcc' => collect($msg->getBcc())->map(fn ($a) => $a->getAddress())->all()],
                    'status' => 'SENT', 'sent_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** A notification whose email could not be sent. */
    public static function notificationFailed(NotificationFailed $event): void
    {
        try {
            if ($event->channel !== 'mail') {
                return;
            }
            $address = method_exists($event->notifiable, 'routeNotificationFor') ? $event->notifiable->routeNotificationFor('mail', $event->notification) : null;
            $data = method_exists($event->notification, 'toArray') ? (array) $event->notification->toArray($event->notifiable) : [];
            $error = $event->data['message'] ?? ($event->data['exception'] ?? null);
            self::record([
                'event_key' => (string) ($data['type'] ?? 'email'), 'channel' => 'email', 'address' => is_string($address) ? $address : null,
                'recipient_user_id' => $event->notifiable instanceof User ? $event->notifiable->id : null,
                'subject' => (string) ($data['title'] ?? ''), 'message' => (string) ($data['message'] ?? ''), 'status' => 'FAILED',
                'error_message' => $error ? Str::limit(strip_tags(is_string($error) ? $error : json_encode($error)), 300) : __('The email could not be sent.'),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** One row of the log (an email, or an SMS / WhatsApp / Meta message that was not queued through the normal sender). */
    public static function record(array $f): ?WimsConnectLog
    {
        $f += ['source_type' => null, 'source_id' => null, 'recipient_user_id' => null, 'status' => 'SENT'];
        $f['idempotency_key'] = $f['idempotency_key'] ?? ($f['channel'] . ':' . Str::uuid());

        return WimsConnectLog::create($f);
    }

    private static function userIdForEmail(?string $address): ?int
    {
        if (! $address) {
            return null;
        }
        $a = strtolower($address);

        return WimsConnectRecipientChannel::where('channel', 'email')->whereRaw('LOWER(address) = ?', [$a])->value('user_id')
            ?: User::whereRaw('LOWER(email) = ?', [$a])->value('id');
    }

    /** A readable name for an event key: the registry, then the workflow pages ("module-event" / "module_event"), then the key itself. */
    public static function eventLabels(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        foreach (WorkflowModules::all() as $mKey => $m) {
            foreach ($m['events'] as $eKey => $e) {
                $label = $e['label'];
                foreach ([$mKey . '-' . str_replace('_', '-', $eKey), $mKey . '_' . $eKey, str_replace('_', '-', $mKey) . '-' . str_replace('_', '-', $eKey)] as $k) {
                    $map[$k] = $label;
                }
            }
        }
        foreach (CommunicationEventRegistry::events() as $k => $e) {
            $map[$k] = $e['label'] ?? $k;
        }
        $map += ['two_factor_otp' => __('Sign-in code'), 'two-factor-otp' => __('Sign-in code'), 'reset-password' => __('Password reset'), 'email' => __('Email'), 'bulk-connect' => __('Bulk Connect message')];

        return $map;
    }
}
