<?php

namespace Sisly\Coach\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Sisly\Coach\Exceptions\AnthropicException;

/**
 * Low-level Anthropic API client.
 * All model calls go through here — never from the client side.
 */
class AnthropicService
{
    private Client $http;
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $coachModel,
        private readonly string $safetyModel,
        private readonly int    $maxTokensCoach,
        private readonly int    $maxTokensSafety,
    ) {
        $this->http = new Client([
            'timeout'         => 30,
            'connect_timeout' => 5,
        ]);
    }

    /**
     * Send a coach message request.
     * Returns the raw text from the first text content block.
     *
     * Uses Anthropic prompt caching on the system prefix to reduce cost.
     * The SHARED_SPINE + PERSONA block is identical across turns and is
     * the single biggest token-saving opportunity.
     *
     * @param  array<array{role: string, content: string}>  $messages
     * @throws AnthropicException
     */
    public function coachCompletion(string $systemPrompt, array $messages): string
    {
        return $this->complete(
            model:      $this->coachModel,
            maxTokens:  $this->maxTokensCoach,
            system:     $systemPrompt,
            messages:   $messages,
            cacheSystem: true,
        );
    }

    /**
     * Send a safety classifier request.
     * Runs on the cheaper/faster safety model in parallel with the coach call.
     * Returns raw JSON string — parsing is the caller's responsibility.
     *
     * @param  array<array{role: string, content: string}>  $messages
     * @throws AnthropicException
     */
    public function safetyCompletion(string $systemPrompt, array $messages): string
    {
        return $this->complete(
            model:      $this->safetyModel,
            maxTokens:  $this->maxTokensSafety,
            system:     $systemPrompt,
            messages:   $messages,
            cacheSystem: false,
        );
    }

    /**
     * Core completion method.
     */
    private function complete(
        string $model,
        int    $maxTokens,
        string $system,
        array  $messages,
        bool   $cacheSystem = false,
    ): string {
        $payload = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => $messages,
        ];

        // Prompt caching on the system field — reduces cost on repeated coach turns
        // by telling Anthropic the system prefix doesn't change.
        if ($cacheSystem) {
            $payload['system'] = [
                [
                    'type' => 'text',
                    'text' => $system,
                    'cache_control' => ['type' => 'ephemeral'],
                ]
            ];
        } else {
            $payload['system'] = $system;
        }

        try {
            $response = $this->http->post(self::API_URL, [
                'headers' => [
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'content-type'      => 'application/json',
                    // Required for prompt caching beta
                    'anthropic-beta'    => 'prompt-caching-2024-07-31',
                ],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new AnthropicException(
                "Anthropic API request failed: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }

        $body = json_decode((string) $response->getBody(), true);

        if (! isset($body['content']) || ! is_array($body['content'])) {
            throw new AnthropicException('Anthropic API returned an unexpected response shape.');
        }

        $text = collect($body['content'])
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");

        return trim($text);
    }
}
