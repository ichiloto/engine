<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

use JsonException;
use RuntimeException;

/**
 * Loads summon cutscenes from folder-based assets.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonCutsceneLibrary
{
  public function __construct(
    protected string $assetPath = 'Cutscenes/Summons',
    protected ?SummonCutsceneCompiler $compiler = null,
  )
  {
    $this->compiler ??= new SummonCutsceneCompiler();
  }

  /**
   * @return SummonCutsceneDefinition[]
   */
  public function load(): array
  {
    $definitions = [];

    foreach ($this->getCutsceneDirectories() as $directory) {
      $definition = $this->loadFromDirectory($directory);
      if ($definition instanceof SummonCutsceneDefinition) {
        $definitions[] = $definition;
      }
    }

    usort($definitions, static fn(SummonCutsceneDefinition $left, SummonCutsceneDefinition $right): int => $left->name <=> $right->name);

    return $definitions;
  }

  public function findById(string $id): ?SummonCutsceneDefinition
  {
    $directory = $this->resolveRootPath() . DIRECTORY_SEPARATOR . trim($id);

    return is_dir($directory)
      ? $this->loadFromDirectory($directory)
      : null;
  }

  public function loadCompiledOrCompile(string $id): ?SummonCompiledCutscene
  {
    $definition = $this->findById($id);
    if (! $definition instanceof SummonCutsceneDefinition) {
      return null;
    }

    $compiled = $this->loadCompiled($id);
    if (! $compiled instanceof SummonCompiledCutscene) {
      return $this->compiler->compile($definition);
    }

    try {
      $expectedHash = sha1(json_encode($definition->toSourceArray(), JSON_THROW_ON_ERROR));
    } catch (JsonException) {
      return $compiled;
    }

    if ($compiled->sourceHash !== $expectedHash) {
      return $this->compiler->compile($definition);
    }

    return $compiled;
  }

  public function loadCompiled(string $id): ?SummonCompiledCutscene
  {
    $directory = $this->resolveRootPath() . DIRECTORY_SEPARATOR . trim($id);
    $compiledPath = $directory . DIRECTORY_SEPARATOR . basename($directory) . '.compiled.php';

    if (! file_exists($compiledPath)) {
      return null;
    }

    $payload = require $compiledPath;

    return is_array($payload)
      ? SummonCompiledCutscene::fromArray($payload)
      : null;
  }

  /**
   * @return string[]
   */
  protected function getCutsceneDirectories(): array
  {
    $directories = glob($this->resolveRootPath() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);

    return is_array($directories)
      ? array_values($directories)
      : [];
  }

  protected function loadFromDirectory(string $directory): ?SummonCutsceneDefinition
  {
    $baseName = basename($directory);
    $dataPath = $directory . DIRECTORY_SEPARATOR . $baseName . '.data.php';
    $timelinePath = $directory . DIRECTORY_SEPARATOR . $baseName . '.timeline.php';

    if (! file_exists($dataPath) || ! file_exists($timelinePath)) {
      return null;
    }

    $data = require $dataPath;
    $timeline = require $timelinePath;

    if (! is_array($data) || ! is_array($timeline)) {
      throw new RuntimeException('Summon cutscene source files must return arrays.');
    }

    return SummonCutsceneDefinition::fromArrays($data, $timeline);
  }

  protected function resolveRootPath(): string
  {
    if (str_starts_with($this->assetPath, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\/', $this->assetPath) === 1) {
      return $this->assetPath;
    }

    return getcwd() . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $this->assetPath;
  }
}
