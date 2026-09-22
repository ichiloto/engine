<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use InvalidArgumentException;
use RuntimeException;

/** Project-owned registration and artwork; no project drawing callbacks or image byte locks. */
final readonly class TitlePresentationCatalog
{
  public const string FILE = 'Data/Presentation/title.php';
  /** Default logical registrations: logo x/y/width, menu x/y/width/height, button x/y/width/height/gap. */
  public const array DEFAULT_LOGO_PLACEMENT = [409, 16, 532];
  public const array DEFAULT_MENU = [507, 374, 336, 292];
  public const array DEFAULT_BUTTONS = [24, 20, 288, 44, 8];
  public const array CAPABILITIES = [...MenuPresentationCatalog::CAPABILITIES, RendererSessionConfig::CANVAS_COMPOSITING,
    RendererSessionConfig::WINDOW_ACTIVATION];
  public MenuPresentationCatalog $theme;
  public array $scenes;
  public string $logo;
  public CanvasRectangle $menu;
  public array $logoPlacement;
  public array $buttons;
  public ?array $gleam;

  public function __construct(public string $assetRoot, array $data)
  {
    if (($data['schema'] ?? null) !== 'ichiloto.title/1'
      || array_diff(array_keys($data), ['schema', 'theme', 'scenes', 'logo', 'logoPlacement', 'menu', 'buttons', 'gleam']) !== []) {
      throw new InvalidArgumentException('Title catalog requires schema ichiloto.title/1 and supported fields.');
    }
    $this->theme = new MenuPresentationCatalog($assetRoot, $data['theme'] ?? []);
    $this->logo = $data['logo'] ?? '';
    SpriteValidation::validateAssetPath($this->logo);
    $this->logoPlacement = self::getNumbers($data['logoPlacement'] ?? self::DEFAULT_LOGO_PLACEMENT, 3);
    [$x, $y, $width] = $this->logoPlacement;
    if ($x < 0 || $y < 0 || $width <= 0 || $x + $width > PresentationCanvas::DEFAULT_WIDTH || $y >= PresentationCanvas::DEFAULT_HEIGHT) {
      throw new InvalidArgumentException('Title logo registration must fit the logical canvas.');
    }
    $this->menu = new CanvasRectangle(...self::getNumbers($data['menu'] ?? self::DEFAULT_MENU, 4));
    $this->menu->assertWithin(PresentationCanvas::DEFAULT_WIDTH, PresentationCanvas::DEFAULT_HEIGHT);
    if ($y >= $this->menu->y) { throw new InvalidArgumentException('Title logo must have room above its menu.'); }
    $this->buttons = self::getNumbers($data['buttons'] ?? self::DEFAULT_BUTTONS, 5);
    [$bx, $by, $bw, $bh, $gap] = $this->buttons;
    if (min($bx, $by, $gap) < 0 || min($bw, $bh) <= 0 || $bx + $bw > $this->menu->width
      || $by + $bh > $this->menu->height) {
      throw new InvalidArgumentException('Title buttons must fit their panel.');
    }
    $scenes = $data['scenes'] ?? [];
    if (!is_array($scenes) || count($scenes) !== 2 || !isset($scenes['day'], $scenes['night'])) {
      throw new InvalidArgumentException('Title requires day and night scene roles.');
    }
    foreach ($scenes as $scene) {
      if (!is_array($scene) || array_diff(array_keys($scene), ['background', 'sprites', 'effects']) !== []) {
        throw new InvalidArgumentException('Title scenery requires a background and optional sprite registrations.');
      }
      SpriteValidation::validateAssetPath($scene['background'] ?? '');
      TitleAmbientPresentation::getOperations($scene['background'], $scene['effects'] ?? [], 0, 1);
      if (!is_array($scene['sprites'] ?? [])) { throw new InvalidArgumentException('Title sprites must be an array.'); }
      if (count($scene['sprites'] ?? []) > 32) { throw new InvalidArgumentException('Title supports at most 32 sprite subjects per scene.'); }
      foreach ($scene['sprites'] ?? [] as $id => $sprite) {
        TitleSpritePresentation::validate($assetRoot, (string)$id, $sprite);
      }
    }
    $this->scenes = $scenes;
    $this->gleam = $data['gleam'] ?? null;
    if ($this->gleam !== null) {
      TitleGleamPresentation::getComposite(new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage('logo',
        $this->logo, new CanvasRectangle(0, 0, $width, min(PresentationCanvas::DEFAULT_HEIGHT, $width))), $this->gleam, 0, true);
    }
  }

  public static function getRequestedCapabilities(string $root): array
  {
    return file_exists($root . '/' . self::FILE) || is_link($root . '/' . self::FILE)
      ? self::CAPABILITIES : [];
  }

  public static function load(string $root): ?self
  {
    if (!file_exists($root . '/' . self::FILE) && !is_link($root . '/' . self::FILE)) { return null; }
    $root = realpath($root);
    $path = $root === false ? false : realpath($root . '/' . self::FILE);
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
      || !is_file($path) || !is_readable($path)) {
      throw new RuntimeException('Title presentation must be a readable file inside assets.');
    }
    $data = (static fn(string $file): mixed => require $file)($path);
    if (!is_array($data)) { throw new RuntimeException('Title presentation must return a plain array.'); }
    return new self($root, $data);
  }

  public static function getNumbers(mixed $values, int $count): array
  {
    if (!is_array($values) || !array_is_list($values) || count($values) !== $count
      || !array_all($values, static fn($n) => (is_int($n) || is_float($n)) && is_finite((float)$n))) {
      throw new InvalidArgumentException('Title registration requires finite numeric coordinates.');
    }
    return $values;
  }
}
