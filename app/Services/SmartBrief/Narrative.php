<?php

namespace App\Services\SmartBrief;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The natural-language summary, built ONLY from the verified events handed in (deterministic, no AI). Every sentence carries the ids of the
 * events it was counted from, so the reader can see the records behind it. It never states more than the events say: it counts
 * what was recorded (an approval is only called an approval when an approval step was recorded), says "recorded" for sign-outs, and
 * names what WIMS cannot see.
 */
class Narrative
{
    private const VERBS = [
        'created' => ['created', 'record'], 'updated' => ['updated', 'record'], 'submitted' => ['submitted', 'item'], 'approved' => ['approved', 'item'],
        'rejected' => ['rejected', 'item'], 'returned' => ['sent back', 'item'], 'deleted' => ['deleted', 'record'], 'restored' => ['restored', 'record'],
        'completed' => ['completed a step on', 'item'],
    ];

    /**
     * @param bool $own  true = "you", false = the person's name
     * @return array{sentences: array<int, array{text:string,evidence:array,count:int,tone:string}>, text: string}
     */
    public function build(Collection $events, array $sessions, array $pending, string $name, bool $own, Period $period, array $flags): array
    {
        $who = $own ? 'you' : $name;
        $They = $own ? 'You' : $name;
        $when = $period->isSingleDay() ? ($period->key === 'today' ? 'Today' : ($period->key === 'yesterday' ? 'Yesterday' : 'On ' . $period->from->format('d M Y'))) : 'From ' . $period->from->format('d M') . ' to ' . $period->to->format('d M Y');
        $out = [];
        $add = function (string $text, Collection $ev, string $tone = 'info', array $links = []) use (&$out) {
            $out[] = ['text' => $text, 'evidence' => $ev->take(40)->pluck('id')->values()->all(), 'count' => $ev->count(), 'tone' => $tone, 'links' => $links];
        };

        // 1. sessions
        $logins = $events->where('kind', 'login');
        if ($flags['sessions'] ?? true) {
            if ($logins->isNotEmpty()) {
                $first = $logins->first();
                $t = Str::of(date('g:i A', strtotime($first['at'])))->toString();
                $on = $period->isSingleDay() ? '' : ' on ' . date('d M', strtotime($first['at']));
                $line = $logins->count() > 1
                    ? "{$when}, {$who} signed in to WIMS {$logins->count()} times, the first at {$t}{$on}"
                    : "{$when}, {$who} signed in to WIMS at {$t}{$on}";
                $line .= '.';
                $add($line, $logins);
                $explicit = collect($sessions)->where('end', 'logout');
                $unresolved = collect($sessions)->where('end', 'not_recorded');
                if ($explicit->isNotEmpty()) {
                    $last = $explicit->last();
                    $add(($own ? 'You signed out' : "{$name} signed out") . ' at ' . date('g:i A', strtotime($last['logout_at'])) . ($explicit->count() > 1 ? ' (last of ' . $explicit->count() . ' recorded sign-outs).' : '.'), $events->whereIn('id', $explicit->pluck('logout_id')->all()));
                }
                if ($unresolved->isNotEmpty() && $period->key !== 'today') {
                    $add($unresolved->count() . ' session' . ($unresolved->count() > 1 ? 's have' : ' has') . ' no recorded sign-out, so how ' . ($unresolved->count() > 1 ? 'they' : 'it') . ' ended is not known (it may have expired or the browser was closed).', $events->whereIn('id', $unresolved->pluck('login_id')->all()), 'note');
                }
            } elseif ($events->isEmpty()) {
                $add("WIMS has no recorded activity for {$who} {$this->lc($when)}.", collect(), 'note');
            }
        }
        $failed = $events->where('kind', 'login_failed');
        if (($flags['sensitive'] ?? false) && $failed->isNotEmpty()) {
            $add("There " . ($failed->count() === 1 ? 'was 1 failed sign-in attempt' : 'were ' . $failed->count() . ' failed sign-in attempts') . ($own ? ' on your account' : " on {$name}'s account") . '.', $failed, 'warn');
        }

        // 2. what was done in records (headline counts leave out child rows such as item lines)
        $main = $events->reject(fn ($e) => $e['line'] || in_array($e['kind'], ['login', 'login_failed', 'logout', 'report', 'viewed', 'clock', 'other'], true));
        $parts = [];
        $ev = collect();
        foreach (self::VERBS as $kind => [$verb, $noun]) {
            $group = $main->where('kind', $kind);
            if ($group->isEmpty()) {
                continue;
            }
            $byModule = $group->groupBy('module')->map->count()->sortDesc();
            $detail = $byModule->take(3)->map(fn ($c, $m) => "{$c} in {$m}")->implode(', ') . ($byModule->count() > 3 ? ' and ' . ($byModule->count() - 3) . ' more module' . ($byModule->count() - 3 > 1 ? 's' : '') : '');
            $parts[] = "{$verb} {$group->count()} {$noun}" . ($group->count() === 1 ? '' : 's') . " ({$detail})";
            $ev = $ev->merge($group);
        }
        if ($parts) {
            $add("{$They} " . $this->joinList($parts) . '.', $ev);
        }
        $lines = $events->where('line', true);
        if ($lines->isNotEmpty() && ! ($flags['show_lines'] ?? false)) {
            $add('These records include ' . $lines->count() . ' line entries (items, recipients or attachments) that are listed in the timeline when line entries are shown.', $lines, 'note');
        }

        // 3. other recorded things
        $comments = $events->where('kind', 'comment');
        $clock = $events->where('kind', 'clock');
        $reports = $events->where('kind', 'report');
        $bits = [];
        $bitEv = collect();
        if ($comments->isNotEmpty()) { $bits[] = 'added ' . $comments->count() . ' comment' . ($comments->count() > 1 ? 's' : ''); $bitEv = $bitEv->merge($comments); }
        if ($clock->isNotEmpty()) { $bits[] = 'recorded ' . $clock->count() . ' attendance clock event' . ($clock->count() > 1 ? 's' : ''); $bitEv = $bitEv->merge($clock); }
        if ($reports->isNotEmpty()) { $bits[] = 'printed or downloaded ' . $reports->count() . ' report' . ($reports->count() > 1 ? 's' : '') . ' or document' . ($reports->count() > 1 ? 's' : ''); $bitEv = $bitEv->merge($reports); }
        if ($bits) {
            $add("{$They} " . $this->joinList($bits) . '.', $bitEv);
        }
        if ($main->isEmpty() && ! $bits && $logins->isNotEmpty()) {
            $add(($own ? 'You' : $name) . ' signed in, but no record changes or other recorded actions appear for this period.', collect(), 'note');
        }

        // 4. what is waiting
        if ($pending) {
            $segments = [];
            foreach ($pending as $p) {
                $segments[] = $p['count'] . ' ' . Str::plural($p['noun'], $p['count']) . ' ' . $p['state'] . ($p['overdue'] ? " ({$p['overdue']} overdue)" : '');
            }
            // each waiting item opens its list (new tab in the page)
            $links = collect($pending)->filter(fn ($p) => ! empty($p['url']))->map(fn ($p) => ['label' => $p['count'] . ' ' . Str::plural($p['noun'], $p['count']), 'url' => $p['url']])->values()->all();
            $add(($own ? 'You have' : "{$name} has") . ' right now: ' . $this->joinList($segments) . '.', collect(), collect($pending)->sum('overdue') > 0 ? 'warn' : 'info', $links);
        }

        return ['sentences' => $out, 'text' => collect($out)->pluck('text')->implode(' ')];
    }

    private function joinList(array $parts): string
    {
        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }
        $last = array_pop($parts);

        return implode(', ', $parts) . ' and ' . $last;
    }

    private function lc(string $s): string
    {
        return Str::lower($s);
    }
}
