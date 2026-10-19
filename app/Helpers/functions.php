<?php

// Get Settings
if (! function_exists('get_settings')) {
    function get_settings($key = null)
    {
        // Memoized per request — this is called multiple times per request
        // (HandleInertiaRequests, Language, SystemLockdown middleware all
        // call it independently), and used to re-query the settings table
        // every single call instead of once.
        static $all = null;

        if ($all === null) {
            $all = \App\Models\Setting::all()->pluck('tec_value', 'tec_key')->merge(['baseUrl' => url('/')]);
        }

        return $key ? $all->get($key) : $all;
    }
}

// Get account id
if (! function_exists('getAccountId')) {
    function getAccountId($account_id = null)
    {
        return session('account_id', $account_id ?? optional(auth()->user())->account_id);
    }
}

// Log Activity
if (! function_exists('log_activity')) {
    function log_activity($activity, $properties = null, $model = null, $name = null)
    {
        $logger = activity($name);
        // a log about no particular record (a report was opened, a setting changed) has no subject; Spatie's performedOn() rejects null
        if ($model instanceof \Illuminate\Database\Eloquent\Model) {
            $logger->performedOn($model);
        }

        return $logger->withProperties($properties)->log($activity);
    }
}

// Wraps a LengthAwarePaginator into the {data, links, meta} shape
// Shared/Pagination.vue actually expects (meta.total, meta.links, etc.) —
// the same shape Laravel's ResourceCollection produces automatically, but
// built by hand here since these pages paginate a manually-filtered PHP
// collection (computed accessors involved) rather than a plain Eloquent
// ->paginate() a Resource class could wrap directly. Passing a raw
// paginator straight to Inertia::render() serializes FLAT (meta.total is
// undefined), which is exactly what silently broke this component before.
if (! function_exists('paginated_response')) {
    function paginated_response(\Illuminate\Pagination\LengthAwarePaginator $paginator)
    {
        return [
            'data' => $paginator->items(),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'links' => $paginator->linkCollection()->toArray(),
                'path' => $paginator->path(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ];
    }
}

// Format Decimal
if (! function_exists('formatDecimal')) {
    function formatDecimal($number, $decimals = 4, $ds = '.', $ts = '')
    {
        return number_format($number, $decimals, $ds, $ts);
    }
}

// Format Number
if (! function_exists('formatNumber')) {
    function formatNumber($number, $decimals = 2, $ds = '.', $ts = ',')
    {
        return number_format($number, $decimals, $ds, $ts);
    }
}

// check if demo enabled
if (! function_exists('demo')) {
    function demo()
    {
        return env('DEMO', false) || env('WINDOWS', false);
    }
}

// check if desktop version enabled
if (! function_exists('desktopMachine')) {
    function desktopMachine()
    {
        return env('DESKTOPMACHINE', false);
    }
}

// check if demo is not enabled
if (! function_exists('notDemo')) {
    function notDemo()
    {
        return ! demo();
    }
}

// Json translation with choice replace
if (! function_exists('__choice')) {
    function __choice($key, array $replace = [], $number = null)
    {
        return trans_choice($key, $number, $replace);
    }
}

// Get UUID v1
if (! function_exists('uuid1')) {
    function uuid1()
    {
        $nodeProvider = new \Ramsey\Uuid\Provider\Node\RandomNodeProvider();

        return \Ramsey\Uuid\Uuid::uuid1($nodeProvider->getNode());
    }
}

// Get UUID v4
if (! function_exists('uuid4')) {
    function uuid4()
    {
        return \Ramsey\Uuid\Uuid::uuid4();
    }
}

// Get ULID
if (! function_exists('ulid')) {
    function ulid()
    {
        return (string) \Ulid\Ulid::generate(true);
    }
}

// Get get next id
if (! function_exists('get_next_id')) {
    function get_next_id($model)
    {
        return collect(\Illuminate\Support\Facades\DB::select("show table status like '{$model->getTable()}'"))->first()->Auto_increment;
    }
}

// Get reference
if (! function_exists('get_reference')) {
    function get_reference($model)
    {
        $format = get_settings('reference');

        return match ($format) {
            'ai'     => get_next_id($model),
            'ulid'   => ulid(),
            'uuid'   => uuid4(),
            'uniqid' => uniqid(),
            default  => ulid(),
        };
    }
}

// The warehouse currently selected in the top bar (active working context).
// Returns null when "All Warehouses" is active (no single-warehouse scoping).
if (! function_exists('active_warehouse_id')) {
    function active_warehouse_id()
    {
        $id = session('warehouse_id');

        return $id ? (int) $id : null;
    }
}

// All warehouse IDs the current user may access (the ones assigned to them).
// Returns null = unconstrained (admins without an explicit assignment see every warehouse).
if (! function_exists('accessible_warehouse_ids')) {
    function accessible_warehouse_ids()
    {
        static $computed = false;
        static $value = null;
        if ($computed) {
            return $value;
        }
        $computed = true;

        $user = auth()->user();
        if (! $user) {
            return $value = null;
        }

        $assigned = $user->warehouses()->pluck('warehouses.id')->all();
        if (! empty($assigned)) {
            return $value = $assigned;
        }

        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return $value = null;
        }

        return $value = ($user->warehouse_id ? [$user->warehouse_id] : null);
    }
}

// Warehouse IDs to scope listings by: the one selected in the top bar, or — when
// "All Warehouses" is active — every warehouse the user is assigned to. Null = no scope.
if (! function_exists('warehouse_scope_ids')) {
    function warehouse_scope_ids()
    {
        // Memoized per request — called from ~18 controllers' index() methods,
        // and without this its `exists()` check re-queried the DB on every
        // single call rather than once.
        static $computed = false;
        static $value = null;
        if ($computed) {
            return $value;
        }
        $computed = true;

        $selected = active_warehouse_id();
        $accessible = accessible_warehouse_ids();

        if ($selected) {
            if (is_array($accessible) && ! in_array($selected, $accessible, true)) {
                return $value = $accessible;
            }

            $exists = \App\Models\Warehouse::ofAccount()->whereKey($selected)->exists();
            if (! $exists) {
                return $value = $accessible;
            }

            return $value = [$selected];
        }

        return $value = $accessible;
    }
}

// Parse a User-Agent string into OS, browser and device type
if (! function_exists('user_agent_info')) {
    function user_agent_info($ua)
    {
        $ua = (string) $ua;

        $os = 'Unknown';
        foreach ([
            '/windows nt 10/i'  => 'Windows 10/11',
            '/windows nt 6\.3/i' => 'Windows 8.1',
            '/windows nt 6\.2/i' => 'Windows 8',
            '/windows nt 6\.1/i' => 'Windows 7',
            '/windows/i'        => 'Windows',
            '/iphone/i'         => 'iOS',
            '/ipad/i'           => 'iPadOS',
            '/mac os x/i'       => 'macOS',
            '/macintosh/i'      => 'macOS',
            '/android/i'        => 'Android',
            '/cros/i'           => 'ChromeOS',
            '/linux/i'          => 'Linux',
        ] as $regex => $name) {
            if (preg_match($regex, $ua)) {
                $os = $name;
                break;
            }
        }
        if ($os === 'Android' && preg_match('/android\s([0-9.]+)/i', $ua, $m)) {
            $os = 'Android ' . $m[1];
        }
        if (in_array($os, ['iOS', 'iPadOS'], true) && preg_match('/os\s([0-9_]+)/i', $ua, $m)) {
            $os .= ' ' . str_replace('_', '.', $m[1]);
        }

        $browser = 'Unknown';
        foreach ([
            '/edg/i'        => 'Edge',
            '/opr|opera/i'  => 'Opera',
            '/chrome|crios/i' => 'Chrome',
            '/firefox|fxios/i' => 'Firefox',
            '/safari/i'     => 'Safari',
            '/msie|trident/i' => 'Internet Explorer',
        ] as $regex => $name) {
            if (preg_match($regex, $ua)) {
                $browser = $name;
                break;
            }
        }

        $device = 'Desktop';
        if (preg_match('/iphone/i', $ua)) {
            $device = 'iPhone';
        } elseif (preg_match('/ipad/i', $ua)) {
            $device = 'iPad';
        } elseif (preg_match('/tablet/i', $ua)) {
            $device = 'Tablet';
        } elseif (preg_match('/mobile|android/i', $ua)) {
            $device = 'Mobile';
        }

        return ['os' => $os, 'browser' => $browser, 'device' => $device];
    }
}

// Resolve an approximate location (City, Region, Country) from an IP address
if (! function_exists('geo_from_ip')) {
    function geo_from_ip($ip)
    {
        if (! $ip) {
            return null;
        }

        // Private / reserved addresses can't be geolocated.
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return 'Local network';
        }

        $key = 'geoip:' . $ip;
        $cached = \Illuminate\Support\Facades\Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $res = \Illuminate\Support\Facades\Http::timeout(2)
                ->get("http://ip-api.com/json/{$ip}", ['fields' => 'status,country,regionName,city']);

            if ($res->ok() && $res->json('status') === 'success') {
                $location = collect([$res->json('city'), $res->json('regionName'), $res->json('country')])
                    ->filter()->implode(', ');
                if ($location) {
                    \Illuminate\Support\Facades\Cache::put($key, $location, now()->addDays(30));

                    return $location;
                }
            }
        } catch (\Throwable $e) {
            // Network/geo lookup failed — leave location unknown, retry next time.
        }

        return null;
    }
}

// Resolve a human-readable place name from GPS coordinates (OpenStreetMap's
// free Nominatim API — no key required). Best-effort only: the raw lat/lng
// always remain the authoritative record (spec: never make external
// geocoding mandatory for core attendance functionality) — this just adds a
// friendlier label alongside them when the lookup succeeds.
if (! function_exists('reverse_geocode')) {
    function reverse_geocode($latitude, $longitude)
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }

        $key = 'reverse-geocode:' . round((float) $latitude, 4) . ',' . round((float) $longitude, 4);
        $cached = \Illuminate\Support\Facades\Cache::get($key);
        if ($cached !== null) {
            return $cached ?: null;
        }

        try {
            $res = \Illuminate\Support\Facades\Http::timeout(3)
                ->withHeaders(['User-Agent' => 'RANS-WIMS/1.0 (Digital Attendance)'])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'format' => 'jsonv2',
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'zoom' => 16,
                ]);

            if ($res->ok() && $res->json('address')) {
                $address = $res->json('address');
                $label = collect([
                    $address['suburb'] ?? $address['neighbourhood'] ?? $address['road'] ?? null,
                    $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['county'] ?? null,
                    $address['state'] ?? $address['country'] ?? null,
                ])->filter()->unique()->implode(', ');

                \Illuminate\Support\Facades\Cache::put($key, $label ?: '', now()->addDays(30));

                return $label ?: null;
            }

            \Illuminate\Support\Facades\Cache::put($key, '', now()->addHours(6));
        } catch (\Throwable $e) {
            // Network/geocoding failed — leave the label unknown, retry next time.
            // Deliberately not cached on exception, unlike a clean "no result".
        }

        return null;
    }
}

// Gather request context (IP, location, device, OS) for audit logging
if (! function_exists('request_context')) {
    function request_context()
    {
        $request = request();
        $ip = $request->ip();
        $info = user_agent_info($request->userAgent());

        return [
            'ip'       => $ip,
            'os'       => $info['os'],
            'device'   => trim($info['device'] . ' · ' . $info['browser'], ' ·'),
            'location' => geo_from_ip($ip),
        ];
    }
}

// Convert unit quantity to base quantity
if (! function_exists('convert_to_base_quantity')) {
    function convert_to_base_quantity($quantity, $unit)
    {
        $base_quantity = $quantity;
        if ($unit && $unit->operator) {
            switch ($unit->operator) {
                case '*':
                    $base_quantity = $quantity * $unit->operation_value;
                    break;
                case '/':
                    $base_quantity = $quantity / $unit->operation_value;
                    break;
                case '+':
                    $base_quantity = $quantity + $unit->operation_value;
                    break;
                case '-':
                    $base_quantity = $quantity - $unit->operation_value;
                    break;
                default:
                    $base_quantity = $quantity;
            }
        }

        return $base_quantity;
    }
}

// Digital Attendance settings merged over sane defaults — stored in the
// generic Setting EAV table (tec_key/tec_value) under 'digital_attendance_*'
// keys, so no dedicated settings table/migration is needed.
if (! function_exists('digital_attendance_settings')) {
    function digital_attendance_settings($accountId = null)
    {
        $accountId = $accountId ?? getAccountId();

        $defaults = [
            'digital_attendance_enabled' => '1',
            'digital_attendance_location_capture' => '1',
            'digital_attendance_require_location' => '0',
            'digital_attendance_geofencing' => '0',
            'digital_attendance_geofence_latitude' => null,
            'digital_attendance_geofence_longitude' => null,
            'digital_attendance_geofence_radius' => '100',
            'digital_attendance_geofence_mode' => 'warning',
            'digital_attendance_allow_remote' => '1',
            'digital_attendance_working_start' => '08:00',
            'digital_attendance_working_end' => '17:00',
            'digital_attendance_late_threshold_minutes' => '15',
            'digital_attendance_require_clock_out' => '1',
        ];

        $stored = \App\Models\Setting::query()
            ->where('account_id', $accountId)
            ->whereIn('tec_key', array_keys($defaults))
            ->pluck('tec_value', 'tec_key');

        $merged = array_merge($defaults, $stored->all());

        foreach (['digital_attendance_enabled', 'digital_attendance_location_capture', 'digital_attendance_require_location', 'digital_attendance_geofencing', 'digital_attendance_allow_remote', 'digital_attendance_require_clock_out'] as $bool) {
            $merged[$bool] = (bool) (int) $merged[$bool];
        }
        $merged['digital_attendance_geofence_radius'] = (int) $merged['digital_attendance_geofence_radius'];
        $merged['digital_attendance_late_threshold_minutes'] = (int) $merged['digital_attendance_late_threshold_minutes'];
        $merged['digital_attendance_geofence_latitude'] = $merged['digital_attendance_geofence_latitude'] !== null && $merged['digital_attendance_geofence_latitude'] !== '' ? (float) $merged['digital_attendance_geofence_latitude'] : null;
        $merged['digital_attendance_geofence_longitude'] = $merged['digital_attendance_geofence_longitude'] !== null && $merged['digital_attendance_geofence_longitude'] !== '' ? (float) $merged['digital_attendance_geofence_longitude'] : null;

        return $merged;
    }
}

if (! function_exists('transport_settings')) {
    /**
     * The Transport Routes module's configurable default per-trip
     * price (starts at TZS 75,000 unless overridden via Transport
     * Settings) — mirrors digital_attendance_settings()'s own
     * defaults-merged-with-stored convention, reusing the generic
     * Setting key/value model rather than a dedicated settings table.
     */
    function transport_settings($accountId = null)
    {
        $accountId = $accountId ?? getAccountId();

        $defaults = [
            'transport_default_price' => '75000',
            // What one day of loading / unloading work earns (Transport Routes > Loading & Unloading).
            'transport_loading_daily_rate' => '15000',
        ];

        $stored = \App\Models\Setting::query()
            ->where('account_id', $accountId)
            ->whereIn('tec_key', array_keys($defaults))
            ->pluck('tec_value', 'tec_key');

        $merged = array_merge($defaults, $stored->all());
        $merged['transport_default_price'] = (float) $merged['transport_default_price'];
        $merged['transport_loading_daily_rate'] = (float) $merged['transport_loading_daily_rate'];

        return $merged;
    }
}

if (! function_exists('terms_sections')) {
    /**
     * Splits the Terms & Conditions content (plain text with "## Heading"
     * lines marking each section — the same lightweight convention
     * Terms/Show.vue parses client-side) into title/body pairs for the
     * print/PDF view, which has no access to the frontend's JS parser.
     * Inline **bold** is converted to <strong> on the already-escaped body,
     * so admin-authored content can't inject HTML.
     */
    function terms_sections(string $content): array
    {
        $blocks = preg_split('/^##\s+/m', trim($content));
        $sections = [];

        foreach ($blocks as $block) {
            if (trim($block) === '') {
                continue;
            }

            [$title, $body] = array_pad(explode("\n", trim($block), 2), 2, '');
            $body = nl2br(preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', e(trim($body))));

            $sections[] = ['title' => trim($title), 'body' => $body];
        }

        return $sections;
    }
}
