<?php

declare(strict_types=1);

namespace GnozioCMS;

/**
 * Makes a stylesheet smaller without changing what it means: comments go (except `/*! … *\/`, which keep a licence), runs of
 * white space become one space, and the space around `{ } ; ,` and after `:` goes, as does the `;` before `}`.
 * Strings and `url(…)` are copied as they are. It never removes the space around `+`, `-`, `*`, `/`, `>`, `~` or before `:`
 * (in `calc(a - b)` and in `a :hover` that space has a meaning), so it is safe rather than smallest.
 */
final class CssMinify
{
    public static function minify(string $css): string
    {
        $out = '';
        $length = strlen($css);
        $space = false;
        $i = 0;
        while ($i < $length) {
            $c = $css[$i];

            if ($c === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                if (($css[$i + 2] ?? '') === '!') {
                    $out .= self::gap($out, $space) . substr($css, $i, $end - $i);
                    $space = false;
                }
                // A comment is not white space (`.a/**/.b` is `.a.b`): it just goes.
                $i = $end;
                continue;
            }

            if ($c === '"' || $c === "'") {
                $end = $i + 1;
                while ($end < $length && $css[$end] !== $c) {
                    $end += $css[$end] === '\\' ? 2 : 1;
                }
                $out .= self::gap($out, $space) . substr($css, $i, min($end + 1, $length) - $i);
                $space = false;
                $i = $end + 1;
                continue;
            }

            if (($c === 'u' || $c === 'U') && strncasecmp(substr($css, $i, 4), 'url(', 4) === 0) {
                $end = $i + 4;
                $depth = 1;
                while ($end < $length && $depth > 0) {
                    $ch = $css[$end];
                    if ($ch === '"' || $ch === "'") {
                        $close = $end + 1;
                        while ($close < $length && $css[$close] !== $ch) {
                            $close += $css[$close] === '\\' ? 2 : 1;
                        }
                        $end = $close + 1;
                        continue;
                    }
                    $depth += $ch === '(' ? 1 : ($ch === ')' ? -1 : 0);
                    $end++;
                }
                $out .= self::gap($out, $space) . substr($css, $i, $end - $i);
                $space = false;
                $i = $end;
                continue;
            }

            if (ctype_space($c)) {
                $space = $out !== '';
                $i++;
                continue;
            }

            if ($c === '}' && str_ends_with($out, ';')) {
                $out = substr($out, 0, -1);
            }
            // `--x: ;` (a custom property whose value is a space) keeps its space.
            $keep = str_ends_with($out, ':') && ($c === ';' || $c === '}');
            if ($space && ($keep || (!self::trimsBefore($c) && !self::trimsAfter(substr($out, -1))))) {
                $out .= ' ';
            }
            $space = false;
            $out .= $c;
            $i++;
        }
        return $out;
    }

    /** The space to write before a string, a url() or a kept comment: none after `{ } ; , :`. */
    private static function gap(string $out, bool $space): string
    {
        return $space && $out !== '' && !self::trimsAfter(substr($out, -1)) ? ' ' : '';
    }

    /** No space is needed before these. */
    private static function trimsBefore(string $c): bool
    {
        return $c === '{' || $c === '}' || $c === ';' || $c === ',';
    }

    /** No space is needed after these. */
    private static function trimsAfter(string $c): bool
    {
        return $c === '{' || $c === '}' || $c === ';' || $c === ',' || $c === ':';
    }
}
