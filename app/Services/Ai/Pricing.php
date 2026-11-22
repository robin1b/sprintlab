<?php

namespace App\Services\Ai;

/**
 * Turns tokens into money, using the prices in the config.
 */
class Pricing
{
    /**
     * Cost in millionths of a US dollar, or null when the model has no price.
     *
     * The prices are dollars per million tokens, so a price of 2.00 is exactly
     * 2 000 000 micros per million tokens: price in micros x tokens / 1 000 000.
     */
    public function costMicros(string $model, int $inputTokens, int $outputTokens): ?int
    {
        $price = $this->priceFor($model);

        if ($price === null) {
            return null;
        }

        $inputMicros = (int) round($price['input'] * 1_000_000);
        $outputMicros = (int) round($price['output'] * 1_000_000);

        $total = $inputMicros * $inputTokens + $outputMicros * $outputTokens;

        // Round up to the next micro: we would rather over- than undercount.
        return intdiv($total + 999_999, 1_000_000);
    }

    /**
     * @return array{input: float, output: float}|null
     */
    private function priceFor(string $model): ?array
    {
        /** @var array<string, array{input: float|int, output: float|int}> $prices */
        $prices = (array) config('sprintlab.ai.prices', []);

        // The longest matching key wins, so "claude-sonnet-5-5-mini" can get its own price.
        $match = null;

        foreach ($prices as $name => $price) {
            if (str_starts_with($model, (string) $name) && ($match === null || strlen((string) $name) > strlen($match))) {
                $match = (string) $name;
            }
        }

        if ($match === null) {
            return null;
        }

        return ['input' => (float) $prices[$match]['input'], 'output' => (float) $prices[$match]['output']];
    }
}
