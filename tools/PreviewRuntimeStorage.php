<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Sprites\PngAssetBounds;
use Ichiloto\Engine\Rendering\Sprites\PngAssetFactsCache;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\LocalDataFiles;

/** A preview owns its generated artwork facts and diagnostics for its CLI lifetime. */
final class PreviewRuntimeStorage
{
    private readonly string $directory;

    public function __construct()
    {
        $temporary = realpath(sys_get_temp_dir());
        if ($temporary === false) { throw new RuntimeException('Preview temporary directory is unavailable.'); }
        $this->directory = $temporary . DIRECTORY_SEPARATOR . 'ichiloto-preview-' . bin2hex(random_bytes(12));
        if (!LocalDataFiles::runFileOperation(fn(): bool => mkdir($this->directory, 0700))) {
            throw new RuntimeException('Could not create isolated preview runtime storage.');
        }

        PngAssetBounds::setFactsCache(new PngAssetFactsCache($this->directory . '/facts'));
        Debug::configure(['log_directory' => $this->directory . '/logs']);
    }

    public function close(): void
    {
        LocalDataFiles::runFileOperation(function (): void {
            try {
                foreach (['warning.log', 'error.log'] as $name) {
                    $path = $this->directory . '/logs/' . $name;
                    if (!is_file($path)) { continue; }
                    $stream = fopen($path, 'rb');
                    if ($stream === false) { throw new RuntimeException('Could not read preview diagnostics.'); }
                    try { stream_copy_to_stream($stream, STDERR); }
                    finally { fclose($stream); }
                }
            } finally {
                $entries = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($entries as $entry) {
                    $path = $entry->getPathname();
                    $entry->isDir() && !$entry->isLink() ? rmdir($path) : unlink($path);
                }
                rmdir($this->directory);
            }
        });
    }
}
