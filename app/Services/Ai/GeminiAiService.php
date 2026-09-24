<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GeminiAiService
{
    private string $apiKey;
    private string $primaryModel;
    private array $fallbackModels;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = (string) (config('gemini.api_key') ?: env('GEMINI_API_KEY'));
        $this->primaryModel = (string) (config('gemini.model') ?: env('GEMINI_MODEL') ?: 'gemini-3.6-flash');
        $this->fallbackModels = (array) config('gemini.fallback_models', [
            'gemini-3.6-flash',
            'gemini-3.7-flash',
            'gemini-3.5-flash-lite',
            'gemini-3.8-flash',
        ]);
        $this->timeout = max(30, (int) config('gemini.timeout', 90));
    }

    /**
     * Generate structured JSON content using Google Gemini (with automatic model fallback).
     */
    public function generateStructuredJson(string $instructions, array|string $userContext, array $options = []): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Gemini API key is not configured. Please add GEMINI_API_KEY to your .env file.');
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit($this->timeout + 20);
        }

        $userText = is_string($userContext)
            ? $userContext
            : json_encode($userContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $modelsToTry = array_values(array_unique(array_merge([$this->primaryModel], $this->fallbackModels)));
        $lastError = null;

        foreach ($modelsToTry as $model) {
            $attempts = 0;
            while ($attempts < 2) {
                $attempts++;
                try {
                    $response = $this->callGeminiApi($model, $instructions, $userText, true, $options);

                    if ($response->successful()) {
                        $body = $response->json();
                        $rawText = $this->extractCandidateText($body);
                        $cleanJson = $this->cleanJsonString($rawText);
                        $parsed = json_decode($cleanJson, true);

                        if (is_array($parsed)) {
                            $usage = $this->extractUsage($body, $model);
                            return [
                                'data' => $parsed,
                                'model' => $model,
                                'usage' => $usage,
                            ];
                        }

                        Log::warning("Gemini model {$model} returned unparseable JSON: " . substr($rawText, 0, 300));
                        break;
                    } else {
                        $errorMsg = $response->json('error.message') ?: $response->body();
                        $statusCode = $response->status();
                        Log::warning("Gemini model {$model} returned HTTP {$statusCode}: {$errorMsg}");
                        $lastError = $errorMsg;

                        if (in_array($statusCode, [503, 429], true) && $attempts < 2) {
                            usleep(1200000); // 1.2s retry on temporary Google demand spikes
                            continue;
                        }
                        break;
                    }
                } catch (Throwable $e) {
                    Log::warning("Gemini exception on model {$model}: " . $e->getMessage());
                    $lastError = $e->getMessage();
                    break;
                }
            }
        }

        // Optional fallback to OpenAI if configured and available
        $openAiKey = config('openai.api_key');
        if ($openAiKey && str_starts_with($openAiKey, 'sk-')) {
            Log::info('Attempting fallback to OpenAI API...');
            return $this->fallbackOpenAiJson($instructions, $userText, $options);
        }

        throw new RuntimeException($lastError ?: 'Unable to generate AI content at this time. Please try again in a few moments.');
    }

    /**
     * Generate multi-turn conversation text (for Sarah AI assistant).
     */
    public function generateConversation(string $systemPrompt, array $messages, array $options = []): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Gemini API key is not configured.');
        }

        $modelsToTry = array_values(array_unique(array_merge([$this->primaryModel], $this->fallbackModels)));
        $lastError = null;

        $geminiContents = [];
        foreach ($messages as $msg) {
            $role = in_array($msg['role'] ?? '', ['user', 'customer'], true) ? 'user' : 'model';
            $text = trim((string) ($msg['content'] ?? ''));
            if ($text !== '') {
                $geminiContents[] = [
                    'role' => $role,
                    'parts' => [['text' => $text]],
                ];
            }
        }

        if (empty($geminiContents)) {
            $geminiContents[] = [
                'role' => 'user',
                'parts' => [['text' => 'Hello!']],
            ];
        }

        foreach ($modelsToTry as $model) {
            try {
                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$this->apiKey}";
                $payload = [
                    'systemInstruction' => [
                        'parts' => [['text' => $systemPrompt]],
                    ],
                    'contents' => $geminiContents,
                    'generationConfig' => [
                        'temperature' => (float) ($options['temperature'] ?? 0.70),
                        'maxOutputTokens' => (int) ($options['max_tokens'] ?? 1200),
                    ],
                ];

                $response = Http::timeout($this->timeout)
                    ->acceptJson()
                    ->post($url, $payload);

                if ($response->successful()) {
                    $body = $response->json();
                    $replyText = $this->extractCandidateText($body);
                    if ($replyText !== '') {
                        return [
                            'reply' => $replyText,
                            'model' => $model,
                            'usage' => $this->extractUsage($body, $model),
                        ];
                    }
                }
            } catch (Throwable $e) {
                Log::warning("Gemini conversation error with {$model}: " . $e->getMessage());
                $lastError = $e->getMessage();
            }
        }

        throw new RuntimeException($lastError ?: 'AI assistant unavailable at the moment.');
    }

    private function callGeminiApi(string $model, string $instructions, string $userText, bool $jsonMode = true, array $options = []): \Illuminate\Http\Client\Response
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$this->apiKey}";

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $instructions]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $userText]],
                ],
            ],
            'generationConfig' => array_filter([
                'temperature' => (float) ($options['temperature'] ?? 0.7),
                'responseMimeType' => $jsonMode ? 'application/json' : null,
                'maxOutputTokens' => (int) ($options['max_tokens'] ?? 8192),
            ]),
        ];

        return Http::timeout($this->timeout)
            ->acceptJson()
            ->post($url, $payload);
    }

    private function extractCandidateText(array $body): string
    {
        $candidate = $body['candidates'][0] ?? null;
        if (! $candidate) {
            return '';
        }

        $parts = $candidate['content']['parts'] ?? [];
        $textParts = [];
        foreach ($parts as $part) {
            if (! empty($part['text'])) {
                $textParts[] = $part['text'];
            }
        }

        return trim(implode("\n", $textParts));
    }

    private function cleanJsonString(string $raw): string
    {
        $cleaned = trim($raw);

        // Strip ```json ... ``` code fences if present
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $cleaned, $matches)) {
            $cleaned = trim($matches[1]);
        }

        // If not valid JSON yet, find outermost { ... } or [ ... ]
        $decoded = json_decode($cleaned, true);
        if (! is_array($decoded)) {
            $firstBrace = strpos($cleaned, '{');
            $firstBracket = strpos($cleaned, '[');
            if ($firstBrace !== false && ($firstBracket === false || $firstBrace < $firstBracket)) {
                $lastBrace = strrpos($cleaned, '}');
                if ($lastBrace !== false && $lastBrace > $firstBrace) {
                    $candidate = substr($cleaned, $firstBrace, $lastBrace - $firstBrace + 1);
                    if (is_array(json_decode($candidate, true))) {
                        return $candidate;
                    }
                }
            } elseif ($firstBracket !== false) {
                $lastBracket = strrpos($cleaned, ']');
                if ($lastBracket !== false && $lastBracket > $firstBracket) {
                    $candidate = substr($cleaned, $firstBracket, $lastBracket - $firstBracket + 1);
                    if (is_array(json_decode($candidate, true))) {
                        return $candidate;
                    }
                }
            }
        }

        return trim($cleaned);
    }

    private function extractUsage(array $body, string $model): array
    {
        $meta = $body['usageMetadata'] ?? [];

        return [
            'model' => $model,
            'input_tokens' => (int) ($meta['promptTokenCount'] ?? 0),
            'output_tokens' => (int) ($meta['candidatesTokenCount'] ?? 0),
            'total_tokens' => (int) ($meta['totalTokenCount'] ?? 0),
        ];
    }

    private function fallbackOpenAiJson(string $instructions, string $userText, array $options = []): array
    {
        $apiKey = config('openai.api_key');
        $model = config('openai.model', 'gpt-4o-mini');

        $response = Http::withToken($apiKey)
            ->timeout($this->timeout)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $instructions],
                    ['role' => 'user', 'content' => $userText],
                ],
                'response_format' => ['type' => 'json_object'],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'OpenAI fallback request failed.');
        }

        $body = $response->json();
        $content = $body['choices'][0]['message']['content'] ?? '{}';
        $parsed = json_decode($this->cleanJsonString($content), true);

        if (! is_array($parsed)) {
            throw new RuntimeException('OpenAI returned invalid JSON.');
        }

        return [
            'data' => $parsed,
            'model' => $body['model'] ?? $model,
            'usage' => [
                'model' => $body['model'] ?? $model,
                'input_tokens' => (int) data_get($body, 'usage.prompt_tokens', 0),
                'output_tokens' => (int) data_get($body, 'usage.completion_tokens', 0),
                'total_tokens' => (int) data_get($body, 'usage.total_tokens', 0),
            ],
        ];
    }
}
