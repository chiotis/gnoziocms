<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * A QR code (ISO/IEC 18004) drawn as SVG, for the second factor's `otpauth://` address: made here, so the secret is never sent to
 * a service that draws codes. Only what that needs: byte mode, error correction level M, versions 1 to 10 (up to 213 bytes).
 */
final class QrCode
{
    /** By version: error-correction codewords per block, then the data codewords of each block (level M). */
    private const BLOCKS = [
        1 => [10, [16]],
        2 => [16, [28]],
        3 => [26, [44]],
        4 => [18, [32, 32]],
        5 => [24, [43, 43]],
        6 => [16, [27, 27, 27, 27]],
        7 => [18, [31, 31, 31, 31]],
        8 => [22, [38, 38, 39, 39]],
        9 => [22, [36, 36, 36, 37, 37]],
        10 => [26, [43, 43, 43, 43, 44]],
    ];
    private const ALIGNMENT = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];

    /** @var list<list<bool>> */
    private array $modules = [];
    /** @var list<list<bool>> which modules are patterns, not data */
    private array $fixed = [];
    private int $size;

    private function __construct(private int $version)
    {
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->fixed = $this->modules;
    }

    /** The code of a text; throws when it is longer than version 10 holds. */
    public static function encode(string $text): self
    {
        $length = strlen($text);
        foreach (self::BLOCKS as $version => [, $blocks]) {
            $countBits = $version < 10 ? 8 : 16;
            if (4 + $countBits + 8 * $length <= array_sum($blocks) * 8) {
                $code = new self($version);
                $code->build($text, $countBits);
                return $code;
            }
        }
        throw new \LengthException('Too long for a QR code here.');
    }

    public function size(): int
    {
        return $this->size;
    }

    public function dark(int $x, int $y): bool
    {
        return $this->modules[$y][$x];
    }

    public function version(): int
    {
        return $this->version;
    }

    /** The code as an SVG image: one path, with the quiet zone of four modules around it. */
    public function svg(int $pixels = 200, string $label = 'QR code'): string
    {
        $full = $this->size + 8;
        $path = '';
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x]) {
                    $path .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $full . ' ' . $full . '" width="' . $pixels . '" height="' . $pixels
            . '" role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
    }

    private function build(string $text, int $countBits): void
    {
        [$eccLength, $blocks] = self::BLOCKS[$this->version];
        $capacity = array_sum($blocks);

        // The data: mode (byte), length, the bytes, a terminator, then padding to fill the version.
        $bits = '0100' . str_pad(decbin(strlen($text)), $countBits, '0', STR_PAD_LEFT);
        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $capacity * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $data = array_map('bindec', str_split($bits, 8));
        for ($pad = 0; count($data) < $capacity; $pad++) {
            $data[] = $pad % 2 === 0 ? 0xEC : 0x11;
        }

        // Split into blocks, each with its error correction, and interleave them.
        $dataBlocks = [];
        $eccBlocks = [];
        $offset = 0;
        foreach ($blocks as $length) {
            $block = array_slice($data, $offset, $length);
            $offset += $length;
            $dataBlocks[] = $block;
            $eccBlocks[] = self::reedSolomon($block, $eccLength);
        }
        $codewords = [];
        for ($i = 0; $i < max($blocks); $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $codewords[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $eccLength; $i++) {
            foreach ($eccBlocks as $block) {
                $codewords[] = $block[$i];
            }
        }

        $this->drawPatterns();
        $this->placeData($codewords);

        // The mask that leaves the fewest patterns a reader could mistake for something else.
        $best = 0;
        $bestScore = PHP_INT_MAX;
        $plain = $this->modules;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->modules = $plain;
            $this->applyMask($mask);
            $this->drawFormat($mask);
            $score = $this->penalty();
            if ($score < $bestScore) {
                $best = $mask;
                $bestScore = $score;
            }
        }
        $this->modules = $plain;
        $this->applyMask($best);
        $this->drawFormat($best);
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->fixed[$y][$x] = true;
    }

    private function drawPatterns(): void
    {
        $last = $this->size - 1;
        for ($i = 0; $i < $this->size; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$last - 3, 3], [3, $last - 3]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                        $distance = max(abs($dx), abs($dy));
                        $this->set($x, $y, $distance !== 2 && $distance !== 4);
                    }
                }
            }
        }
        $centres = self::ALIGNMENT[$this->version];
        $count = count($centres);
        foreach ($centres as $i => $cx) {
            foreach ($centres as $j => $cy) {
                // Not where a finder pattern is.
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $count - 1) || ($i === $count - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        // Reserve the format areas (drawn for real once the mask is chosen) and draw the version, from version 7.
        $this->drawFormat(0);
        if ($this->version >= 7) {
            $remainder = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
            }
            $bits = ($this->version << 12) | $remainder;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $this->size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $dark);
                $this->set($b, $a, $dark);
            }
        }
    }

    /** The format information: level M (00) and the mask, with its BCH code, in its two places. */
    private function drawFormat(int $mask): void
    {
        $data = (0 << 3) | $mask;
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }
        $bits = (($data << 10) | $remainder) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $this->size - 15 + $i, $bit($i));
        }
        $this->set(8, $this->size - 8, true);
    }

    /** @param list<int> $codewords */
    private function placeData(array $codewords): void
    {
        $total = count($codewords) * 8;
        $i = 0;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vertical = 0; $vertical < $this->size; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vertical : $vertical;
                    if (!$this->fixed[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->fixed[$y][$x]) {
                    continue;
                }
                $flip = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($flip) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** The penalty of the standard's four rules: runs, 2×2 blocks, finder-like patterns, and the balance of dark and light. */
    private function penalty(): int
    {
        $score = 0;
        $n = $this->size;
        $lines = [];
        for ($i = 0; $i < $n; $i++) {
            $row = '';
            $column = '';
            for ($j = 0; $j < $n; $j++) {
                $row .= $this->modules[$i][$j] ? '1' : '0';
                $column .= $this->modules[$j][$i] ? '1' : '0';
            }
            $lines[] = $row;
            $lines[] = $column;
        }
        foreach ($lines as $line) {
            preg_match_all('/0{5,}|1{5,}/', $line, $runs);
            foreach ($runs[0] as $run) {
                $score += strlen($run) - 2;
            }
            $score += 40 * preg_match_all('/(?=10111010000|00001011101)/', $line);
        }
        $dark = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $dark += $this->modules[$y][$x] ? 1 : 0;
                if ($x < $n - 1 && $y < $n - 1) {
                    $c = $this->modules[$y][$x];
                    if ($c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) {
                        $score += 3;
                    }
                }
            }
        }
        $score += 10 * intdiv(abs($dark * 20 - $n * $n * 10), $n * $n);
        return $score;
    }

    /**
     * The error-correction codewords of a block: the remainder of the data divided by the generator polynomial, in GF(256).
     *
     * @param list<int> $data
     * @return list<int>
     */
    public static function reedSolomon(array $data, int $degree): array
    {
        [$exp, $log] = self::galois();
        // The generator: (x - a^0)(x - a^1)…(x - a^(degree-1)), highest power first, without its leading 1.
        $generator = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);
            foreach ($generator as $j => $coefficient) {
                $next[$j] ^= $coefficient;
                $next[$j + 1] ^= $coefficient === 0 ? 0 : $exp[($log[$coefficient] + $i) % 255];
            }
            $generator = $next;
        }
        array_shift($generator);
        $remainder = array_fill(0, $degree, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($remainder);
            $remainder[] = 0;
            if ($factor !== 0) {
                foreach ($generator as $j => $coefficient) {
                    $remainder[$j] ^= $coefficient === 0 ? 0 : $exp[($log[$coefficient] + $log[$factor]) % 255];
                }
            }
        }
        return $remainder;
    }

    /** @return array{0: list<int>, 1: array<int, int>} the powers of 2 in GF(256) (polynomial 0x11D), and their logarithms */
    private static function galois(): array
    {
        static $tables = null;
        if ($tables === null) {
            $exp = [];
            $log = [];
            $value = 1;
            for ($i = 0; $i < 255; $i++) {
                $exp[$i] = $value;
                $log[$value] = $i;
                $value <<= 1;
                if ($value & 0x100) {
                    $value ^= 0x11D;
                }
            }
            $tables = [$exp, $log];
        }
        return $tables;
    }
}
