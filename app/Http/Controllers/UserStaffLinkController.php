<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\User;
use App\Support\ReferenceLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Connects a system login (User) to an employee record (Staff): link an existing profile, or convert the
 * user into a brand-new profile pre-filled from the user's own data, or unlink again.
 */
class UserStaffLinkController extends Controller
{
    public function __construct()
    {
        // Gated explicitly below: acting on staff needs the Staff permissions, not just users.
        $this->setAbilities([]);
    }

    /** What the user edit page needs to render its "Staff Profile" panel. */
    public static function payloadFor(User $user): array
    {
        $viewer = auth()->user();
        $staff = $user->staff;

        return [
            'can' => [
                'view' => $viewer->can('read-staffs'),
                'link' => $viewer->can('update-staffs'),
                'convert' => $viewer->can('create-staffs'),
                // the Sync button changes both records: the user (email, phone) and the staff profile (personal email, mobile)
                'sync' => $viewer->can('update-users') && $viewer->can('update-staffs'),
            ],
            'staff' => $staff ? [
                'id' => $staff->id,
                'staff_number' => $staff->staff_number,
                'name' => $staff->name,
                'position' => $staff->position,
                'status' => $staff->status,
                'photo_url' => $staff->photo ? \Illuminate\Support\Facades\Storage::disk('assets')->url($staff->photo) : null,
                'url' => $viewer->can('read-staffs') ? route('staffs.show', $staff->id) : null,
                'edit_url' => $viewer->can('update-staffs') ? route('staffs.edit', $staff->id) : null,
            ] : null,
            // Profiles that are not tied to any login yet (and are not terminated), for the "link existing" picker.
            'available' => $staff || ! $viewer->can('update-staffs') ? [] : Staff::whereNull('user_id')
                ->where('status', '!=', 'Terminated')
                ->orderBy('name')
                ->get(['id', 'staff_number', 'name', 'position'])
                ->map(fn ($s) => ['id' => $s->id, 'label' => $s->name . ' (' . $s->staff_number . ')' . ($s->position ? ' - ' . $s->position : '')])
                ->values(),
            // Preview of what "Create staff profile" will pre-fill.
            'defaults' => $staff ? null : collect(Staff::defaultsFromUser($user))->only(['name', 'personal_email', 'mobile_no', 'position', 'status'])->all(),
        ];
    }

    public function link(Request $request, User $user)
    {
        $this->authorize('update-staffs');

        $data = $request->validate([
            'staff_id' => 'required|integer|exists:staffs,id',
            'fill_blanks' => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($user, $data) {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $staff = Staff::whereKey($data['staff_id'])->lockForUpdate()->firstOrFail();

            if (Staff::where('user_id', $lockedUser->id)->exists()) {
                throw ValidationException::withMessages(['staff_id' => __('This user is already linked to a staff profile.')]);
            }
            if ($staff->user_id) {
                throw ValidationException::withMessages(['staff_id' => __('That staff profile is already linked to another user.')]);
            }

            $staff->user_id = $lockedUser->id;

            // Optionally top up EMPTY staff fields from the user (never overwrites anything already entered).
            if ($data['fill_blanks'] ?? true) {
                $defaults = Staff::defaultsFromUser($lockedUser);
                foreach (['personal_email', 'mobile_no', 'position'] as $field) {
                    if (blank($staff->{$field}) && filled($defaults[$field] ?? null)) {
                        $staff->{$field} = $defaults[$field];
                    }
                }
            }

            $staff->save();

            log_activity(__('Staff profile :ref linked to user :user.', ['ref' => $staff->staff_number, 'user' => $lockedUser->name]), ['staff_number' => $staff->staff_number, 'user_id' => $lockedUser->id], $staff, 'Staff');
            log_activity(__('Linked to staff profile :ref.', ['ref' => $staff->staff_number]), ['staff_number' => $staff->staff_number], $lockedUser, 'User');
            \App\Support\UserAudit::record($lockedUser, 'staff_link', ['action' => 'linked', 'staff' => $staff->name, 'staff_number' => $staff->staff_number]);
            \App\Services\StaffNotifier::fire('linked', $staff, __('Staff linked'), $staff->name.' '.__('was linked to the login of :u by :n.', ['u' => $lockedUser->name, 'n' => auth()->user()->name]), auth()->user());
        });

        return back()->with('message', __('User linked to the staff profile.'));
    }

    public function convert(Request $request, User $user)
    {
        $this->authorize('create-staffs');

        $staff = DB::transaction(function () use ($request, $user) {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (Staff::where('user_id', $lockedUser->id)->exists()) {
                throw ValidationException::withMessages(['staff_id' => __('This user is already linked to a staff profile.')]);
            }

            $staff = ReferenceLock::run('staffs', function () use ($request, $lockedUser) {
                $staff = new Staff();
                $staff->fill(Staff::defaultsFromUser($lockedUser));
                $staff->staff_number = $this->nextStaffNumber();
                $staff->created_by_user_id = $request->user()->id;
                $staff->basic_salary = 0;
                $staff->allowances = 0;
                $staff->deductions = 0;
                $staff->net_salary = 0;
                $staff->photo = $this->copyProfilePhoto($lockedUser);
                $staff->save();

                return $staff;
            });

            log_activity(__('Staff record created.'), ['staff_number' => $staff->staff_number, 'created_from_user' => $lockedUser->id], $staff, 'Staff');
            log_activity(__('Staff profile :ref created from this user.', ['ref' => $staff->staff_number]), ['staff_number' => $staff->staff_number], $lockedUser, 'User');
            \App\Support\UserAudit::record($lockedUser, 'staff_link', ['action' => 'created', 'staff' => $staff->name, 'staff_number' => $staff->staff_number]);
            \App\Services\StaffNotifier::fire('created_from_user', $staff, __('Staff created from a login'), __('A staff profile for :s was created from the login of :u by :n.', ['s' => $staff->name, 'u' => $lockedUser->name, 'n' => auth()->user()->name]), auth()->user());

            return $staff;
        });

        // Straight to the profile so HR can finish the details the user record could not provide.
        return redirect()->route('staffs.edit', $staff->id)
            ->with('message', __('Staff profile :ref created from the user. Complete the remaining details (join date, salary, bank …) and save.', ['ref' => $staff->staff_number]));
    }

    /** The two details both records keep. */
    private const SYNC_FIELDS = [
        'email' => ['label' => 'Email', 'user' => 'email', 'staff' => 'personal_email'],
        'phone' => ['label' => 'Phone', 'user' => 'phone', 'staff' => 'mobile_no'],
    ];

    /** The value each side holds, in the form it is compared (email lower case; phone as +255XXXXXXXXX when it is a valid number). */
    private static function syncValue(string $field, ?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        return $field === 'phone' ? (\App\Support\TzPhone::normalize($raw) ?? $raw) : mb_strtolower($raw);
    }

    /**
     * What a sync would do, field by field. Directions:
     *  - auto (the default): User -> Staff. The user's value is written to the staff profile; where the user has NOTHING and the staff profile has a value,
     *    that value is brought into the user instead.
     *  - user_to_staff: only User -> Staff.
     *  - staff_to_user: only Staff -> User.
     * An action is push (user value goes to the profile), pull (profile value goes to the user), same (already equal) or none (nothing on the side that would give).
     */
    public static function syncPlan(User $user, Staff $staff, string $direction = 'auto'): array
    {
        $rows = [];
        foreach (self::SYNC_FIELDS as $key => $f) {
            $uRaw = $key === 'phone' ? data_get($user->extra_attributes, 'phone') : $user->email;
            $sRaw = $staff->{$f['staff']};
            $u = self::syncValue($key, $uRaw);
            $s = self::syncValue($key, $sRaw);

            $action = 'none';
            if ($u !== null && $s !== null && $u === $s) {
                $action = 'same';
            } elseif ($direction === 'staff_to_user') {
                $action = $s !== null ? 'pull' : 'none';
            } elseif ($u !== null) {
                $action = 'push';
            } elseif ($direction === 'auto' && $s !== null) {
                $action = 'pull';
            }

            $reason = null;
            $result = $action === 'push' ? $u : ($action === 'pull' ? $s : null);
            if ($action === 'pull' && $key === 'email') {
                if (! filter_var($s, FILTER_VALIDATE_EMAIL)) {
                    [$action, $reason] = ['skip', __('The staff email is not a valid address.')];
                } elseif (User::withTrashed()->where('email', $s)->where('id', '!=', $user->id)->exists()) {
                    [$action, $reason] = ['skip', __('Another login already uses this email.')];
                }
            }
            if ($action === 'pull' && $key === 'phone') {
                $n = \App\Support\TzPhone::normalize($sRaw);
                if (! $n) {
                    [$action, $reason] = ['skip', __('The staff number is not a valid Tanzanian mobile number.')];
                } else {
                    $result = $n;
                }
            }
            if ($action === 'push' && $key === 'phone' && ! \App\Support\TzPhone::isValid($uRaw)) {
                [$action, $reason] = ['skip', __('The user phone is not a valid Tanzanian mobile number.')];
            }
            if ($action === 'push' && $key === 'email' && ! filter_var($u, FILTER_VALIDATE_EMAIL)) {
                [$action, $reason] = ['skip', __('The user email is not a valid address.')];
            }
            if ($action === 'skip') {
                $result = null;
            }

            $rows[] = ['key' => $key, 'label' => __($f['label']), 'user' => $uRaw ?: null, 'staff' => $sRaw ?: null, 'action' => $action, 'result' => $result, 'reason' => $reason];
        }

        return $rows;
    }

    private function syncGuard(Request $request, User $user): Staff
    {
        abort_unless($request->user()->can('update-users') && $request->user()->can('update-staffs'), 403);
        if ($user->hasRole('Super Admin') && ! $request->user()->hasRole('Super Admin')) {
            abort(403, __('Only a Super Admin can update a Super Admin account.'));
        }
        if ($user->id === $request->user()->id && ! \App\Services\Licensing\LicenceService::isManager($request->user())) {
            abort(403, __('You should not update your own account.'));
        }

        $staff = Staff::where('user_id', $user->id)->first();
        abort_unless($staff, 422, __('This user is not linked to a staff profile.'));

        return $staff;
    }

    /** What the Sync button would do (shown before anything is changed). */
    public function syncPreview(Request $request, User $user)
    {
        $staff = $this->syncGuard($request, $user);
        $direction = in_array($request->query('direction'), ['auto', 'user_to_staff', 'staff_to_user'], true) ? $request->query('direction') : 'auto';

        return response()->json([
            'direction' => $direction,
            'staff' => ['name' => $staff->name, 'staff_number' => $staff->staff_number],
            'rows' => self::syncPlan($user, $staff, $direction),
        ]);
    }

    /** Applies the sync: writes the user and / or the staff profile, tells the Recipients book, logs it. */
    public function sync(Request $request, User $user)
    {
        $staff = $this->syncGuard($request, $user);
        $direction = $request->validate(['direction' => 'nullable|in:auto,user_to_staff,staff_to_user'])['direction'] ?? 'auto';

        $applied = DB::transaction(function () use ($user, $staff, $direction) {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $lockedStaff = Staff::whereKey($staff->id)->lockForUpdate()->firstOrFail();
            $applied = [];

            foreach (self::syncPlan($lockedUser, $lockedStaff, $direction) as $row) {
                if ($row['action'] === 'push') {
                    $lockedStaff->{self::SYNC_FIELDS[$row['key']]['staff']} = $row['result'];
                } elseif ($row['action'] === 'pull') {
                    if ($row['key'] === 'email') {
                        $lockedUser->email = $row['result'];
                    } else {
                        $extra = (array) ($lockedUser->extra_attributes ?? []);
                        $extra['phone'] = $row['result'];
                        $lockedUser->extra_attributes = $extra;
                    }
                } else {
                    continue;
                }
                $applied[] = ['field' => $row['label'], 'action' => $row['action'], 'from' => $row['action'] === 'push' ? 'user' : 'staff', 'old' => $row['action'] === 'push' ? $row['staff'] : $row['user'], 'new' => $row['result']];
            }
            if ($applied) {
                $lockedStaff->save();
                $lockedUser->save();
            }

            return $applied;
        });

        $summary = collect($applied)->map(fn ($a) => $a['field'] . ' ' . ($a['action'] === 'push' ? __('user to staff') : __('staff to user')))->implode(', ');
        if ($applied) {
            // the contact book (Recipients) follows the records it was pulled from
            try {
                \App\Services\WimsConnect\RecipientDirectory::syncUser($user->fresh());
            } catch (\Throwable $e) {
                report($e);
            }
            log_activity(__('Email / phone synced between user :user and staff profile :ref (:what).', ['user' => $user->name, 'ref' => $staff->staff_number, 'what' => $summary]), ['direction' => $direction, 'changes' => $applied], $user, 'User');
            \App\Support\UserAudit::record($user, 'staff_sync', ['direction' => $direction, 'staff_number' => $staff->staff_number, 'changes' => $applied]);
        }

        return back()->with('message', $applied ? __('Synced: :what.', ['what' => $summary]) : __('Nothing to sync: the user and the staff profile already agree, or there is nothing to copy.'));
    }

    public function unlink(Request $request, User $user)
    {
        $this->authorize('update-staffs');

        $staff = Staff::where('user_id', $user->id)->firstOrFail();
        $staff->forceFill(['user_id' => null])->save();

        log_activity(__('Staff profile :ref unlinked from user :user.', ['ref' => $staff->staff_number, 'user' => $user->name]), ['staff_number' => $staff->staff_number, 'user_id' => $user->id], $staff, 'Staff');
        log_activity(__('Unlinked from staff profile :ref.', ['ref' => $staff->staff_number]), ['staff_number' => $staff->staff_number], $user, 'User');
        \App\Support\UserAudit::record($user, 'staff_link', ['action' => 'unlinked', 'staff' => $staff->name, 'staff_number' => $staff->staff_number]);
        \App\Services\StaffNotifier::fire('unlinked', $staff, __('Staff unlinked'), $staff->name.' '.__('was unlinked from the login of :u by :n.', ['u' => $user->name, 'n' => $request->user()->name]), $request->user(), null, ['user' => (string) $user->name]);

        return back()->with('message', __('User unlinked from the staff profile.'));
    }

    private function nextStaffNumber(): string
    {
        $prefixLength = strlen('STF-') + 1;

        $maxSuffix = (int) DB::table('staffs')
            ->selectRaw("MAX(CAST(SUBSTRING(staff_number, {$prefixLength}) AS UNSIGNED)) as max_suffix")
            ->where('staff_number', 'like', 'STF-%')
            ->value('max_suffix');

        return 'STF-' . str_pad((string) ($maxSuffix + 1), 5, '0', STR_PAD_LEFT);
    }

    /** Gives the new staff profile its own copy of the user's profile photo (null if the user has none or it cannot be read). */
    private function copyProfilePhoto(User $user): ?string
    {
        if (! $user->profile_photo_path) {
            return null;
        }

        try {
            $source = Storage::disk(config('jetstream.profile_photo_disk', 'public'));
            if (! $source->exists($user->profile_photo_path)) {
                return null;
            }

            $ext = pathinfo($user->profile_photo_path, PATHINFO_EXTENSION) ?: 'jpg';
            $target = 'staffs/' . \Illuminate\Support\Str::uuid() . '.' . $ext;
            Storage::disk('assets')->put($target, $source->get($user->profile_photo_path));

            return $target;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
