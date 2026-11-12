<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Tests\Support;

use Closure;
use RuntimeException;

/** Owns synthetic test files, including helpers used outside a running test. */
final class TemporaryFixtures
{
    private const string RUN_PREFIX = 'ichiloto-engine-fixtures-';
    private const string LOCK_FORMAT = 'ichiloto.engine.test-fixtures/1';

    private static ?string $runDirectory = null;
    /** @var resource|null */
    private static mixed $runLock = null;
    private static ?string $test = null;
    /** @var array<string, string> Test identity (or process scope) to owned directory. */
    private static array $directories = [];
    private static bool $shutdownRegistered = false;

    public static function beginTest(string $identity): void
    {
        if (self::$test !== null || trim($identity) === '') {
            throw new RuntimeException('A temporary fixture test scope must start after the previous scope ends.');
        }
        self::$test = $identity;
    }

    /** Per-test cleanup fails that test if owned files cannot be removed. */
    public static function endTest(): void
    {
        $scope = self::$test;
        try { self::clearCurrentTest(); } finally { self::$test = null; }
        if ($scope !== null) { unset(self::$directories[$scope]); }
    }

    /** Explicit cleanup keeps the current test scope available for later fixtures. */
    public static function clearCurrentTest(): void
    {
        $scope = self::$test;
        if ($scope === null || !isset(self::$directories[$scope])) { return; }
        self::assertRunOwnership();
        $problems = self::removeTree(self::$directories[$scope]);
        if ($problems !== []) {
            throw new RuntimeException('Temporary fixture cleanup failed for ' . $scope . ":\n" . implode("\n", $problems));
        }
    }

    /** Stable within a test; fixtures made before tests stay owned until process exit. */
    public static function getDirectory(): string
    {
        if (self::$runDirectory !== null) { self::assertRunOwnership(); }
        $scope = self::$test ?? '';
        if (isset(self::$directories[$scope]) && (is_link(self::$directories[$scope])
            || (file_exists(self::$directories[$scope]) && !is_dir(self::$directories[$scope])))) {
            throw new RuntimeException('Temporary fixture scope ownership changed; its contents were preserved.');
        }
        if (!isset(self::$directories[$scope])) {
            $path = self::getRunDirectory() . '/' . ($scope === '' ? 'process-' : 'test-') . bin2hex(random_bytes(12));
            self::requireOperation(static fn(): bool => mkdir($path, 0o700), $path);
            self::$directories[$scope] = $path;
        } elseif (!file_exists(self::$directories[$scope]) && !is_link(self::$directories[$scope])) {
            $path = self::$directories[$scope];
            self::assertRunOwnership();
            self::requireOperation(static fn(): bool => mkdir($path, 0o700), $path);
        }
        return self::$directories[$scope];
    }

    public static function createDirectory(string $prefix): string
    {
        if (str_contains($prefix, '/') || str_contains($prefix, chr(92)) || preg_match('/\p{Cc}/u', $prefix) !== 0) {
            throw new RuntimeException('A temporary fixture prefix must be a filename prefix.');
        }
        $path = self::getDirectory() . '/' . $prefix . bin2hex(random_bytes(12));
        self::requireOperation(static fn(): bool => mkdir($path, 0o700), $path);
        return $path;
    }

    private static function getRunDirectory(): string
    {
        if (self::$runDirectory !== null) { self::assertRunOwnership(); return self::$runDirectory; }
        $base = realpath(sys_get_temp_dir());
        if ($base === false) { throw new RuntimeException('The system temporary directory is unavailable.'); }
        self::removeAbandonedRuns($base);
        do {
            $directory = $base . '/' . self::RUN_PREFIX . bin2hex(random_bytes(12));
            $lockPath = $directory . '.lock';
            $lock = fopen($lockPath, 'x+b');
            if ($lock === false) { throw new RuntimeException('Cannot create temporary fixture lock ' . $lockPath); }
            if (!flock($lock, LOCK_EX)) {
                fclose($lock);
                throw new RuntimeException('Cannot hold temporary fixture lock ' . $lockPath);
            }
            $matches = self::isLockAt($lock, $lockPath);
            if (!$matches) { flock($lock, LOCK_UN); fclose($lock); }
        } while (!$matches);
        self::$runDirectory = $directory;
        self::$runLock = $lock;
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            // Services registered later may still flush diagnostics during shutdown.
            register_shutdown_function(static function (): void {
                register_shutdown_function(self::removeAtShutdown(...));
            });
        }
        $header = self::LOCK_FORMAT . "\n";
        if (fwrite($lock, $header) !== strlen($header) || !fflush($lock)) {
            throw new RuntimeException('Cannot identify temporary fixture lock ' . $lockPath);
        }
        self::requireOperation(static fn(): bool => mkdir($directory, 0o700), $directory);
        return $directory;
    }

    /** A lock is live only while a process holds it; abandoned filenames alone grant no ownership. */
    private static function removeAbandonedRuns(string $base): void
    {
        foreach (glob($base . '/' . self::RUN_PREFIX . '*.lock') ?: [] as $path) {
            if (is_link($path) || !is_file($path)) { continue; }
            $handle = self::openExistingLock($path);
            if ($handle === null) { continue; }
            try {
                if (!flock($handle, LOCK_EX | LOCK_NB) || !self::isLockAt($handle, $path)) { continue; }
                rewind($handle);
                if (fgets($handle) !== self::LOCK_FORMAT . "\n") { continue; }
                $problems = self::removeTree(substr($path, 0, -strlen('.lock')));
                if ($problems === []) { $problems = self::attempt(static fn(): bool => unlink($path), $path); }
                if ($problems !== []) {
                    throw new RuntimeException("Cannot recover abandoned temporary fixtures:\n" . implode("\n", $problems));
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
        // A destructor may recreate diagnostics after shutdown removed its run and lock.
        // Our factory always locks before making a run, so a lockless generated run is abandoned.
        foreach (glob($base . '/' . self::RUN_PREFIX . '*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (preg_match('/\A' . self::RUN_PREFIX . '[a-f0-9]{24}\z/D', basename($directory)) !== 1
                || file_exists($directory . '.lock') || is_link($directory . '.lock')) { continue; }
            $problems = self::removeTree($directory);
            if ($problems !== []) { throw new RuntimeException("Cannot recover late fixture diagnostics:\n" . implode("\n", $problems)); }
        }
    }

    /** A concurrent cleanup may remove a lock between discovery and open. */
    private static function openExistingLock(string $path): mixed
    {
        $handle = null;
        self::attempt(static function () use ($path, &$handle): bool {
            $handle = fopen($path, 'r+b');
            return $handle !== false;
        }, $path);
        return is_resource($handle) ? $handle : null;
    }

    /** @param resource $handle */
    private static function isLockAt(mixed $handle, string $path): bool
    {
        clearstatcache(true, $path);
        $held = fstat($handle);
        $current = null;
        self::attempt(static function () use ($path, &$current): bool {
            $current = lstat($path);
            return $current !== false;
        }, $path);
        return is_array($held) && is_array($current) && $held['dev'] === $current['dev'] && $held['ino'] === $current['ino'];
    }

    /** Remove links as links; a fixture never owns their targets. @return list<string> */
    private static function removeTree(string $path): array
    {
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) { return []; }
        if (is_link($path) || !is_dir($path)) { return self::attempt(static fn(): bool => unlink($path), $path); }
        $permissions = fileperms($path);
        if ($permissions === false) { return [$path . ': cannot read directory permissions']; }
        if (($permissions & 0o700) !== 0o700) {
            $problems = self::attempt(static fn(): bool => chmod($path, ($permissions & 0o7777) | 0o700), $path);
            if ($problems !== []) { return $problems; }
        }
        $entries = [];
        $problems = self::attempt(static function () use ($path, &$entries): bool {
            $entries = scandir($path);
            return $entries !== false;
        }, $path);
        foreach ($entries ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') { array_push($problems, ...self::removeTree($path . '/' . $entry)); }
        }
        return $problems === [] ? self::attempt(static fn(): bool => rmdir($path), $path) : $problems;
    }

    /** Capture the filesystem's reason, retaining a failed fixture for another cleanup attempt. @return list<string> */
    private static function attempt(Closure $operation, string $path): array
    {
        $reason = 'operation returned false';
        set_error_handler(static function (int $severity, string $message) use (&$reason): bool { $reason = $message; return true; });
        try { $success = $operation(); } finally { restore_error_handler(); }
        return $success ? [] : [$path . ': ' . $reason];
    }

    private static function requireOperation(Closure $operation, string $path): void
    {
        $problems = self::attempt($operation, $path);
        if ($problems !== []) { throw new RuntimeException(implode("\n", $problems)); }
    }

    private static function assertRunOwnership(): void
    {
        if (self::$runDirectory === null || !is_resource(self::$runLock)
            || !self::isLockAt(self::$runLock, self::$runDirectory . '.lock')
            || is_link(self::$runDirectory) || realpath(self::$runDirectory) !== self::$runDirectory) {
            throw new RuntimeException('Temporary fixture run ownership changed; its contents were preserved.');
        }
    }

    private static function removeAtShutdown(): void
    {
        if (self::$runDirectory === null || !is_resource(self::$runLock)) { return; }
        $directory = self::$runDirectory; $lock = self::$runLock;
        if (!self::isLockAt($lock, $directory . '.lock')) {
            fwrite(STDERR, "Temporary fixture run lock changed; preserved " . $directory . "\n");
            exit(1);
        }
        $problems = self::removeTree($directory);
        if ($problems === [] && self::isLockAt($lock, $directory . '.lock')) {
            $problems = self::attempt(static fn(): bool => unlink($directory . '.lock'), $directory . '.lock');
        }
        flock($lock, LOCK_UN); fclose($lock);
        self::$runDirectory = null; self::$runLock = null; self::$directories = [];
        if ($problems !== []) {
            fwrite(STDERR, "Temporary fixture shutdown cleanup failed:\n" . implode("\n", $problems) . "\n");
            exit(1);
        }
    }
}
