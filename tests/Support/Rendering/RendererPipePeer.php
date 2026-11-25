<?php

declare(strict_types=1);

namespace Tests\Support\Rendering;

/** Runs the existing silent peer through one checked-in executable for all renderer CLI tests. */
final class RendererPipePeer
{
  public static function getExecutablePath(): string
  {
    return dirname(__DIR__, 2) . '/Fixtures/Renderer/renderer-pipe-peer.sh';
  }

  /** @return array<string, string> */
  public static function getEnvironment(string $scenario, string $capturePath): array
  {
    return array_replace(getenv(), [
      'ICHILOTO_TEST_RENDERER_PHP' => PHP_BINARY,
      'ICHILOTO_TEST_RENDERER_STUB' => dirname(__DIR__, 2) . '/Fixtures/Renderer/renderer-stub.php',
      'ICHILOTO_TEST_RENDERER_SCENARIO' => $scenario,
      'ICHILOTO_TEST_RENDERER_CAPTURE' => $capturePath,
    ]);
  }
}
