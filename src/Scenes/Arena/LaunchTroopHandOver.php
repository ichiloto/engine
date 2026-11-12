<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use JsonException;
use RuntimeException;

/**
 * Keeps a composed troop by handing it to the editor that launched the
 * battle test, through the launch's own data root: each troop is one JSON
 * file in `kept-troops/`, written whole, which the editor takes in the order
 * they were kept and adds to its troops ({@see takeKept()}). Nothing is
 * written into the project.
 */
final readonly class LaunchTroopHandOver implements BattleTestTroopKeeper
{
  public const string DIRECTORY = 'kept-troops';
  /** The most troops one battle test hands over. */
  public const int MAX_TROOPS = 64;

  public function __construct(private string $dataRoot)
  {
    if (!is_dir($dataRoot)) {
      throw new RuntimeException('A battle test hands troops over through its launch data root, which does not exist.');
    }
  }

  public function keepTroop(array $members): string
  {
    if ($members === [] || !array_is_list($members)) {
      throw new RuntimeException('A troop needs a member; add an enemy first.');
    }
    $directory = $this->dataRoot . DIRECTORY_SEPARATOR . self::DIRECTORY;
    if (!is_dir($directory) && !@mkdir($directory, 0o700) && !is_dir($directory)) {
      throw new RuntimeException('The troop could not be handed to the editor: its folder cannot be made.');
    }
    $kept = glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
    if (count($kept) >= self::MAX_TROOPS) {
      throw new RuntimeException(sprintf('The editor has not taken the %d troops kept already.', count($kept)));
    }
    try {
      $json = json_encode(['enemies' => $members], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (JsonException $error) {
      throw new RuntimeException('The troop could not be handed to the editor: ' . $error->getMessage(), previous: $error);
    }
    // Named by when it was kept, so the editor takes troops in order; written aside, then moved whole into place.
    $name = sprintf('%020d-%s', hrtime(true), bin2hex(random_bytes(4)));
    $partial = $directory . DIRECTORY_SEPARATOR . $name . '.part';
    if (@file_put_contents($partial, $json) !== strlen($json) || !@rename($partial, $directory . DIRECTORY_SEPARATOR . $name . '.json')) {
      @unlink($partial);
      throw new RuntimeException('The troop could not be handed to the editor: its file cannot be written.');
    }
    return 'Sent to the editor, which adds it to Troops.';
  }

  /** How many troops are waiting to be taken. */
  public static function countKept(string $dataRoot): int
  {
    return count(glob($dataRoot . DIRECTORY_SEPARATOR . self::DIRECTORY . DIRECTORY_SEPARATOR . '*.json') ?: []);
  }

  /**
   * The troops a battle test has handed over since last taken, oldest
   * first, each its members; taken, so each is added once. A file that is
   * not a kept troop is left out and removed.
   *
   * @return list<list<array<string, mixed>>>
   */
  public static function takeKept(string $dataRoot): array
  {
    $files = glob($dataRoot . DIRECTORY_SEPARATOR . self::DIRECTORY . DIRECTORY_SEPARATOR . '*.json') ?: [];
    sort($files);
    $troops = [];
    foreach ($files as $file) {
      $data = json_decode((string) @file_get_contents($file), true);
      @unlink($file);
      if (is_array($data) && is_array($data['enemies'] ?? null) && array_is_list($data['enemies']) && $data['enemies'] !== []) {
        $troops[] = $data['enemies'];
      }
    }
    return $troops;
  }
}
