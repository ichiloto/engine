<?php

namespace Ichiloto\Engine\Rendering\Launch;

use JsonException;

/** The sole owner of the internal package manifest layout and host lookup. */
final readonly class PackagedRendererExecutableResolver implements RendererExecutableResolverInterface
{
  private string $platform;

  public function __construct(
    private string $manifestFile = __DIR__ . '/../../../resources/renderers/installed/manifest.json',
    ?string $platform = null,
  ) {
    $this->platform = $platform ?? self::hostPlatform();
  }

  public static function hostPlatform(): string
  {
    $os = strtolower(PHP_OS_FAMILY);
    $architecture = match (strtolower(php_uname('m'))) {
      'arm64', 'aarch64' => 'arm64',
      'x86_64', 'amd64' => 'x64',
      default => strtolower(php_uname('m')),
    };
    return $os . '-' . $architecture;
  }

  public function resolve(string $rendererId): string
  {
    if (!is_file($this->manifestFile) || !is_readable($this->manifestFile)) {
      throw $this->unavailable($rendererId, 'No installed renderer manifest.');
    }
    $contents = file_get_contents($this->manifestFile);
    if ($contents === false) {
      throw $this->unavailable($rendererId, 'Cannot read the installed renderer manifest.');
    }
    try {
      $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
      throw $this->unavailable($rendererId, 'Invalid installed renderer manifest.', $error);
    }
    if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1
      || !is_array($manifest['renderers'] ?? null)) {
      throw $this->unavailable($rendererId, 'Invalid installed renderer manifest.');
    }
    $platforms = $manifest['renderers'][$rendererId] ?? [];
    $relative = is_array($platforms) ? ($platforms[$this->platform] ?? null) : null;
    if (!is_string($relative) || $relative === '') {
      throw $this->unavailable($rendererId, 'No implementation is installed for this platform.');
    }
    // Package entries are relative to the manifest, including app-bundle executables.
    if (str_contains($relative, "\0") || preg_match('~^(?:[/\\\\]|[a-zA-Z]:)|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $relative)) {
      throw $this->unavailable($rendererId, 'Invalid packaged executable entry.');
    }
    $root = realpath(dirname($this->manifestFile));
    $executable = realpath(dirname($this->manifestFile) . DIRECTORY_SEPARATOR . $relative);
    if ($root === false || $executable === false || !str_starts_with($executable, $root . DIRECTORY_SEPARATOR)
      || !is_file($executable) || !is_executable($executable)) {
      throw $this->unavailable($rendererId, 'The packaged executable is missing or not executable.');
    }
    return $executable;
  }

  private function unavailable(string $id, string $reason, ?\Throwable $previous = null): RendererUnavailableException
  {
    return new RendererUnavailableException(sprintf(
      '%s renderer is not available for this installation/platform (%s). %s', strtoupper($id), $this->platform, $reason,
    ), previous: $previous);
  }
}
