<?php

namespace App\Services\SmartBrief;

use App\Services\AiAssistantService;
use App\Services\FeatureManager;
use ReflectionClassConstant;

/**
 * Optional AI wording of the already-verified summary, through the existing ARIS assistant (no second AI subsystem). It may only rephrase the
 * sentences it is given, which were built from records the viewer is allowed to see; it can never add facts. It is OFF unless the Smart Brief
 * AI function is on in Global Feature Control AND the Smart Brief setting is on AND ARIS is available. While ARIS is in its training mode (as
 * it is today) this reports "not available" and the deterministic summary is used. Nothing here is needed for a correct brief.
 */
class AiPolish
{
    public static function available(array $settings): bool
    {
        if (! FeatureManager::isEnabled('smart_brief_ai') || empty($settings['ai_enabled'])) {
            return false;
        }
        try {
            $training = (new ReflectionClassConstant(AiAssistantService::class, 'TRAINING_MODE'))->getValue();
        } catch (\Throwable) {
            return false;
        }

        return ! $training && filled(config('services.anthropic.key') ?? env('ANTHROPIC_API_KEY'));
    }

    /** Returns reworded text, or null when AI is not available (the caller keeps the verified text). */
    public static function polish(string $verifiedText, array $settings): ?string
    {
        if (! self::available($settings)) {
            return null;
        }

        // ARIS answers questions with tools and does not expose a plain "rewrite this" call yet; until it does, keep the verified wording.
        return null;
    }
}
