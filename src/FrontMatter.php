<?php

declare(strict_types=1);

namespace GnozioCMS;

/** Content files are YAML front matter between --- lines, then the Markdown body. */
final class FrontMatter
{
    /** @return array{0: string, 1: string} the YAML text and the body */
    public static function split(string $raw): array
    {
        if (preg_match('/\A---\s*\R(.*?)\R---\s*\R(.*)\z/s', $raw, $matches)) {
            return [$matches[1], $matches[2]];
        }
        return ['', $raw];
    }
}
