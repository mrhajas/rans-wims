<?php

namespace App\Services;

use App\Models\User;
use App\Support\AiNativeIntentCatalog;
use Illuminate\Support\Facades\Log;

/**
 * The "native" half of ARIS's two-tier design: tries to answer a question
 * with a plain Eloquent query before ever considering the Anthropic API.
 * See AiNativeIntentCatalog for the pattern -> tool-handler mapping.
 *
 * Every handler this dispatches to is the exact same class AiToolRegistry
 * hands to Claude, so authorization/account/warehouse scoping is already
 * enforced identically on both paths — this is a routing decision, not a
 * separate, weaker query layer.
 */
class AiNativeQueryEngine
{
    /**
     * Returns an ['answer','data','toolUsed','source'] array on a confident
     * match, or null to signal "let Claude handle this one" — a null here
     * is not an error, it's simply "not a recognized native pattern".
     */
    public function answer(string $question, User $user): ?array
    {
        // Patterns match against a clean sentence — trailing punctuation
        // ("...GD1?", "...warehouses.") would otherwise leak into the last
        // captured entity and break the name lookup that follows.
        $question = preg_replace('/[.?!]+$/', '', trim($question));

        if ($question === '') {
            return null;
        }

        foreach (AiNativeIntentCatalog::intents() as $key => $intent) {
            foreach ($intent['patterns'] as $pattern) {
                if (! preg_match($pattern, $question, $matches)) {
                    continue;
                }

                return $this->resolve($key, $intent, $matches, $question, $user);
            }
        }

        return null;
    }

    protected function resolve(string $key, array $intent, array $matches, string $question, User $user): ?array
    {
        $named = collect($matches)
            ->filter(fn ($value, $group) => is_string($group) && $value !== '')
            ->all();

        $input = ($intent['map'])($named, $question);
        $input = array_filter($input, fn ($value) => $value !== null && $value !== '');

        try {
            [$class, $method] = $intent['handler'];
            $result = app($class)->{$method}($input, $user);
        } catch (\Throwable $e) {
            // A native handler failing is not the user's problem — quietly
            // fall through to Claude rather than surfacing a broken answer.
            Log::warning('AiNativeQueryEngine: handler failed, falling back to AI', [
                'intent' => $key,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $text = ($intent['format'])($result);

        if (! $text) {
            return null;
        }

        return [
            'answer' => $text,
            'data' => $result,
            'toolUsed' => $key,
            'source' => 'native',
        ];
    }
}
