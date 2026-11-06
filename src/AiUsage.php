<?php

declare(strict_types=1);

namespace GnozioCMS;

use PDO;

/**
 * Tokens used by AI publishing and what they are estimated to cost (docs/ai-publishing-plan.md, section 8a). Every call to a
 * provider writes one row, a failed one too, with the exact counts the provider returned and the prices of the price table at that
 * moment, so a later price change never rewrites the past. A model with no price is counted in tokens and costs null ("unknown").
 * The figure is an estimate: the bill is the provider's.
 */
final class AiUsage
{
    public const SOURCES = ['editor', 'test', 'bulk', 'schedule', 'mcp', 'automatic', 'preview', 'check'];

    /** @param (\Closure(): int)|null $clock */
    public function __construct(private SystemDatabase $db, private ?\Closure $clock = null)
    {
    }

    /**
     * Counts from a provider's `usage` object, in the site's columns. Reads the Responses API's shape (input_tokens_details.cached_tokens,
     * output_tokens_details.reasoning_tokens) and the picture endpoints' (input/output_tokens_details.text_tokens and image_tokens).
     * @param array<string, mixed> $usage
     * @return array{text_input: int, cached_input: int, cache_write: int, image_input: int, text_output: int, reasoning: int, image_output: int}
     */
    public static function counts(array $usage, string $kind): array
    {
        $in = is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : [];
        $out = is_array($usage['output_tokens_details'] ?? null) ? $usage['output_tokens_details'] : [];
        $input = (int)($usage['input_tokens'] ?? 0);
        $output = (int)($usage['output_tokens'] ?? 0);
        if ($kind === 'picture') {
            $imageIn = (int)($in['image_tokens'] ?? 0);
            $imageOut = isset($out['image_tokens']) ? (int)$out['image_tokens'] : $output;
            return [
                'text_input' => isset($in['text_tokens']) ? (int)$in['text_tokens'] : max(0, $input - $imageIn),
                'cached_input' => (int)($in['cached_tokens'] ?? 0),
                'cache_write' => 0,
                'image_input' => $imageIn,
                'text_output' => (int)($out['text_tokens'] ?? 0),
                'reasoning' => 0,
                'image_output' => $imageOut,
            ];
        }
        return [
            'text_input' => $input,
            'cached_input' => (int)($in['cached_tokens'] ?? 0),
            'cache_write' => (int)($in['cache_write_tokens'] ?? 0),
            'image_input' => 0,
            'text_output' => $output,
            'reasoning' => (int)($out['reasoning_tokens'] ?? 0),
            'image_output' => 0,
        ];
    }

    /**
     * The estimated cost in US dollars, or null when a price the counts need is missing. Prices per million tokens; text input
     * counts cached tokens at the cached price (when there is one); output includes reasoning tokens, as the provider bills them.
     * @param array<string, int> $counts from counts() @param array<string, float|null>|null $prices a row of the price table
     */
    public static function cost(array $counts, ?array $prices, int $searches = 0): ?float
    {
        if ($prices === null) {
            return null;
        }
        $need = static function (string $field) use ($prices): ?float {
            return isset($prices[$field]) && is_numeric($prices[$field]) ? (float)$prices[$field] : null;
        };
        $total = 0.0;
        $parts = [
            ['text_input', max(0, $counts['text_input'] - $counts['cached_input'])],
            ['cached_input', $counts['cached_input']],
            ['image_input', $counts['image_input']],
            ['text_output', $counts['text_output']],
            ['image_output', $counts['image_output']],
        ];
        foreach ($parts as [$field, $tokens]) {
            if ($tokens <= 0) {
                continue;
            }
            // Cached tokens without a price of their own cost what other input costs.
            $price = $need($field) ?? ($field === 'cached_input' ? $need('text_input') : null);
            if ($price === null) {
                return null;
            }
            $total += $tokens / 1_000_000 * $price;
        }
        if ($searches > 0) {
            $price = $need('search_call');
            if ($price === null) {
                return null;
            }
            $total += $searches * $price;
        }
        return round($total, 6);
    }

    /**
     * Writes one call. Returns the estimated cost (null when unknown).
     * @param array<string, mixed> $usage the provider's usage object @param array<string, array<string, float|null>> $priceTable
     */
    public function record(string $source, string $provider, string $model, string $kind, array $usage, array $priceTable, bool $ok, string $ref = '', string $entry = '', int $pictures = 0, int $searches = 0): ?float
    {
        $counts = self::counts($usage, $kind);
        $prices = $priceTable[$model] ?? null;
        $cost = self::cost($counts, $prices, $searches);
        $pdo = $this->pdo();
        if ($pdo === null) {
            return $cost;
        }
        $pdo->prepare('INSERT INTO ai_usage (at, source, ref, entry, provider, model, kind, ok, text_input, cached_input, cache_write, image_input, text_output, reasoning, image_output, searches, pictures, cost, prices)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $this->now(), in_array($source, self::SOURCES, true) ? $source : 'editor', $ref, $entry, $provider, $model, $kind, $ok ? 1 : 0,
            $counts['text_input'], $counts['cached_input'], $counts['cache_write'], $counts['image_input'], $counts['text_output'], $counts['reasoning'],
            $counts['image_output'], $searches, $pictures, $cost, json_encode($prices ?? new \stdClass()),
        ]);
        return $cost;
    }

    /**
     * Totals between two times (Unix seconds, end excluded), grouped by a column (kind, source, model) or not at all.
     * @return array<int, array<string, mixed>> rows with calls, failed, tokens per column, pictures, searches, cost (known part), unknown (calls without a price)
     */
    public function totals(int $from, int $to, ?string $by = null): array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        $group = in_array($by, ['kind', 'source', 'model', 'day'], true) ? $by : null;
        $key = match ($group) {
            null => "''",
            'day' => "strftime('%Y-%m-%d', at, 'unixepoch', 'localtime')",
            default => $group,
        };
        $stmt = $pdo->prepare("SELECT $key AS label, COUNT(*) AS calls, SUM(1 - ok) AS failed, SUM(text_input) AS text_input, SUM(cached_input) AS cached_input,
            SUM(image_input) AS image_input, SUM(text_output) AS text_output, SUM(reasoning) AS reasoning, SUM(image_output) AS image_output,
            SUM(pictures) AS pictures, SUM(searches) AS searches, SUM(COALESCE(cost, 0)) AS cost, SUM(CASE WHEN cost IS NULL AND ok = 1 THEN 1 ELSE 0 END) AS unknown
            FROM ai_usage WHERE at >= ? AND at < ? GROUP BY label ORDER BY " . ($group === 'day' ? 'label ASC' : 'cost DESC, calls DESC'));
        $stmt->execute([$from, $to]);
        return array_map(static function (array $row): array {
            foreach ($row as $name => $value) {
                if ($name !== 'label') {
                    $row[$name] = $name === 'cost' ? round((float)$value, 6) : (int)$value;
                }
            }
            $row['tokens'] = $row['text_input'] + $row['image_input'] + $row['text_output'] + $row['image_output'];
            return $row;
        }, $stmt->fetchAll());
    }

    /** Estimated cost and pictures made in the calendar month of a time (site time), for the caps. @return array{cost: float, pictures: int} */
    public function month(?int $at = null): array
    {
        [$from, $to] = self::monthRange(date('Y-m', $at ?? $this->now()));
        $row = $this->totals($from, $to)[0] ?? ['cost' => 0.0, 'pictures' => 0];
        return ['cost' => (float)$row['cost'], 'pictures' => (int)$row['pictures']];
    }

    /** What one entry cost to make (all its calls). */
    public function forEntry(string $entry): ?float
    {
        $pdo = $this->pdo();
        if ($pdo === null || $entry === '') {
            return null;
        }
        $stmt = $pdo->prepare('SELECT SUM(COALESCE(cost, 0)) FROM ai_usage WHERE entry = ?');
        $stmt->execute([$entry]);
        $value = $stmt->fetchColumn();
        return $value === null ? null : round((float)$value, 6);
    }

    /** @return array<int, array<string, mixed>> every row in a range, oldest first, for CSV */
    public function rows(int $from, int $to): array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return [];
        }
        $stmt = $pdo->prepare('SELECT * FROM ai_usage WHERE at >= ? AND at < ? ORDER BY at ASC, id ASC');
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll();
    }

    /** First second of a month (YYYY-MM, site time) and of the next one. @return array{0: int, 1: int} */
    public static function monthRange(string $month): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            $month = date('Y-m');
        }
        $start = (int)strtotime($month . '-01 00:00:00');
        return [$start, (int)strtotime('+1 month', $start)];
    }

    private function pdo(): ?PDO
    {
        return $this->db->isAvailable() ? $this->db->connection() : null;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
