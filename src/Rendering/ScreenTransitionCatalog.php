<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering;

use InvalidArgumentException;
use RuntimeException;

/** Shared named presentation resources. Terminal intros do not load this optional catalogue. */
final readonly class ScreenTransitionCatalog
{
  public const string FILE = 'Data/Presentation/transitions.php';
  /** @var array<string, ScreenTransitionTreatment> */
  public array $treatments;

  public function __construct(array $treatments, public ?string $battle = null)
  {
    $copy = [];
    foreach ($treatments as $key => $treatment) {
      if (!is_string($key) || !$treatment instanceof ScreenTransitionTreatment || $key !== $treatment->id) {
        throw new InvalidArgumentException('Transition catalogue keys must match their stable treatment ids.');
      }
      $copy[$key] = $treatment;
    }
    if ($battle !== null && !isset($copy[$battle])) { throw new InvalidArgumentException('Unknown battle transition treatment.'); }
    $this->treatments = $copy;
  }

  public function getBattleTreatment(): ?ScreenTransitionTreatment
  {
    return $this->battle === null ? null : $this->treatments[$this->battle];
  }

  public function getChoices(): array { return array_combine(array_keys($this->treatments), array_keys($this->treatments)); }

  /** Editor validation checks all authored resources, including unselected treatments. */
  public function validateAssets(string $assetRoot): void
  {
    foreach ($this->treatments as $treatment) { $treatment->validateAssets($assetRoot); }
  }

  public static function load(string $assetRoot): ?self
  {
    $file = $assetRoot . '/' . self::FILE;
    if (!file_exists($file) && !is_link($file)) { return null; }
    $root = realpath($assetRoot);
    $path = realpath($file);
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
      || !is_file($path) || !is_readable($path)) {
      throw new RuntimeException('Transition catalogue must be a readable file inside assets.');
    }
    $catalog = (static fn(string $source): mixed => require $source)($path);
    if (!$catalog instanceof self) { throw new RuntimeException(self::FILE . ' must return a ScreenTransitionCatalog.'); }
    return $catalog;
  }
}
