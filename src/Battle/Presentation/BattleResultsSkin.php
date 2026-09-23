<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use InvalidArgumentException;

/** Project-owned visual slots, independent of rewards and playback. */
final readonly class BattleResultsSkin
{
  /** @var array<string, CanvasNineSlice> */
  public array $textures;
  /** @var array<string, PresentationColor> */
  public array $colors;
  /** @var array<string, array{menu: ?CanvasNineSlice, bust: ?CanvasNineSlice}> */
  public array $portraits;
  /** @var array<string, CanvasNineSlice> */
  public array $icons;

  public function __construct(array $textures, array $colors, array $portraits = [], array $icons = [], public ?string $assetRoot = null)
  {
    $this->textures = self::roles($textures, CanvasNineSlice::class,
      ['panel', 'quiet', 'track', 'selector', 'portrait', 'exp', 'divider', 'button']);
    $this->colors = self::roles($colors, PresentationColor::class,
      ['text', 'muted', 'accent', 'positive', 'negative', 'ink']);
    $copy = [];
    foreach ($portraits as $id => $families) {
      if (!is_string($id) || trim($id) === '' || !is_array($families)
        || array_diff(array_keys($families), ['menu', 'bust']) !== []) {
        throw new InvalidArgumentException('Results portraits require actor IDs and separate menu/bust families.');
      }
      foreach (['menu', 'bust'] as $family) {
        $art = $families[$family] ?? null;
        if ($art !== null) { self::plainImage($art); }
        $copy[$id][$family] = $art;
      }
    }
    foreach ($icons as $category => $art) {
      if (!is_string($category) || trim($category) === '') {
        throw new InvalidArgumentException('Results icon categories must be nonempty strings.');
      }
      self::plainImage($art);
    }
    foreach ($textures as $texture) {
      if ($texture->density !== 1) {
        throw new InvalidArgumentException('Results use one-density PNG assets only.');
      }
    }
    $this->portraits = $copy;
    $this->icons = $icons;
  }

  /** Portraits and icons are whole images; their source sizes belong to the files, not the catalog. */
  public function withCurrentImages(string $root): self
  {
    $resolve = static function (CanvasNineSlice $art) use ($root): ?CanvasNineSlice {
      if (PngAssetPreflight::getAvailableSize($root, $art->asset) === null) { return null; }
      $current = CanvasNineSlice::getFromPng($root, $art->asset);
      if ($current->source != $art->source) {
        Debug::warn("Results whole-image metadata reconciled to the current PNG: {$art->asset}");
      }
      return $current;
    };
    $portraits = [];
    foreach ($this->portraits as $id => $families) {
      foreach ($families as $family => $art) {
        $portraits[$id][$family] = $art === null ? null : $resolve($art);
      }
    }
    return new self($this->textures, $this->colors, $portraits, array_filter(array_map($resolve, $this->icons)), $root);
  }

  private static function plainImage(mixed $art): void
  {
    if (!$art instanceof CanvasNineSlice || $art->density !== 1
      || $art->left + $art->top + $art->right + $art->bottom !== 0) {
      throw new InvalidArgumentException('Results portraits and icons require a plain one-density image.');
    }
  }

  private static function roles(array $entries, string $type, array $roles): array
  {
    if (count($entries) !== count($roles)) {
      throw new InvalidArgumentException('Results skin must declare exactly the supported roles.');
    }
    foreach ($roles as $role) {
      if (!($entries[$role] ?? null) instanceof $type) {
        throw new InvalidArgumentException("Results skin requires a typed {$role} entry.");
      }
    }
    return $entries;
  }
}
