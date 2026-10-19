<?php

namespace App\Services;

use App\Models\User;
use App\Support\AiToolRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Two-tier ARIS: first tries AiNativeQueryEngine (plain Eloquent, no LLM,
 * for questions that match a known deterministic pattern), and only calls
 * Claude when that returns no confident match. Every tool result actually
 * shown to the user — on either path — came straight out of an Eloquent
 * query already scoped to their account/warehouse permissions; on the AI
 * path, Claude's only job is picking the right tool and phrasing the
 * result, the same "phrase, don't invent" discipline as
 * AiExecutiveSummaryService.
 */
class AiAssistantService
{
    protected const MAX_TOOL_ROUNDS = 3;

    protected const UNAVAILABLE_ANSWER = 'Sorry, ARIS is temporarily unavailable. Please try again shortly.';

    /**
     * Temporary switch: while true, every question gets the friendly
     * "still training" reply below instead of a real answer — no native
     * query, no Anthropic call. Flip back to false to restore normal
     * answering once ARIS is ready to go live for users.
     */
    protected const TRAINING_MODE = true;

    protected const TRAINING_MODE_ANSWER = "I'm ARIS, and I'm currently in training and learning mode, not broken, just growing! I'm studying how your team works with WIMS so I can become a genuinely great intelligence assistant for you. In the meantime, please keep using WIMS as usual, and I'll be back soon with the skills to help you with all kinds of questions and issues. Thank you for your patience!";

    public function __construct(protected AiNativeQueryEngine $nativeEngine)
    {
    }

    public function ask(string $question, User $user, array $recentHistory = []): array
    {
        if (self::TRAINING_MODE) {
            return [
                'answer' => self::TRAINING_MODE_ANSWER,
                'data' => null,
                'toolUsed' => null,
                'source' => 'training',
            ];
        }

        if ($native = $this->nativeEngine->answer($question, $user)) {
            return $native;
        }

        $apiKey = config('services.anthropic.key');

        if (! $apiKey) {
            Log::error('AiAssistantService: ANTHROPIC_API_KEY not configured');

            return ['answer' => self::UNAVAILABLE_ANSWER, 'data' => null, 'toolUsed' => null, 'source' => 'ai'];
        }

        $messages = $this->buildInitialMessages($recentHistory, $question);
        $lastToolResult = null;
        $lastToolKey = null;

        try {
            for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
                $response = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                    ->timeout(30)
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => config('services.anthropic.model'),
                        'max_tokens' => 1024,
                        'system' => $this->buildSystemPrompt($user),
                        'tools' => AiToolRegistry::forClaude(),
                        'messages' => $messages,
                    ]);

                if (! $response->successful()) {
                    Log::error('AiAssistantService: Anthropic API error', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return ['answer' => self::UNAVAILABLE_ANSWER, 'data' => null, 'toolUsed' => null, 'source' => 'ai'];
                }

                $body = $response->json();
                $content = $body['content'] ?? [];
                $stopReason = $body['stop_reason'] ?? null;

                if ($stopReason !== 'tool_use') {
                    $text = collect($content)->firstWhere('type', 'text')['text'] ?? '';

                    return [
                        'answer' => trim($text) ?: "I wasn't able to find an answer to that.",
                        'data' => $lastToolResult,
                        'toolUsed' => $lastToolKey,
                        'source' => 'ai',
                    ];
                }

                $messages[] = ['role' => 'assistant', 'content' => $content];

                $toolResults = [];
                foreach ($content as $block) {
                    if (($block['type'] ?? null) !== 'tool_use') {
                        continue;
                    }

                    $toolKey = $block['name'];
                    $input = $block['input'] ?? [];
                    $result = $this->executeTool($toolKey, $input, $user);

                    $lastToolResult = $result;
                    $lastToolKey = $toolKey;

                    $toolResults[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $block['id'],
                        'content' => json_encode($result),
                    ];
                }

                $messages[] = ['role' => 'user', 'content' => $toolResults];
            }

            return [
                'answer' => "I wasn't able to reach a final answer to that, try rephrasing your question.",
                'data' => $lastToolResult,
                'toolUsed' => $lastToolKey,
                'source' => 'ai',
            ];
        } catch (\Throwable $e) {
            Log::error('AiAssistantService: failed to answer question', ['error' => $e->getMessage()]);

            return ['answer' => self::UNAVAILABLE_ANSWER, 'data' => null, 'toolUsed' => null, 'source' => 'ai'];
        }
    }

    protected function buildInitialMessages(array $recentHistory, string $question): array
    {
        $messages = [];

        foreach (array_slice($recentHistory, -3) as $turn) {
            if (! empty($turn['question'])) {
                $messages[] = ['role' => 'user', 'content' => (string) $turn['question']];
            }
            if (! empty($turn['answer'])) {
                $messages[] = ['role' => 'assistant', 'content' => (string) $turn['answer']];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $question];

        return $messages;
    }

    protected function buildSystemPrompt(User $user): string
    {
        $today = now()->format('Y-m-d (l)');
        $company = optional($user->account)->name ?? 'the company';

        return <<<PROMPT
            You are ARIS (Adaptive RANS Intelligence System), the assistant built into {$company}'s WIMS construction and warehouse management system. You answer questions about their live inventory, warehouse activity, and project data for {$user->name} ({$user->getRoleNames()->first()}) on {$today}.

            You may only answer using the tools provided, never invent, estimate, or guess a number, date, or name. If a tool returns "found: false" or no matching record, say so plainly rather than guessing. If a tool returns an "error: not_permitted" result, tell the user they don't have visibility into that data rather than making anything up.

            If a question doesn't map to any available tool, say so and briefly suggest what kinds of questions you can answer instead of attempting to answer from general knowledge.

            Keep answers short, direct, and in plain business language, a sentence or two, not a report. Use the exact figures/names the tool returned.
            PROMPT;
    }

    protected function executeTool(string $toolKey, array $input, User $user): array
    {
        $handler = AiToolRegistry::handlerFor($toolKey);

        if (! $handler) {
            return ['error' => 'unknown_tool'];
        }

        try {
            [$class, $method] = $handler;

            return app($class)->{$method}($input, $user);
        } catch (\Throwable $e) {
            Log::error('AiAssistantService: tool execution failed', [
                'tool' => $toolKey,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'tool_failed'];
        }
    }
}
