<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Rendering\Assets\AssetHoldRequest;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasFrameBudget;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;

/** An immutable, session-owned composition, not a screenshot or a gameplay snapshot. @internal */
final readonly class RetainedFrame
{
  public function __construct(
    public object $owner,
    public array $values,
    public array $textRows,
    public ?PresentationWorld $world,
    public ?array $viewport,
    public ?PresentationCanvas $canvas,
    public bool $canvasIsOverlay,
    /** @var list<PresentationWorldCanvas> */
    public array $worldCanvases = [],
    /** @var list<AssetHoldRequest> Exact resource intent supplied to the retained sender. */
    public array $assetHolds = [],
  ) {}

  /** @return list<AssetHoldRequest> Leases a visible or frozen composition needs to borrow. */
  public function getReferencedAssetHolds(): array
  {
    if ($this->assetHolds === []) { return []; }
    // World sheets and composite samplers can use multiple regions of their source.
    $wholeAssets = array_fill_keys($this->world?->getTilesetAssetPaths() ?? [], true);
    $regions = [];
    $addSource = static function (string $asset, ?array $region) use (&$wholeAssets, &$regions): void {
      if ($region === null) { $wholeAssets[$asset] = true; return; }
      $regions[$asset][] = $region;
    };
    foreach ($this->values['sprite'] ?? [] as $sprite) {
      $addSource($sprite['asset'], $sprite['sourceRect'] ?? null);
    }
    $roots = array_map(static fn(PresentationWorldCanvas $group): PresentationCanvas => $group->canvas, $this->worldCanvases);
    if ($this->canvas !== null) { $roots[] = $this->canvas; }
    foreach (CanvasFrameBudget::getCanvases($roots) as $canvas) {
      foreach ($canvas->images as $image) { $addSource($image->asset, $image->sourceRect?->toArray()); }
      foreach ($canvas->composites as $composite) {
        foreach ($composite->operations as $operation) {
          foreach ($operation->getAssets() as $asset) { $wholeAssets[$asset] = true; }
        }
      }
    }
    $referenced = array_filter($this->assetHolds, static function (AssetHoldRequest $request) use ($wholeAssets, $regions): bool {
      $asset = $request->resource->asset;
      if (isset($wholeAssets[$asset])) { return true; }
      if (!isset($regions[$asset])) { return false; }
      $rect = $request->resource->sourceRect;
      return $rect === null || array_any($regions[$asset], static fn(array $visible): bool =>
        $rect->x < $visible['x'] + $visible['width'] && $visible['x'] < $rect->x + $rect->width
        && $rect->y < $visible['y'] + $visible['height'] && $visible['y'] < $rect->y + $rect->height);
    });
    $latest = [];
    foreach ($referenced as $request) {
      $key = $request->resource->getKey();
      if ($request->revision > ($latest[$key]->revision ?? 0)) { $latest[$key] = $request; }
    }
    return array_values($latest);
  }
}
