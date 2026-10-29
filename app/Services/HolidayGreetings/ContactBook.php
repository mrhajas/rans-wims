<?php

namespace App\Services\HolidayGreetings;

use App\Models\Contact;
use App\Models\HolidayContact;
use App\Models\Staff;
use App\Models\User;
use App\Services\WimsConnect\RecipientDirectory;
use App\Support\TzPhone;
use Illuminate\Validation\ValidationException;

/**
 * The list of people who receive the greetings. Everything that enters the list goes through here, so an email address or a mobile number is never kept twice, a mobile number is
 * always stored as +255XXXXXXXXX (typed without the country code, it is corrected), and only people with an email or a mobile number are imported.
 */
class ContactBook
{
    public const SOURCES = ['users' => 'Users', 'staff' => 'Staff', 'contacts' => 'Contacts'];

    private const TITLES = ['Prof', 'Dr', 'Eng', 'Engr', 'Mr', 'Mrs', 'Ms', 'Miss', 'Hon', 'Sheikh', 'Rev', 'Fr', 'Pastor', 'Capt', 'Arch', 'Qs', 'Hajji', 'Haji', 'Alhaj', 'Mwalimu', 'Ustadh', 'Bi', 'Bw', 'Ndugu'];

    /** Titles offered when somebody is added by hand. */
    public const TITLE_SUGGESTIONS = ['Mr.', 'Mrs.', 'Ms.', 'Miss', 'Dr.', 'Eng.', 'Prof.', 'Hon.', 'Sheikh', 'Rev.', 'Hajji', 'Arch.', 'Ndugu'];

    public static function email(?string $raw): ?string
    {
        $raw = mb_strtolower(trim((string) $raw));

        return ($raw !== '' && filter_var($raw, FILTER_VALIDATE_EMAIL)) ? $raw : null;
    }

    public static function mobile(?string $raw): ?string
    {
        return TzPhone::normalize($raw);
    }

    /** "ENG. AMINA JUMA" => ["Eng.", "Amina Juma"]; "Hon. Dr. Said" keeps both titles; a name with no leading title keeps the title it was given. */
    public static function splitTitle(string $name, ?string $title = null): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        $title = trim((string) $title);
        $t = implode('|', self::TITLES);
        $found = [];
        for ($i = 0; $i < 2; $i++) {
            if (! preg_match('/^(?:(' . $t . ')\.\s*|(' . $t . ')\s+)(.+)$/iu', $name, $m)) {
                break;
            }
            $word = ucfirst(mb_strtolower($m[1] !== '' ? $m[1] : $m[2]));
            $found[] = in_array($word, ['Sheikh', 'Miss', 'Pastor', 'Hajji', 'Haji', 'Alhaj', 'Mwalimu', 'Ustadh', 'Ndugu', 'Bi', 'Bw'], true) ? $word : $word . '.';
            $name = trim($m[3]);
        }
        if ($found && $title === '') {
            $title = implode(' ', $found);
        }
        if ($name !== '' && ! preg_match('/\p{Ll}/u', $name)) {
            $name = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8'); // a name typed in capitals reads warmly in a greeting only in normal case
        }
        $name = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1) : $name;

        return [$title !== '' ? mb_substr($title, 0, 40) : null, mb_substr($name, 0, 190)];
    }

    /** The row that already holds this email or mobile (ignoring one row, so an edit does not clash with itself). */
    public static function find(int $accountId, ?string $email, ?string $mobile, ?int $ignoreId = null): ?HolidayContact
    {
        if (! $email && ! $mobile) {
            return null;
        }

        return HolidayContact::withoutGlobalScope('account')->where('account_id', $accountId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(function ($q) use ($email, $mobile) {
                if ($email) {
                    $q->orWhere('email', $email);
                }
                if ($mobile) {
                    $q->orWhere('mobile', $mobile);
                }
            })->first();
    }

    /**
     * A person typed in (or edited): title, name and at least an email or a mobile number are needed. Returns the cleaned fields; throws a validation error that says what to fix.
     * `mobile_fixed` tells the form that a number was written in full, so the person sees what WIMS understood.
     */
    public static function clean(array $in, int $accountId, ?int $ignoreId = null): array
    {
        $errors = [];
        $title = trim((string) ($in['title'] ?? ''));
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')));
        $rawEmail = trim((string) ($in['email'] ?? ''));
        $rawMobile = trim((string) ($in['mobile'] ?? ''));
        if ($title === '') {
            $errors['title'] = __('Enter the title, for example Mr., Mrs., Dr. or Eng.');
        }
        if ($name === '') {
            $errors['name'] = __('Enter the name.');
        }
        $email = null;
        if ($rawEmail !== '') {
            $email = self::email($rawEmail);
            if (! $email) {
                $errors['email'] = __('That is not a valid email address.');
            }
        }
        $mobile = null;
        if ($rawMobile !== '') {
            $mobile = self::mobile($rawMobile);
            if (! $mobile) {
                $errors['mobile'] = TzPhone::message();
            }
        }
        if ($rawEmail === '' && $rawMobile === '') {
            $errors['email'] = __('Enter an email address or a mobile number (one of them is enough).');
        }
        if (! $errors) {
            $clash = self::find($accountId, $email, $mobile, $ignoreId);
            if ($clash) {
                $key = ($email && $clash->email === $email) ? 'email' : 'mobile';
                $errors[$key] = __(':v is already in the list (:who).', ['v' => $key === 'email' ? $email : $mobile, 'who' => $clash->fullName()]);
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['title' => mb_substr($title, 0, 40), 'name' => mb_substr($name, 0, 190), 'email' => $email, 'mobile' => $mobile, 'mobile_fixed' => $mobile && $mobile !== $rawMobile];
    }

    /**
     * Brings people from users, staff and contacts into the list. With $dryRun nothing is written and the figures say what would happen.
     *
     * @param  array<int,string>  $sources  users | staff | contacts
     * @param  array<int,string>  $categories  contact categories to take (empty = all)
     */
    public static function import(int $accountId, array $sources, array $categories = [], bool $dryRun = false, ?int $byUserId = null): array
    {
        $existing = HolidayContact::withoutGlobalScope('account')->where('account_id', $accountId)->get();
        $byEmail = $existing->filter(fn ($r) => $r->email)->keyBy('email')->all();
        $byMobile = $existing->filter(fn ($r) => $r->mobile)->keyBy('mobile')->all();
        $bySource = $existing->filter(fn ($r) => $r->source_id)->keyBy(fn ($r) => $r->source . ':' . $r->source_id)->all();

        $out = ['added' => 0, 'merged' => 0, 'already' => 0, 'no_contact' => 0, 'bySource' => [], 'sample' => []];
        foreach (self::candidates($accountId, $sources, $categories) as $c) {
            $src = $c['source'];
            $out['bySource'][$src] ??= ['added' => 0, 'already' => 0, 'no_contact' => 0];
            $email = self::email($c['email'] ?? null);
            $mobile = self::mobile($c['mobile'] ?? null);
            if (! $email && ! $mobile) {
                $out['no_contact']++;
                $out['bySource'][$src]['no_contact']++;
                continue;
            }
            [$title, $name] = self::splitTitle((string) $c['name'], $c['title'] ?? null);
            if ($name === '') {
                $out['no_contact']++;
                $out['bySource'][$src]['no_contact']++;
                continue;
            }
            $link = ! empty($c['link']) ? ($bySource[$c['link']] ?? null) : null;
            $row = $link ?? ($email ? ($byEmail[$email] ?? null) : null) ?? ($mobile ? ($byMobile[$mobile] ?? null) : null);

            if ($row) {
                // the person is already in the list: only fill in what is missing, never replace, never repeat
                $fill = [];
                if (! $row->email && $email && ! isset($byEmail[$email])) {
                    $fill['email'] = $email;
                }
                if (! $row->mobile && $mobile && ! isset($byMobile[$mobile])) {
                    $fill['mobile'] = $mobile;
                }
                if (! $row->title && $title) {
                    $fill['title'] = $title;
                }
                if ($fill) {
                    if (! $dryRun) {
                        $row->update($fill);
                    } else {
                        $row->fill($fill);
                    }
                    foreach ($fill as $k => $v) {
                        if ($k === 'email') {
                            $byEmail[$v] = $row;
                        } elseif ($k === 'mobile') {
                            $byMobile[$v] = $row;
                        }
                    }
                    $out['merged']++;
                }
                $out['already']++;
                $out['bySource'][$src]['already']++;
                continue;
            }

            $data = ['account_id' => $accountId, 'title' => $title, 'name' => $name, 'email' => $email, 'mobile' => $mobile, 'source' => $src, 'source_id' => $c['source_id'] ?? null,
                'group_label' => $c['group'] ?? null, 'is_active' => true, 'created_by_user_id' => $byUserId];
            $row = $dryRun ? new HolidayContact($data) : HolidayContact::withoutGlobalScope('account')->create($data);
            if ($email) {
                $byEmail[$email] = $row;
            }
            if ($mobile) {
                $byMobile[$mobile] = $row;
            }
            if (! empty($c['source_id'])) {
                $bySource[$src . ':' . $c['source_id']] = $row;
            }
            $out['added']++;
            $out['bySource'][$src]['added']++;
            if (count($out['sample']) < 6) {
                $out['sample'][] = trim(($title ? $title . ' ' : '') . $name);
            }
        }

        return $out;
    }

    /** @return \Generator<int,array> the people found in the chosen places, in the order users, staff, contacts */
    private static function candidates(int $accountId, array $sources, array $categories)
    {
        if (in_array('users', $sources, true)) {
            $users = User::withoutGlobalScope('account')->where('account_id', $accountId)->where('is_active', true)->whereNull('disabled_at')->with('staff')->orderBy('id')->get();
            foreach ($users as $u) {
                $c = RecipientDirectory::candidates($u);
                yield ['source' => 'user', 'source_id' => $u->id, 'link' => 'user:' . $u->id, 'name' => $u->name, 'email' => $c['email'] ?? $u->email, 'mobile' => $c['sms'] ?? null, 'group' => 'Users'];
            }
        }
        if (in_array('staff', $sources, true)) {
            $staff = Staff::query()->where('status', 'Active')->orderBy('id')->get();
            foreach ($staff as $s) {
                yield ['source' => 'staff', 'source_id' => $s->id, 'link' => $s->user_id ? 'user:' . $s->user_id : 'staff:' . $s->id, 'name' => $s->name, 'email' => $s->personal_email, 'mobile' => $s->mobile_no, 'group' => 'Staff'];
            }
        }
        if (in_array('contacts', $sources, true)) {
            $q = Contact::withoutGlobalScope('account')->where('account_id', $accountId)->where(fn ($w) => $w->whereNotNull('email')->where('email', '!=', '')->orWhere(fn ($x) => $x->whereNotNull('phone')->where('phone', '!=', '')))->orderBy('id');
            if ($categories) {
                $q->whereIn('category', $categories);
            }
            foreach ($q->get() as $c) {
                yield ['source' => 'contact', 'source_id' => $c->id, 'link' => 'contact:' . $c->id, 'name' => $c->name, 'email' => $c->email, 'mobile' => $c->phone, 'group' => $c->category];
            }
        }
    }

    /** The categories that have contacts with an email or a mobile number, with how many. */
    public static function contactCategories(int $accountId): array
    {
        return Contact::withoutGlobalScope('account')->where('account_id', $accountId)->whereNotNull('category')->where(fn ($w) => $w->where(fn ($x) => $x->whereNotNull('email')->where('email', '!=', ''))->orWhere(fn ($x) => $x->whereNotNull('phone')->where('phone', '!=', '')))
            ->selectRaw('category, COUNT(*) n')->groupBy('category')->orderBy('category')->pluck('n', 'category')->all();
    }
}
