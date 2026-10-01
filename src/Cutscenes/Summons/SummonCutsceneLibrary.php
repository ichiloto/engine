<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

use JsonException;
use RuntimeException;
use Throwable;
use Ichiloto\Engine\Util\Debug;

/**
 * Loads summon cutscenes from folder-based assets.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonCutsceneLibrary
{
  /** @var SummonCutsceneDefinition[]|null */
  private ?array $battleDefinitions = null;
  /** @var array<string, SummonCompiledCutscene|null> */
  private array $battleCompiled = [];

  public function __construct(
    protected string $assetPath = 'Cutscenes/Summons',
    protected ?SummonCutsceneCompiler $compiler = null,
    protected bool $cacheForBattle = false,
  )
  {
    $this->compiler ??= new SummonCutsceneCompiler();
  }

  /**
   * @return SummonCutsceneDefinition[]
   */
  public function load(): array
  {
    if ($this->cacheForBattle && $this->battleDefinitions !== null) {
      return $this->battleDefinitions;
    }
    $definitions = [];

    foreach ($this->getCutsceneDirectories() as $directory) {
      try {
        $definition = $this->loadFromDirectory($directory);
        if ($definition instanceof SummonCutsceneDefinition) {
          $definitions[] = $definition;
        }
      } catch (Throwable $error) {
        // A bad optional presentation must not hide valid summon commands.
        Debug::warn(sprintf('Summon cutscene %s could not be loaded: %s', basename($directory), $error->getMessage()));
      }
    }

    usort($definitions, static fn(SummonCutsceneDefinition $left, SummonCutsceneDefinition $right): int => $left->name <=> $right->name);

    return $this->cacheForBattle ? ($this->battleDefinitions = $definitions) : $definitions;
  }

  public function findById(string $id): ?SummonCutsceneDefinition
  {
    if ($this->cacheForBattle) {
      foreach ($this->load() as $definition) {
        if ($definition->id === trim($id)) { return $definition; }
      }
      return null;
    }
    $directory = $this->resolveRootPath() . DIRECTORY_SEPARATOR . trim($id);

    return is_dir($directory)
      ? $this->loadFromDirectory($directory)
      : null;
  }

  public function findByLinkedActionId(string $actionId): ?SummonCutsceneDefinition
  {
    $normalizedActionId = trim($actionId);

    foreach ($this->load() as $definition) {
      if ($definition->linkedActionId === $normalizedActionId) {
        return $definition;
      }
    }

    return null;
  }

  public function loadCompiledOrCompileByLinkedActionId(string $actionId): ?SummonCompiledCutscene
  {
    $definition = $this->findByLinkedActionId($actionId);

    if (! $definition instanceof SummonCutsceneDefinition) {
      return null;
    }

    return $this->loadCompiledOrCompile($definition->id);
  }

  public function loadCompiledOrCompile(string $id): ?SummonCompiledCutscene
  {
    if ($this->cacheForBattle && array_key_exists($id, $this->battleCompiled)) {
      return $this->battleCompiled[$id];
    }
    $definition = $this->findById($id);
    if (! $definition instanceof SummonCutsceneDefinition) {
      return $this->cacheForBattle ? ($this->battleCompiled[$id] = null) : null;
    }

    $compiled = $this->loadCompiled($id);
    if (! $compiled instanceof SummonCompiledCutscene) {
      $compiled = $this->compiler->compile($definition);
      return $this->cacheForBattle ? ($this->battleCompiled[$id] = $compiled) : $compiled;
    }

    try {
      $expectedHash = sha1(json_encode($definition->toSourceArray(), JSON_THROW_ON_ERROR));
    } catch (JsonException) {
      return $this->cacheForBattle ? ($this->battleCompiled[$id] = $compiled) : $compiled;
    }

    if ($compiled->sourceHash !== $expectedHash) {
      $compiled = $this->compiler->compile($definition);
      return $this->cacheForBattle ? ($this->battleCompiled[$id] = $compiled) : $compiled;
    }

    return $this->cacheForBattle ? ($this->battleCompiled[$id] = $compiled) : $compiled;
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
    if (str_starts_with($this->assetPath, DIRECTORY_SEPARATOR)) {
      return $this->assetPath;
    }

    return getcwd() . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $this->assetPath;
  }
}
