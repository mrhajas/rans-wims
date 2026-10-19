<?php

namespace App\Support\Wimshow;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Content loader for the public knowledge base (/wimshow). Every guide is a PHP array in resources/knowledge/<slug>.php
 * (schema: see resources/knowledge/_README.md); navigation groups live in resources/knowledge/_groups.php.
 * The merged result is cached in a PHP file keyed on the files' modification times, so editing a guide shows up immediately
 * and normal requests cost one opcache-friendly include.
 */
class Knowledge
{
    /** Shared by every instance (inline() resolves cross links through app(), which may build a fresh instance). */
    private static ?array $shared = null;


    public function groups(): Collection
    {
        return collect($this->load()['groups']);
    }

    /** @return Collection<string, array<string, mixed>> keyed by slug, in navigation order */
    public function guides(): Collection
    {
        return collect($this->load()['guides']);
    }

    public function guide(string $slug): ?array
    {
        return $this->load()['guides'][$slug] ?? null;
    }

    /** Guides of one group. */
    public function inGroup(string $group): Collection
    {
        return $this->guides()->where('group', $group)->values();
    }

    /** @return array{0: ?array, 1: ?array} previous and next guide in navigation order */
    public function neighbours(string $slug): array
    {
        $keys = $this->guides()->keys()->all();
        $i = array_search($slug, $keys, true);

        return [
            $i > 0 ? $this->guides()[$keys[$i - 1]] : null,
            $i !== false && $i < count($keys) - 1 ? $this->guides()[$keys[$i + 1]] : null,
        ];
    }

    /** Flat list used by the search box: one entry per guide, function and behind-the-scenes item. */
    public function searchIndex(): array
    {
        return $this->load()['search'];
    }

    public function stats(): array
    {
        $guides = $this->guides();

        return [
            'guides' => $guides->count(),
            'functions' => $guides->sum(fn (array $g): int => count($g['functions'])),
            'shots' => $guides->sum(fn (array $g): int => collect($g['functions'])->sum(fn (array $f): int => count($f['shots']))),
            'behind' => $guides->sum(fn (array $g): int => count($g['behind']) + collect($g['functions'])->sum(fn (array $f): int => count($f['inner']['items'] ?? []))),
        ];
    }

    /** Version string for cache-busting assets and the search index. */
    public function version(): string
    {
        return substr($this->load()['hash'], 0, 10);
    }

    /* ---------------------------------------------------------------- markup */

    /**
     * Safe inline markup for guide text: **bold**, `code`, [[guide]] / [[guide#function|label]] cross links and [label](https://url).
     */
    public static function inline(?string $text): string
    {
        $html = e((string) $text);
        $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);
        $html = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace_callback('/\[\[([a-z0-9-]+)(?:#([a-z0-9-]+))?(?:\|([^\]]+))?\]\]/', function (array $m): string {
            $guide = app(self::class)->guide($m[1]);
            if (! $guide) {
                return e($m[3] ?? $m[1]);
            }
            $url = route('wimshow.show', $m[1]).(! empty($m[2]) ? '#'.$m[2] : '');

            return '<a class="kb-xref" href="'.$url.'">'.($m[3] ?? $guide['title']).'</a>';
        }, $html);
        $html = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $html);

        return $html;
    }

    /** Plain text of guide markup for the search index. Never looks other guides up (that would recurse while the data is being built). */
    public static function plain(?string $text): string
    {
        $text = preg_replace('/\[\[[a-z0-9-]+(?:#[a-z0-9-]+)?\|([^\]]+)\]\]/', '$1', (string) $text);
        $text = preg_replace('/\[\[([a-z0-9-]+)(?:#[a-z0-9-]+)?\]\]/', '$1', $text);
        $text = preg_replace('/\[([^\]]+)\]\(https?:[^)]*\)/', '$1', $text);
        $text = str_replace(['**', '`'], '', $text);

        return trim(preg_replace('/\s+/', ' ', strip_tags($text)));
    }

    /* ---------------------------------------------------------------- loading */

    private function load(): array
    {
        if (self::$shared !== null) {
            return self::$shared;
        }

        $dir = resource_path('knowledge');
        $files = glob($dir.'/*.php') ?: [];
        sort($files);
        $stamp = md5(implode('|', array_map(fn (string $f): string => basename($f).filemtime($f), $files)));
        $cache = storage_path("framework/cache/wimshow-{$stamp}.php");

        if (is_file($cache)) {
            return self::$shared = require $cache;
        }

        $groups = require $dir.'/_groups.php';
        $guides = [];
        foreach ($files as $file) {
            if (str_starts_with(basename($file), '_')) {
                continue;
            }
            $guide = $this->normalise(basename($file, '.php'), require $file, $groups);
            $guides[$guide['slug']] = $guide;
        }

        // navigation order: group order, then the guide's own "order", then title
        $order = array_flip(array_keys($groups));
        uasort($guides, fn (array $a, array $b): int => [$order[$a['group']] ?? 99, $a['order'], $a['title']] <=> [$order[$b['group']] ?? 99, $b['order'], $b['title']]);

        $data = [
            'hash' => $stamp,
            'groups' => collect($groups)->map(fn (array $g, string $slug): array => [...$g, 'slug' => $slug])->all(),
            'guides' => $guides,
            'search' => $this->buildSearch($guides),
        ];

        foreach (glob(storage_path('framework/cache/wimshow-*.php')) ?: [] as $old) {
            @unlink($old);
        }
        file_put_contents($cache, '<?php return '.var_export($data, true).';');

        return self::$shared = $data;
    }

    private function normalise(string $slug, array $g, array $groups): array
    {
        $g['slug'] = $slug;
        $g += ['title' => Str::headline($slug), 'group' => 'other', 'icon' => 'book-open', 'color' => $groups[$g['group'] ?? 'other']['color'] ?? 'indigo', 'summary' => '', 'where' => null,
            'path' => null, 'permissions' => [], 'overview' => [], 'concepts' => [], 'functions' => [], 'behind' => [], 'faq' => [], 'related' => [], 'order' => 50, 'updated' => null];
        $g['overview'] = (array) $g['overview'];
        $g['functions'] = array_values(array_map(function (array $f): array {
            $f += ['id' => Str::slug($f['title'] ?? 'function'), 'title' => '', 'icon' => 'zap', 'summary' => '', 'steps' => [], 'fields' => [], 'shots' => [], 'tips' => [], 'warnings' => [], 'inner' => []];
            $f['shots'] = array_values(array_map(fn ($s): array => is_array($s) ? ['file' => $s[0], 'caption' => $s[1] ?? '', 'alt' => $s[2] ?? ($s[1] ?? '')] : ['file' => $s, 'caption' => '', 'alt' => ''], (array) $f['shots']));

            return $f;
        }, $g['functions']));

        return $g;
    }

    private function buildSearch(array $guides): array
    {
        $out = [];
        $plain = fn (?string $t): string => self::plain($t);
        foreach ($guides as $slug => $g) {
            $base = route('wimshow.show', $slug, false);
            $out[] = ['t' => $g['title'], 'm' => 'Guide', 'u' => $base, 'i' => $g['icon'], 'c' => $g['color'], 'x' => Str::lower($plain($g['summary'].' '.implode(' ', $g['overview'])))];
            foreach ($g['functions'] as $f) {
                $out[] = ['t' => $f['title'], 'm' => $g['title'], 'u' => $base.'#'.$f['id'], 'i' => $f['icon'], 'c' => $g['color'],
                    'x' => Str::lower($plain($f['summary'].' '.implode(' ', $f['steps']).' '.implode(' ', $f['tips']).' '.implode(' ', $f['inner']['items'] ?? [])))];
            }
            foreach ($g['behind'] as $i => $b) {
                $out[] = ['t' => $b['title'], 'm' => $g['title'].' · behind the scenes', 'u' => $base.'#behind-'.($i + 1), 'i' => $b['icon'] ?? 'zap', 'c' => $g['color'], 'x' => Str::lower($plain($b['text'] ?? ''))];
            }
        }

        return $out;
    }
}
