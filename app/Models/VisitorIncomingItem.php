<?php

namespace App\Models;

use App\Traits\HasAttachments;

/** A letter, document, package or parcel received at reception, for one person (a user of WIMS). Its status moves through the Super Admin's list; every change is kept. */
class VisitorIncomingItem extends Model
{
    use HasAttachments;

    public static $tracksDeletes = true;

    protected $table = 'visitor_incoming_items';

    protected $setHash = true;

    protected $fillable = ['account_id', 'reference', 'hash', 'received_at', 'item_type', 'sender_name', 'sender_company', 'sender_contact', 'courier', 'tracking_number', 'recipient_user_id', 'recipient_name',
        'department', 'subject', 'is_confidential', 'item_count', 'item_condition', 'received_by_user_id', 'visit_id', 'visitor_id', 'status', 'status_flag', 'status_changed_at', 'handed_to', 'handed_at',
        'released_by_user_id', 'handover_remarks', 'remarks', 'overdue_alerted_at', 'deleted_by', 'warehouse_id', 'sender_contact_id', 'extra_attributes'];

    public $casts = ['received_at' => 'datetime', 'status_changed_at' => 'datetime', 'handed_at' => 'datetime', 'overdue_alerted_at' => 'datetime', 'is_confidential' => 'boolean', 'extra_attributes' => 'array'];

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id')->withTrashed();
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function releasedBy()
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    public function visit()
    {
        return $this->belongsTo(VisitorVisit::class, 'visit_id');
    }

    public function visitor()
    {
        return $this->belongsTo(Visitor::class, 'visitor_id')->withTrashed();
    }

    public function senderContact()
    {
        return $this->belongsTo(Contact::class, 'sender_contact_id')->withTrashed();
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id')->withTrashed();
    }

    public static function nextReference(): string
    {
        $max = (int) \Illuminate\Support\Facades\DB::table('visitor_incoming_items')->selectRaw('MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)) as m')->where('reference', 'like', 'INC-%')->value('m');

        return 'INC-' . str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
    }

    /** Still waiting: not handed over and not closed. */
    public function scopeOpen($q)
    {
        return $q->where(fn ($w) => $w->whereNull('status_flag')->orWhereNotIn('status_flag', ['handed', 'closed']));
    }

    public function scopeAwaiting($q)
    {
        return $q->open();
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status_flag, ['handed', 'closed'], true);
    }

    /** Confidential items show their sender and subject only to the recipient, the person who received them and people with the confidential right. */
    public function canSeeDetails(User $u): bool
    {
        return ! $this->is_confidential || $u->hasRole('Super Admin') || $u->can('view-confidential-incoming-items') || (int) $this->recipient_user_id === $u->id || (int) $this->received_by_user_id === $u->id;
    }

    public function canBeSeenBy(User $u): bool
    {
        return $u->hasRole('Super Admin') || (int) $this->recipient_user_id === $u->id || (int) $this->received_by_user_id === $u->id || ($u->can('read-incoming-items') && $this->inMyProjects($u));
    }

    public function inMyProjects(User $u): bool
    {
        $ids = \App\Services\VisitorLogs\VisitorSettings::accessibleWarehouseIds($u);

        return $ids === null || ! $this->warehouse_id || in_array((int) $this->warehouse_id, $ids, true);
    }

    /** The items a person may see: those of their projects (and with no project), and the ones addressed to them or received by them. */
    public function scopeVisibleTo($q, User $u)
    {
        $ids = $u->hasRole('Super Admin') ? null : \App\Services\VisitorLogs\VisitorSettings::accessibleWarehouseIds($u);
        if ($ids === null) {
            return $q;
        }

        return $q->where(fn ($w) => $w->whereNull('warehouse_id')->orWhereIn('warehouse_id', $ids ?: [0])->orWhere('recipient_user_id', $u->id)->orWhere('received_by_user_id', $u->id));
    }
}
