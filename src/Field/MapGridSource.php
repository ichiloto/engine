<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use InvalidArgumentException;
use ParseError;

/** Reads an authored grid without executing its PHP source. */
final class MapGridSource
{
    public static function readFile(string $path): string
    {
        $source = @file_get_contents($path);

        if ($source === false) {
            throw new InvalidArgumentException("Grid source {$path} could not be read.");
        }

        return self::parseSource($source, $path);
    }

    public static function parseSource(string $source, string $path): string
    {
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError $error) {
            throw new InvalidArgumentException("Grid source {$path} must return one literal nowdoc string.", previous: $error);
        }

        $index = 0;
        if (!self::take($tokens, $index, T_OPEN_TAG)) {
            throw self::createRefusalException($path);
        }

        self::skipTrivia($tokens, $index);
        if (!self::take($tokens, $index, T_RETURN)) {
            throw self::createRefusalException($path);
        }

        self::skipTrivia($tokens, $index);
        $start = $tokens[$index] ?? null;
        if (!is_array($start) || $start[0] !== T_START_HEREDOC
            || preg_match("/\\A<<<[ \\t]*'([A-Za-z_][A-Za-z0-9_]*)'(?:\\r\\n|\\n|\\r)\\z/", $start[1], $matches) !== 1) {
            throw self::createRefusalException($path);
        }
        $marker = $matches[1];
        $index++;

        $body = '';
        $content = $tokens[$index] ?? null;
        if (is_array($content) && $content[0] === T_ENCAPSED_AND_WHITESPACE) {
            $body = $content[1];
            $index++;
        }

        $end = $tokens[$index] ?? null;
        if (!is_array($end) || $end[0] !== T_END_HEREDOC
            || preg_match('/\\A([ \\t]*)' . preg_quote($marker, '/') . '\\z/', $end[1], $matches) !== 1) {
            throw self::createRefusalException($path);
        }
        $indent = $matches[1];
        $index++;

        if (($tokens[$index] ?? null) !== ';') {
            throw self::createRefusalException($path);
        }
        $index++;
        self::skipTrivia($tokens, $index);
        if ($index !== count($tokens)) {
            throw self::createRefusalException($path);
        }

        // PHP excludes the line ending immediately before the closing marker.
        $body = preg_replace('/(?:\\r\\n|\\n|\\r)\\z/', '', $body) ?? $body;
        if ($indent !== '') {
            $parts = preg_split('/(\\r\\n|\\n|\\r)/', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$body];
            foreach ($parts as $partIndex => &$part) {
                if ($partIndex % 2 !== 0) {
                    continue;
                }
                if (str_starts_with($part, $indent)) {
                    $part = substr($part, strlen($indent));
                } elseif (trim($part, " \t") === '') {
                    // PHP removes all whitespace from a blank line shorter
                    // than the closing marker's indentation.
                    $part = '';
                }
            }
            unset($part);
            $body = implode('', $parts);
        }

        return $body;
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private static function skipTrivia(array $tokens, int &$index): void
    {
        while (isset($tokens[$index]) && is_array($tokens[$index])
            && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $index++;
        }
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private static function take(array $tokens, int &$index, int $type): bool
    {
        if (!isset($tokens[$index]) || !is_array($tokens[$index]) || $tokens[$index][0] !== $type) {
            return false;
        }
        $index++;
        return true;
    }

    private static function createRefusalException(string $path): InvalidArgumentException
    {
        return new InvalidArgumentException("Grid source {$path} must return one literal nowdoc string without executable code.");
    }
}
