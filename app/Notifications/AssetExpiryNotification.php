<?php

namespace App\Notifications;

use App\Models\Asset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssetExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Asset $asset, protected string $field, protected string $label, protected int $daysRemaining)
    {
    }

    public function via($notifiable)
    {
        return $notifiable->asset_expiry_email_enabled ? ['database', 'mail'] : ['database'];
    }

    public function toArray($notifiable)
    {
        return [
            'module' => 'assets',
            'type' => 'asset_expiry',
            'field' => $this->field,
            'asset_id' => $this->asset->id,
            'days_remaining' => $this->daysRemaining,
            'title' => __(':label expiring - :asset', ['label' => $this->label, 'asset' => $this->asset->name]),
            'message' => __(':label for :asset (:number) expires in :days day(s).', [
                'label' => $this->label,
                'asset' => $this->asset->name,
                'number' => $this->asset->asset_number,
                'days' => $this->daysRemaining,
            ]),
            'url' => route('assets.show', $this->asset->id, false),
            'created_at' => now()->toDateTimeString(),
        ];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject(__(':label expiring - :asset', ['label' => $this->label, 'asset' => $this->asset->name]))
            ->view('emails.asset-expiry', [
                'asset' => $this->asset,
                'label' => $this->label,
                'daysRemaining' => $this->daysRemaining,
                'notifiable' => $notifiable,
                'url' => route('assets.show', $this->asset->id),
            ]);
    }
}
