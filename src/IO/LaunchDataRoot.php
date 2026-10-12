<?php

declare(strict_types=1);

namespace Ichiloto\Engine\IO;

use Assegai\Util\Path;
use InvalidArgumentException;

/** Validates a caller-owned, dedicated playtest directory without creating or deleting it. */
final class LaunchDataRoot
{
    public static function validate(string $path, string $projectRoot): string
    {
        if ($path === '' || $path === DIRECTORY_SEPARATOR || str_contains($path, "\0") || realpath($path) !== $path
            || !is_dir($path) || !is_readable($path) || !is_writable($path)) {
            throw new InvalidArgumentException('Launch dataRoot must be an existing canonical absolute writable directory.');
        }
        $project = realpath($projectRoot);
        if ($project === false || $path === $project || str_starts_with($path, $project . DIRECTORY_SEPARATOR)
            || str_starts_with($project, $path . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('Launch dataRoot must be isolated outside the project, not its ancestor.');
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            // The launcher owns PHP errors/stdout/stderr before Game can initialize its logger.
            if ($entry === 'logs') {
                self::validateInitialLogs($path);
                continue;
            }
            if ($entry !== 'player-settings.json' || is_link($path . '/' . $entry)
                || !is_file($path . '/' . $entry) || !is_readable($path . '/' . $entry)) {
                throw new InvalidArgumentException('A fresh launch dataRoot may contain only a regular player-settings.json copy and launcher logs.');
            }
        }
        return $path;
    }

    private static function validateInitialLogs(string $root): void
    {
        $logs = self::validatePath($root, $root . DIRECTORY_SEPARATOR . 'logs');
        if (!is_dir($logs) || !is_readable($logs) || !is_writable($logs)) {
            throw new InvalidArgumentException('Launch logs must be a readable writable directory inside dataRoot.');
        }
        foreach (new \FilesystemIterator($logs, \FilesystemIterator::SKIP_DOTS) as $file) {
            self::validatePath($root, $file->getPathname());
            if (!$file->isFile() || !$file->isReadable() || !$file->isWritable()) {
                throw new InvalidArgumentException('Initial launcher logs must be regular readable writable files.');
            }
        }
    }

    /** Recheck descendants at use time, including paths created after launch validation. */
    public static function validatePath(string $root, string $path): string
    {
        $normalized = Path::normalize($path);
        if (str_contains($path, "\0") || realpath($root) !== $root
            || !str_starts_with($normalized, $root . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('Local data path escapes the isolated launch dataRoot.');
        }
        $current = $normalized;
        while ($current !== $root) {
            if (is_link($current) || (file_exists($current) && realpath($current) !== $current)) {
                throw new InvalidArgumentException('Isolated launch data cannot follow a symlink.');
            }
            $current = dirname($current);
        }
        return $normalized;
    }
}
