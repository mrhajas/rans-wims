<?php

namespace App\Http\Controllers;

use App\Http\Requests\NoticeStoreRequest;
use App\Http\Resources\NoticeCollection;
use App\Models\Notice;
use App\Models\NoticeHistory;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class NoticeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $filters = $request->all('search', 'start_date', 'end_date');

        $query = Notice::with(['creator:id,name'])->withCount('histories')->filter($filters)->latest('id');

        // Visibility scope shared by the list and the stat tiles above it.
        $scope = null;
        if (! $user->hasRole(['Super Admin', 'Admin'])) {
            $roleIds = $user->roles->pluck('id');

            $scope = function ($q) use ($user, $roleIds) {
                $q->where('send_to_all', true)
                    ->orWhere('created_by', $user->id)
                    ->orWhereHas('targetUsers', fn ($uq) => $uq->where('users.id', $user->id))
                    ->orWhereHas('targetRoles', fn ($rq) => $rq->whereIn('roles.id', $roleIds));
            };
            $query->where($scope);
        }

        $notices = $query->paginate()->withQueryString();

        // Only Super Admin or the notice's own creator gets to see who else it went to.
        $notices->getCollection()
            ->filter(fn ($notice) => $user->hasRole('Super Admin') || (int) $notice->created_by === (int) $user->id)
            ->load('histories.user:id,name');

        $notices->getCollection()->each(function ($notice) use ($user) {
            $canSeeRecipients = $user->hasRole('Super Admin') || (int) $notice->created_by === (int) $user->id;
            $notice->recipient_names = $canSeeRecipients
                ? $notice->histories->pluck('user.name')->filter()->values()
                : null;
        });

        // Which of these notices are still unread by the current user (their in-app notification).
        $unreadNoticeIds = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->whereNull('read_at')
            ->whereIn('data->notice_id', $notices->getCollection()->pluck('id')->all() ?: [0])
            ->pluck('data')
            ->map(fn ($raw) => (int) (json_decode($raw, true)['notice_id'] ?? 0))
            ->all();

        $notices->getCollection()->each(function ($notice) use ($unreadNoticeIds) {
            $notice->is_unread = in_array((int) $notice->id, $unreadNoticeIds, true);
        });

        $statsBase = fn () => Notice::query()->when($scope, fn ($q) => $q->where($scope));
        $statsRow = $statsBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN created_by = ? THEN 1 ELSE 0 END), 0) as mine', [$user->id])
            ->selectRaw('COALESCE(SUM(CASE WHEN send_to_all = 1 THEN 1 ELSE 0 END), 0) as everyone')
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) as week', [now()->subDays(7)])
            ->first();

        $unread = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->whereNull('read_at')
            ->where('data->module', 'notice_board')
            ->count();

        return Inertia::render('Notice/Index', [
            'filters' => $filters,
            'notices' => new NoticeCollection($notices),
            'canCreate' => $user->can('create-notices'),
            'stats' => [
                'total' => (int) $statsRow->total,
                'mine' => (int) $statsRow->mine,
                'everyone' => (int) $statsRow->everyone,
                'week' => (int) $statsRow->week,
                'unread' => (int) $unread,
            ],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', Notice::class);

        return Inertia::render('Notice/Form', [
            'roles' => Role::query()->orderBy('name')->get(['id', 'name']),
            'users' => User::ofAccount()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(NoticeStoreRequest $request)
    {
        $this->authorize('create', Notice::class);

        $data = $request->validated();
        $recipientIds = $this->resolveRecipientUserIds($data);

        DB::transaction(function () use ($request, $data, $recipientIds) {
            $notice = Notice::create([
                'title' => $data['title'],
                'message' => $data['message'],
                'send_to_all' => (bool) ($data['send_to_all'] ?? false),
                'created_by' => $request->user()->id,
            ]);

            $roleIds = collect($data['role_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
            $userIds = collect($data['user_ids'] ?? [])->map(fn ($id) => (int) $id)->all();

            $notice->targetRoles()->sync($roleIds);
            $notice->targetUsers()->sync($userIds);

            $now = now();
            $historyRows = collect($recipientIds)->map(fn ($recipientId) => [
                'notice_id' => $notice->id,
                'user_id' => $recipientId,
                'sent_by' => $request->user()->id,
                'sent_at' => $now,
                'account_id' => getAccountId(),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            NoticeHistory::insert($historyRows);

            $recipients = User::query()->whereIn('id', $recipientIds)->get();
            foreach ($recipients as $recipient) {
                $recipient->notify(new AppNotification([
                    'module' => 'notice_board',
                    'type' => 'notice_published',
                    'title' => 'New Notice Board Message',
                    'message' => $notice->title,
                    'url' => route('notices.show', $notice->id),
                    'notice_id' => $notice->id,
                    'created_at' => $now->toDateTimeString(),
                ]));
            }
        });

        return redirect()->route('notices.index')->with('message', __('Notice has been sent successfully.'));
    }

    public function show(Request $request, Notice $notice)
    {
        $this->authorize('view', $notice);

        $notice->load([
            'creator:id,name',
            'targetRoles:id,name',
            'targetUsers:id,name',
        ]);

        $user = $request->user();
        $canViewSentHistory = $user->hasRole('Super Admin') || (int) $notice->created_by === (int) $user->id;

        if ($canViewSentHistory) {
            $notice->load(['histories.user:id,name', 'histories.sender:id,name']);

            $readAtByUserId = DB::table('notifications')
                ->where('notifiable_type', User::class)
                ->whereIn('notifiable_id', $notice->histories->pluck('user_id'))
                ->where('data->notice_id', $notice->id)
                ->pluck('read_at', 'notifiable_id');

            $notice->histories->each(function ($history) use ($readAtByUserId) {
                $history->read_at = $readAtByUserId->get($history->user_id);
            });
        }

        return Inertia::render('Notice/Show', [
            'notice' => $notice,
            'canViewSentHistory' => $canViewSentHistory,
        ]);
    }

    protected function resolveRecipientUserIds(array $data): array
    {
        if (! empty($data['send_to_all'])) {
            return User::ofAccount()->pluck('id')->all();
        }

        $roleIds = collect($data['role_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->all();
        $userIds = collect($data['user_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->all();

        $roleUserIds = [];
        if (! empty($roleIds)) {
            $roleUserIds = User::ofAccount()->whereHas('roles', fn ($q) => $q->whereIn('roles.id', $roleIds))->pluck('id')->all();
        }

        return collect($userIds)->merge($roleUserIds)->unique()->values()->all();
    }
}
