<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

/**
 * Client for Jev, TypeSafe's decision model. Jev takes a `state` plus a map of
 * typed questions (choice, score, noul) and returns typed answers with
 * probabilities. It can be reached directly (api.typesafe.ai) or through
 * OpenRouter's decisions API; both take the same request body.
 */
class JevClient
{
    /** Model reported by the last successful decision call */
    public ?string $lastModel = null;

    /**
     * The provider that will answer decisions: "jev", "openrouter" or null when
     * no key is configured.
     */
    public function provider(): ?string
    {
        $provider = config('ai.decision_provider', 'auto');
        $hasJev = filled(config('ai.jev.api_key'));
        $hasOpenRouter = filled(config('ai.openrouter.api_key'));

        return match ($provider) {
            'jev' => $hasJev ? 'jev' : null,
            'openrouter' => $hasOpenRouter ? 'openrouter' : null,
            default => $hasJev ? 'jev' : ($hasOpenRouter ? 'openrouter' : null),
        };
    }

    /**
     * The Jev model the current provider will be asked for.
     */
    public function model(): ?string
    {
        return match ($this->provider()) {
            'jev' => config('ai.jev.model'),
            'openrouter' => config('ai.openrouter.decision_model'),
            default => null,
        };
    }

    public function isConfigured(): bool
    {
        return $this->provider() !== null;
    }

    /**
     * Ask Jev the given questions about the state and return the answers keyed
     * by question name.
     *
     * Images (experimental, direct TypeSafe API only) are passed as
     * [reference => absolute file path]; put the reference string in the
     * state, instructions or criteria where the image belongs.
     *
     * @param  array<string, array{type: string, instructions: string, criteria: array}>  $questions
     * @param  array<string, string>  $images
     * @return array<string, array>
     */
    public function decide(array|string $state, array $questions, array $images = []): array
    {
        $provider = $this->provider();

        [$url, $key, $model] = match ($provider) {
            'jev' => [
                config('ai.jev.url'),
                config('ai.jev.api_key'),
                config('ai.jev.model'),
            ],
            'openrouter' => [
                rtrim(config('ai.openrouter.base_url'), '/').'/alpha/decisions',
                config('ai.openrouter.api_key'),
                config('ai.openrouter.decision_model'),
            ],
            default => throw new AiAnalysisException('No Jev provider configured. Set JEV_API_KEY or OPENROUTER_API_KEY.'),
        };

        $body = [
            'model' => $model,
            'state' => $state,
            'questions' => $questions,
        ];

        $request = Http::withToken($key)
            ->acceptJson()
            ->timeout((int) config('ai.timeout', 60));

        if ($images !== []) {
            if ($provider !== 'jev') {
                throw new AiAnalysisException('Jev image input is only supported through the TypeSafe API (JEV_API_KEY).');
            }

            // Image input is opt-in per request while TypeSafe marks it experimental
            $request = $request->withHeaders(['TypeSafe-Danger-Zone-Images' => 'true']);
            $body['assets'] = collect($images)->map(fn (string $path) => self::imageAsset($path))->all();
        }

        $response = $request->post($url, $body);

        if ($response->failed()) {
            throw new AiAnalysisException(
                "Jev request via {$provider} failed (HTTP {$response->status()}): ".mb_substr($response->body(), 0, 500)
            );
        }

        $answers = $response->json('answers');

        if (! is_array($answers)) {
            throw new AiAnalysisException("Jev response via {$provider} had no answers.");
        }

        // The resolved version, e.g. jev-1.14.0 when jev-latest was requested
        $this->lastModel = $response->json('model') ?: $model;

        return $answers;
    }

    /**
     * Whether screenshots can be sent to Jev directly (experimental).
     */
    public function supportsImages(): bool
    {
        return (bool) config('ai.jev.images') && $this->provider() === 'jev';
    }

    /**
     * Encode an image for the request's `assets` map, as documented at
     * docs.typesafe.ai/images: {type: "image", format, data_base64}. TypeSafe
     * accepts still PNG or JPEG, 256-1568 px per side, up to 16 MiB.
     */
    public static function imageAsset(string $path): array
    {
        if (! is_file($path)) {
            throw new AiAnalysisException("Image not found: {$path}");
        }

        $format = match (mime_content_type($path)) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            default => throw new AiAnalysisException("Jev only accepts PNG or JPEG images: {$path}"),
        };

        if (filesize($path) > 16 * 1024 * 1024) {
            throw new AiAnalysisException("Image is larger than Jev's 16 MiB limit: {$path}");
        }

        $size = @getimagesize($path);
        if ($size && (min($size[0], $size[1]) < 256 || max($size[0], $size[1]) > 1568)) {
            throw new AiAnalysisException("Image is {$size[0]}x{$size[1]}; Jev needs 256-1568 px per side: {$path}");
        }

        return [
            'type' => 'image',
            'format' => $format,
            'data_base64' => base64_encode(file_get_contents($path)),
        ];
    }

    /**
     * Index of the most likely level of a score answer (0 = first criterion).
     */
    public static function scoreLevel(array $answer, int $levels): ?int
    {
        $probabilities = $answer['probabilities'] ?? null;

        if (is_array($probabilities) && $probabilities !== []) {
            $values = array_values(array_map('floatval', $probabilities));

            return (int) array_search(max($values), $values, true);
        }

        if (isset($answer['score']) && is_numeric($answer['score'])) {
            $score = (float) $answer['score'];

            // Score may be normalised (0..1) or a level index (0..levels-1)
            $index = $score <= 1 && $levels > 2 ? $score * ($levels - 1) : $score;

            return max(0, min($levels - 1, (int) round($index)));
        }

        return null;
    }

    /**
     * Probability (0..1) that a noul (yes/no) answer is true.
     */
    public static function noulProbability(array $answer): ?float
    {
        $value = $answer['noul'] ?? $answer['probability'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
