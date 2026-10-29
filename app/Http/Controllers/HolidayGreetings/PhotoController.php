<?php

namespace App\Http\Controllers\HolidayGreetings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HolidayGreetings\Concerns\Guards;
use App\Services\HolidayGreetings\GreetingThemes;
use App\Services\HolidayGreetings\HolidayPhotos;
use Illuminate\Http\Request;

/** The photo behind the hero of a template's email: upload your own, put the WIMS photo back, or have none. It applies to the template in every year. */
class PhotoController extends Controller
{
    use Guards;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function theme(string $theme): string
    {
        abort_unless(array_key_exists($theme, GreetingThemes::all()), 404);

        return $theme;
    }

    public function store(Request $request, string $theme)
    {
        $this->need('manage-holiday-photos');
        $theme = $this->theme($theme);
        $request->validate(['photo' => 'required|file|mimes:jpg,jpeg,png,webp|max:8192'], [
            'photo.required' => __('Choose a picture.'), 'photo.mimes' => __('Choose a JPG, PNG or WEBP picture.'), 'photo.max' => __('The picture can be up to 8 MB.'), 'photo.uploaded' => __('The picture could not be uploaded. Keep it under 8 MB.')]);
        $row = HolidayPhotos::store((int) $request->user()->account_id, $theme, $request->file('photo'), $request->user()->id);
        log_activity('Holiday email photo changed', ['template' => GreetingThemes::get($theme)['label'], 'file' => $row->original_name], null, 'Holiday Greetings');

        return back()->with('message', __('The photo of :t was changed. It is used in every email of this template.', ['t' => GreetingThemes::get($theme)['label']]));
    }

    public function reset(Request $request, string $theme)
    {
        $this->need('manage-holiday-photos');
        $theme = $this->theme($theme);
        HolidayPhotos::reset((int) $request->user()->account_id, $theme);
        log_activity('Holiday email photo put back to the WIMS photo', ['template' => GreetingThemes::get($theme)['label']], null, 'Holiday Greetings');

        return back()->with('message', __('The WIMS photo of :t was put back.', ['t' => GreetingThemes::get($theme)['label']]));
    }

    public function off(Request $request, string $theme)
    {
        $this->need('manage-holiday-photos');
        $theme = $this->theme($theme);
        HolidayPhotos::disable((int) $request->user()->account_id, $theme, $request->user()->id);
        log_activity('Holiday email photo switched off', ['template' => GreetingThemes::get($theme)['label']], null, 'Holiday Greetings');

        return back()->with('message', __('The emails of :t will have no photo.', ['t' => GreetingThemes::get($theme)['label']]));
    }

    /** The photo cards of every template, for the pages that show them. */
    public static function cards(int $accountId, ?string $only = null): array
    {
        $out = [];
        foreach (GreetingThemes::all() as $key => $t) {
            if ($only !== null && $only !== $key) {
                continue;
            }
            $src = HolidayPhotos::source($accountId, $key);
            $out[] = ['theme' => $key, 'label' => $t['label'], 'kind' => $src['kind'], 'thumb' => HolidayPhotos::thumb($accountId, $key)];
        }

        return $only !== null ? ($out[0] ?? null) : $out;
    }
}
