<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use InvalidArgumentException;
use RuntimeException;

/** Engine-owned tool chrome. Game artwork remains owned by each preview's presenter. */
final readonly class ToolTheme
{
  public const string SCHEMA = 'ichiloto.tool-theme/1';
  public const string FILE = __DIR__ . '/../../../resources/ui/ichiloto-tool-theme.json';

  /** @var array<string, PresentationColor> */
  private array $colors;
  /** @var array<string, int> */
  private array $terminal;

  public function __construct(array $data)
  {
    $names = array_column(ToolColor::cases(), 'value');
    if (($data['schema'] ?? null) !== self::SCHEMA || count($data) !== 3
      || !is_array($data['colors'] ?? null) || !is_array($data['terminal'] ?? null)
      || array_diff($names, array_keys($data['colors'])) !== []
      || array_diff(array_keys($data['colors']), $names) !== []
      || array_diff($names, array_keys($data['terminal'])) !== []
      || array_diff(array_keys($data['terminal']), $names) !== []) {
      throw new InvalidArgumentException('Ichiloto tool theme requires its versioned schema and every semantic colour and Terminal mapping.');
    }
    $colors = [];
    $terminal = [];
    foreach ($data['colors'] as $name => $hex) {
      if (!is_string($hex) || preg_match('/^#[a-fA-F0-9]{6}$/D', $hex) !== 1) {
        throw new InvalidArgumentException('Tool colours must be six-digit hexadecimal RGB strings.');
      }
      $rgb = (int)hexdec(substr($hex, 1));
      $colors[$name] = PresentationColor::rgb(($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255);
      $index = $data['terminal'][$name];
      if (!is_int($index) || $index < 0 || $index > 15) {
        throw new InvalidArgumentException('Tool Terminal colours must be ANSI indexes in 0..15.');
      }
      $terminal[$name] = $index;
    }
    $this->colors = $colors;
    $this->terminal = $terminal;
  }

  public static function load(?string $path = null): self
  {
    $path ??= self::FILE;
    if (!is_file($path) || !is_readable($path)) {
      throw new RuntimeException('Ichiloto tool theme must be a readable Engine resource.');
    }
    $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($data)) { throw new InvalidArgumentException('Tool theme must be a JSON object.'); }
    return new self($data);
  }

  public function getColor(ToolColor $role): PresentationColor
  {
    return $this->colors[$role->value];
  }

  public function getTerminalColor(ToolColor $role): Color
  {
    return match ($this->terminal[$role->value]) {
      0 => Color::BLACK, 1 => Color::RED, 2 => Color::GREEN, 3 => Color::BROWN,
      4 => Color::BLUE, 5 => Color::PURPLE, 6 => Color::CYAN, 7 => Color::LIGHT_GRAY,
      8 => Color::DARK_GRAY, 9 => Color::LIGHT_RED, 10 => Color::LIGHT_GREEN, 11 => Color::YELLOW,
      12 => Color::LIGHT_BLUE, 13 => Color::LIGHT_PURPLE, 14 => Color::LIGHT_CYAN, 15 => Color::WHITE,
    };
  }

  /** The asset root resolves previewed game content; it supplies no tool styling. */
  public function createMenuCatalog(string $assetRoot): MenuPresentationCatalog
  {
    $roles = ['text' => ToolColor::TEXT, 'selected' => ToolColor::SELECTION, 'accent' => ToolColor::ACCENT,
      'focus' => ToolColor::FOCUS, 'disabled' => ToolColor::TEXT_MUTED, 'edge' => ToolColor::BORDER,
      'background' => ToolColor::BACKGROUND, 'panel' => ToolColor::PANEL, 'increase' => ToolColor::SUCCESS,
      'decrease' => ToolColor::DANGER, 'objective' => ToolColor::WARNING, 'muted' => ToolColor::TEXT_MUTED];
    $colors = [];
    foreach ($roles as $name => $role) {
      $rgb = $this->getColor($role)->toArray();
      $colors[$name] = [$rgb['r'], $rgb['g'], $rgb['b']];
    }
    return new MenuPresentationCatalog($assetRoot, ['schema' => 'ichiloto.menu/1',
      'colors' => $colors, 'metrics' => ['panelBorderWidth' => 1]]);
  }
}
