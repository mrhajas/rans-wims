<?php

namespace App\Services;

/**
 * The "Superadmin Alerts" of Task Manager now live on the Task Manager workflow page (events "Super Admin Alert (app and email)" and
 * "Super Admin Alert (SMS)", sent by TaskWorkflowNotifier). Only the phone-number helper is left here because SalarySmsNotifier still uses it.
 */
class TaskSuperadminAlertNotifier
{
    /**
     * Beem (and Tanzanian SMS delivery generally) needs the international form: a number saved in local format (e.g. "0674020223")
     * would either fail outright or route nowhere.
     */
    public static function normalizeTanzanianPhone(string $phone): string
    {
        $digits = preg_replace('/[^\d+]/', '', $phone) ?? '';

        if (str_starts_with($digits, '+255')) {
            return $digits;
        }

        if (str_starts_with($digits, '255')) {
            return '+' . $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '+255' . substr($digits, 1);
        }

        return '+255' . ltrim($digits, '+');
    }
}
