<?php

namespace App\Models;

use App\Traits\HasAttachments;

/** A reminder sent about an approved Material Request that has not reached its final status: a note, files, and who was told (the delivery rows). Kept as the history of the request. */
class MaterialRequestReminder extends Model
{
    use HasAttachments;

    protected $table = 'mr_reminders';

    protected $fillable = ['account_id', 'material_request_id', 'reference', 'status_at_send', 'note', 'created_by_user_id'];

    public function materialRequest()
    {
        return $this->belongsTo(MaterialRequest::class, 'material_request_id')->withTrashed();
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function deliveries()
    {
        return $this->hasMany(MaterialRequestReminderDelivery::class, 'reminder_id');
    }
}
