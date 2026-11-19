<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Sprites\PngAssetBounds;
use Ichiloto\Engine\Rendering\Sprites\SpriteFactsPreparation;
use Ichiloto\Engine\Util\Debug;

/** Prepares the exact source crops used by battle art without retaining decoded images. */
final class BattleArtworkFacts
{
  /** @var array<string, CanvasImage> */
  private array $images = [];

  public function __construct(private readonly string $assetRoot) {}

  public function addArtwork(BattlerArtwork $artwork): void
  {
    $key = $artwork->asset . ':' . serialize($artwork->sourceRect);
    $this->images[$key] = new CanvasImage('pose-facts', $artwork->asset,
      new CanvasRectangle(0, 0, $artwork->width, $artwork->height), sourceRect: $artwork->sourceRect);
  }

  public function addPose(BattlerPose $pose): void
  {
    foreach ($pose->getDeclaredSourceCells() as $cell) {
      $artwork = $pose->getArtworkForCell($this->assetRoot, $cell);
      if ($artwork !== null) { $this->addArtwork($artwork); }
    }
  }

  public function countCrops(): int
  {
    return count($this->images);
  }

  /** @return list<CanvasImage> */
  public function getImages(): array
  {
    return array_values($this->images);
  }

  public function hasCurrentFacts(bool $memoryOnly = false): bool
  {
    return PngAssetBounds::hasCurrentFacts($this->assetRoot, $this->getImages(), $memoryOnly);
  }

  public function hydrate(int $from, int $deadline): ?int
  {
    return PngAssetBounds::hydrateCurrentFacts($this->assetRoot, $this->getImages(), $from, $deadline);
  }

  public function startPreparation(): SpriteFactsPreparation
  {
    return SpriteFactsPreparation::start($this->assetRoot, $this->getImages());
  }

  public function prepare(): void
  {
    $images = $this->getImages();
    $reported = [];
    foreach (PngAssetBounds::getVisibleBoundsForImages($this->assetRoot, $images) as $index => $result) {
      $asset = $images[$index]->asset;
      if ($result['diagnostic'] === null || isset($reported[$asset])) { continue; }
      Debug::warn('Battle pose facts unavailable: ' . $asset . ': ' . $result['diagnostic']);
      $reported[$asset] = true;
    }
  }
}
