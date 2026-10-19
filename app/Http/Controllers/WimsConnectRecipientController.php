<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WimsConnectRecipientChannel;
use App\Services\WimsConnect\ChannelAvailability;
use App\Services\WimsConnect\RecipientDirectory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WimsConnectRecipientController extends Controller
{
    /** The contact book: mobile (SMS), email, WhatsApp and Meta, in the order they are shown. */
    protected const CHANNELS = ['sms', 'email', 'whatsapp', 'meta'];

    public function index(Request $request)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);

        $accountId = $request->user()->account_id;
        RecipientDirectory::syncAll((int) $accountId); // pull whatever WIMS knows about each person; new details wait to be enabled

        // every person of the account (a new login appears the moment it is created); deactivated people are listed too and marked
        $users = User::where('account_id', $accountId)
            ->with(['roles:id,name', 'warehouses:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_active']);

        $channels = WimsConnectRecipientChannel::whereIn('user_id', $users->pluck('id'))
            ->get(['user_id', 'channel', 'address', 'is_enabled', 'auto_filled'])
            ->groupBy('user_id');

        $channelLive = ChannelAvailability::liveMap();

        $rows = $users->map(function ($user) use ($channels, $channelLive) {
            $userChannels = $channels->get($user->id, collect());

            $row = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'active' => (bool) $user->is_active,
                'roles' => $user->roles->pluck('name')->values(),
                'warehouses' => $user->warehouses->pluck('name')->values(),
            ];

            foreach (self::CHANNELS as $channel) {
                $channelRow = $userChannels->firstWhere('channel', $channel);
                $address = trim((string) ($channelRow?->address ?? ''));
                $enabled = (bool) ($channelRow?->is_enabled ?? false);

                $row[$channel === 'email' ? 'email_contact' : $channel] = [
                    'address' => $address,
                    'is_enabled' => $enabled,
                    'pulled' => (bool) ($channelRow?->auto_filled ?? false),
                    'live' => $enabled && $address !== '' && $channelLive[$channel],
                ];
            }

            return $row;
        });

        return Inertia::render('WimsConnect/Recipients', [
            'users' => $rows,
            'channelLive' => $channelLive,
        ]);
    }

    public function show(Request $request, User $user)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);
        abort_unless($user->account_id === $request->user()->account_id, 404);

        RecipientDirectory::syncUser($user);
        $channelRows = WimsConnectRecipientChannel::where('user_id', $user->id)->get()->keyBy('channel');
        $channels = [];
        foreach (self::CHANNELS as $channel) {
            $row = $channelRows->get($channel);
            $channels[$channel] = [
                'address' => $row?->address ?? '',
                'is_enabled' => (bool) ($row?->is_enabled ?? false),
                'is_verified' => (bool) ($row?->is_verified ?? false),
                'pulled' => (bool) ($row?->auto_filled ?? false),
            ];
        }

        return Inertia::render('WimsConnect/RecipientProfile', [
            'wcUser' => $user->only(['id', 'name', 'email']),
            'roles' => $user->roles()->pluck('name'),
            'channels' => $channels,
            'channelLive' => ChannelAvailability::liveMap(),
        ]);
    }

    /** Switches one channel of one person on or off from the list (the address stays as it is). */
    public function toggle(Request $request, User $user)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);
        abort_unless($user->account_id === $request->user()->account_id, 404);
        $data = $request->validate(['channel' => 'required|in:' . implode(',', self::CHANNELS), 'is_enabled' => 'required|boolean']);

        $row = WimsConnectRecipientChannel::where('user_id', $user->id)->where('channel', $data['channel'])->first();
        if (! $row || trim((string) $row->address) === '') {
            return response()->json(['message' => __('Add a detail for this channel first.')], 422);
        }
        $row->update(['is_enabled' => (bool) $data['is_enabled']]);
        log_activity('WIMS Connect Recipient Channel ' . ($row->is_enabled ? 'Enabled' : 'Disabled'), ['user_id' => $user->id, 'channel' => $data['channel']], $user, 'WIMS Connect');

        return back();
    }

    /** Switches on every pulled detail of one channel that is still waiting (nobody typed it in, nobody enabled it yet). */
    public function enableWaiting(Request $request)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);
        $data = $request->validate(['channel' => 'required|in:' . implode(',', self::CHANNELS)]);
        $ids = User::where('account_id', $request->user()->account_id)->where('is_active', true)->pluck('id');

        $n = WimsConnectRecipientChannel::whereIn('user_id', $ids)->where('channel', $data['channel'])->where('is_enabled', false)->where('auto_filled', true)->where('address', '!=', '')->update(['is_enabled' => true]);
        log_activity('WIMS Connect Recipient Channels Enabled In Bulk', ['channel' => $data['channel'], 'count' => $n], $request->user(), 'WIMS Connect');

        return back()->with('message', trans_choice(':n detail switched on.|:n details switched on.', $n, ['n' => $n]));
    }

    public function updateChannels(Request $request, User $user)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);
        abort_unless($user->account_id === $request->user()->account_id, 404);

        $data = $request->validate([
            'channels' => 'present|array',
            'channels.*.address' => 'nullable|string|max:190',
            'channels.*.is_enabled' => 'boolean',
        ]);

        // check every channel first, so one bad detail saves nothing (not even the channels before it)
        $clean = [];
        foreach (self::CHANNELS as $channel) {
            $row = $data['channels'][$channel] ?? ['address' => '', 'is_enabled' => false];
            $address = trim((string) ($row['address'] ?? ''));

            if ($channel === 'email' && $address !== '' && ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['channels.email.address' => __('Enter a valid email address.')]);
            }

            // mobile numbers (SMS and WhatsApp) are kept as +255XXXXXXXXX
            if (in_array($channel, ['sms', 'whatsapp'], true) && $address !== '') {
                $tz = \App\Support\TzPhone::normalize($address);
                if (! $tz) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['channels.' . $channel . '.address' => \App\Support\TzPhone::message()]);
                }
                $address = $tz;
            }

            $clean[$channel] = [$address, $row];
        }

        foreach ($clean as $channel => [$address, $row]) {
            $existing = WimsConnectRecipientChannel::where('user_id', $user->id)->where('channel', $channel)->first();

            if ($address === '') {
                // cleared on purpose: keep an empty, switched-off row so the pull from the user's records does not refill it
                if ($existing) {
                    $existing->update(['address' => '', 'is_enabled' => false, 'auto_filled' => false]);
                }
                continue;
            }

            // an address typed in by hand is no longer a pulled one
            $typed = ! $existing || $existing->address !== $address || ! $existing->auto_filled;
            WimsConnectRecipientChannel::updateOrCreate(
                ['user_id' => $user->id, 'channel' => $channel],
                ['address' => $address, 'is_enabled' => (bool) ($row['is_enabled'] ?? false), 'auto_filled' => ! $typed]
            );
        }

        log_activity('WIMS Connect Recipient Channels Updated', ['user_id' => $user->id], $user, 'WIMS Connect');

        return back()->with('message', __('Communication channels saved.'));
    }

}
