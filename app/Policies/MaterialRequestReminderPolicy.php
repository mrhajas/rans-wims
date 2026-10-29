<?php

namespace App\Policies;

use App\Models\MaterialRequestReminder;
use App\Models\User;

/** Used by the shared file download: whoever may read reminders and may see the request may open the files of a reminder. */
class MaterialRequestReminderPolicy
{
    public function view(User $user, MaterialRequestReminder $reminder): bool
    {
        return $user->can('read-mr-reminders') && $reminder->materialRequest && $user->can('view', $reminder->materialRequest);
    }
}
