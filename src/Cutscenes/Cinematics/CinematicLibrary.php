<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Assegai\Util\Path;
use RuntimeException;

/** Discovers folder-based cinematic assets without booting a game scene. */
final class CinematicLibrary
{
  public function __construct(
    protected ?string $root = null,
  )
  {
    $this->root ??= Path::join(
      Path::getCurrentWorkingDirectory(),
      'assets',
      'Cutscenes',
      'Cinematics',
    );
  }

  /** @return string[] */
  public function ids(): array
  {
    if (! is_dir($this->root)) {
      return [];
    }

    $ids = [];

    foreach (scandir($this->root) ?: [] as $entry) {
      if ($entry !== '.' && $entry !== '..' && is_dir(Path::join($this->root, $entry))) {
        $ids[] = $entry;
      }
    }

    sort($ids, SORT_STRING);
    return $ids;
  }

  public function findById(string $id): ?CinematicDefinition
  {
    $id = trim($id);

    if ($id === '' || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $id) !== 1) {
      return null;
    }

    $directory = Path::join($this->root, $id);
    $dataFile = Path::join($directory, "$id.data.php");
    $scriptFile = Path::join($directory, "$id.script.php");

    if (! is_file($dataFile) || ! is_file($scriptFile)) {
      return null;
    }

    $data = require $dataFile;
    $script = require $scriptFile;

    if (! is_array($data) || ! is_array($script)) {
      throw new RuntimeException(sprintf('Cinematic "%s" source files must return arrays.', $id));
    }

    $definition = CinematicDefinition::fromArrays($data, $script);

    if ($definition->id !== $id) {
      throw new RuntimeException(sprintf(
        'Cinematic folder "%s" contains stable id "%s".',
        $id,
        $definition->id,
      ));
    }

    return $definition;
  }

  public function load(string $id): CinematicDefinition
  {
    return $this->findById($id)
      ?? throw new RuntimeException(sprintf('Cinematic "%s" was not found.', $id));
  }
}
