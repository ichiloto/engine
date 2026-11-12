<?php

namespace Ichiloto\Engine\Income;

use Assegai\Util\Path;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use InvalidArgumentException;

/**
 * The project's income schedules, read from `assets/Data/income.php`. A
 * project without the file has none, so the feature stays off.
 *
 * @package Ichiloto\Engine\Income
 */
final class IncomeCatalog implements ConfigInterface
{
  /** The project data file, relative to the asset root. */
  public const string FILE = 'Data/income.php';

  /** @var array<string, IncomeSchedule> Keyed by id, in authored order. */
  private array $schedules = [];

  /**
   * @param array<string, mixed> $data The file's payload: `['schedules' => [...]]`.
   * @param string $source Where it was authored, for error messages.
   * @throws InvalidArgumentException When the payload is invalid.
   */
  public function __construct(array $data = [], string $source = 'income')
  {
    foreach (array_keys($data) as $key) {
      if ($key !== 'schedules') {
        throw new InvalidArgumentException("$source has unknown field '$key'.");
      }
    }
    $entries = $data['schedules'] ?? [];
    if (! is_array($entries) || ! array_is_list($entries)) {
      throw new InvalidArgumentException("$source schedules must be an ordered list.");
    }
    foreach ($entries as $index => $entry) {
      $schedule = IncomeSchedule::fromArray($entry, "$source schedule [$index]");
      if (isset($this->schedules[$schedule->id])) {
        throw new InvalidArgumentException("$source schedule [$index] repeats the id '{$schedule->id}'.");
      }
      $this->schedules[$schedule->id] = $schedule;
    }
  }

  /**
   * Loads the project's schedules.
   *
   * @param string|null $filename The file; the project's `assets/Data/income.php` when null.
   * @return self The catalog; empty when the file does not exist.
   */
  public static function fromProject(?string $filename = null): self
  {
    $filename ??= Path::join(Path::getCurrentWorkingDirectory(), 'assets', self::FILE);
    if (! is_file($filename)) {
      return new self();
    }
    $data = require $filename;
    if (! is_array($data)) {
      throw new InvalidArgumentException("$filename must return an array.");
    }
    return new self($data, $filename);
  }

  /** @return array<string, IncomeSchedule> Keyed by id, in authored order. */
  public function getSchedules(): array
  {
    return $this->schedules;
  }

  /** The schedule with this id, or null. */
  public function findSchedule(string $id): ?IncomeSchedule
  {
    return $this->schedules[trim($id)] ?? null;
  }

  /** @inheritDoc */
  public function get(string $path, mixed $default = null): mixed
  {
    return $this->findSchedule($path) ?? $default;
  }

  /** @inheritDoc */
  public function has(string $path): bool
  {
    return $this->findSchedule($path) !== null;
  }

  /** @inheritDoc */
  public function set(string $path, mixed $value): void
  {
    throw new InvalidArgumentException('Income schedules are immutable project content.');
  }

  /** @inheritDoc */
  public function persist(): void {}
}
