<?php

namespace App\Modules\Ai\Contracts;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Anthropic Claude driver (Sprint 9). Config-gated and DISABLED unless an API
 * key is configured (config/ai.php ← env), so no external call happens by
 * default. The key is read from config-from-env and never exposed to the client.
 * Read-only summarization only — the AI never performs or triggers any action.
 */
class AnthropicAiProvider implements AiProvider
{
    /** @param array{api_key:?string, model:string, base_url:string, version:string, max_tokens:int, timeout:int} $config */
    public function __construct(private readonly array $config) {}

    public function identifier(): string
    {
        return 'anthropic';
    }

    public function isEnabled(): bool
    {
        return is_string($this->config['api_key'] ?? null) && $this->config['api_key'] !== '';
    }

    public function complete(AiRequest $request): AiResponse
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('Anthropic AI provider is not configured.');
        }

        // Separate the system instruction from the conversation turns.
        $system = null;
        $messages = [];
        foreach ($request->messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = (string) ($message['content'] ?? '');
            if ($role === 'system') {
                $system = $system === null ? $content : $system."\n\n".$content;

                continue;
            }
            $messages[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => $content];
        }

        $payload = [
            'model' => $this->config['model'],
            'max_tokens' => (int) $this->config['max_tokens'],
            'messages' => $messages,
        ];
        if ($system !== null) {
            $payload['system'] = $system;
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->config['api_key'],
            'anthropic-version' => $this->config['version'],
            'content-type' => 'application/json',
        ])
            ->timeout((int) $this->config['timeout'])
            ->retry(1, 250, throw: false)
            ->post(rtrim($this->config['base_url'], '/').'/v1/messages', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Anthropic AI request failed with status '.$response->status());
        }

        $body = $response->json();

        // Concatenate text blocks; ignore any non-text (e.g. thinking) blocks.
        $text = '';
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return new AiResponse($text, [
            'model' => $body['model'] ?? $this->config['model'],
            'input_tokens' => (int) ($body['usage']['input_tokens'] ?? 0),
            'output_tokens' => (int) ($body['usage']['output_tokens'] ?? 0),
            'stop_reason' => $body['stop_reason'] ?? null,
        ]);
    }
}
