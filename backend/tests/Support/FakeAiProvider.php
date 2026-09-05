<?php

namespace Tests\Support;

use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\Contracts\AiRequest;
use App\Modules\Ai\Contracts\AiResponse;
use RuntimeException;

/**
 * Test double for the AI provider — never contacts a real service. Configurable
 * enabled flag, canned content (or a thrown failure), and it captures the last
 * request so tests can assert the prompt contains no sensitive fields.
 */
class FakeAiProvider implements AiProvider
{
    public ?AiRequest $lastRequest = null;

    public function __construct(
        private readonly bool $enabled = true,
        private readonly string $content = '{"summary":"Fake summary.","highlights":["h1","h2"]}',
        private readonly bool $throw = false,
        private readonly array $meta = ['model' => 'fake-model', 'input_tokens' => 12, 'output_tokens' => 8],
    ) {}

    public function identifier(): string
    {
        return 'fake';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->lastRequest = $request;
        if ($this->throw) {
            throw new RuntimeException('fake provider failure');
        }

        return new AiResponse($this->content, $this->meta);
    }
}
