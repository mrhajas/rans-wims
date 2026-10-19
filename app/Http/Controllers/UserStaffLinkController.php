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
            \App\Services\StaffNotifier::fire('created_from_user', $staff, __('Staff created from a login'), __('A staff profile for :s was created from the login of :u by :n.', ['s' => $staff->name, 'u' => $lockedUser->name, 'n' => auth()->user()->name]), auth()->user());

            return $staff;
        });

        // Straight to the profile so HR can finish the details the user record could not provide.
        return redirect()->route('staffs.edit', $staff->id)
            ->with('message', __('Staff profile :ref created from the user. Complete the remaining details (join date, salary, bank …) and save.', ['ref' => $staff->staff_number]));
    }

    public function unlink(Request $request, User $user)
    {
        $this->authorize('update-staffs');

        $staff = Staff::where('user_id', $user->id)->firstOrFail();
        $staff->forceFill(['user_id' => null])->save();

        log_activity(__('Staff profile :ref unlinked from user :user.', ['ref' => $staff->staff_number, 'user' => $user->name]), ['staff_number' => $staff->staff_number, 'user_id' => $user->id], $staff, 'Staff');
        log_activity(__('Unlinked from staff profile :ref.', ['ref' => $staff->staff_number]), ['staff_number' => $staff->staff_number], $user, 'User');
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
