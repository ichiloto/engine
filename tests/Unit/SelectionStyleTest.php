<?php

use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

beforeEach(function () {
  $this->selectionStyleConfig = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
});

afterEach(function () {
  if ($this->selectionStyleConfig === null) { ConfigStore::remove(ProjectConfig::class); }
  else { ConfigStore::put(ProjectConfig::class, $this->selectionStyleConfig); }
});

it('preserves the highlight hue and changes its intensity for persistent marked choices', function (Color $color) {
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['ui' => ['menu' => ['selection_color' => $color]]]));
  $focused = SelectionStyle::apply('Choice');
  $marked = SelectionStyle::applyMarked('Choice');
  $focusedIndex = SgrColorParser::parse($focused)['foreground']->toArray()['index'];
  $markedIndex = SgrColorParser::parse($marked)['foreground']->toArray()['index'];
  expect($markedIndex % 8)->toBe($focusedIndex % 8)
    ->and(abs($markedIndex - $focusedIndex))->toBe(8)
    ->and(TerminalText::stripAnsi($marked))->toBe('Choice')
    ->and($marked)->not->toContain("\033[5m")
    ->and($marked)->toEndWith(Color::RESET->value);
})->with(array_map(static fn(Color $color): array => [$color],
  array_filter(Color::cases(), static fn(Color $color): bool => $color !== Color::RESET)));

it('resolves marked colours from current menu settings and the existing battle fallback', function () {
  foreach ([['menu' => ['selection_color' => 'light cyan']], ['battle' => ['selection_color' => Color::LIGHT_CYAN->value]]] as $ui) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['ui' => $ui]));
    expect(SelectionStyle::applyMarked('Choice'))->toBe(Color::CYAN->value . 'Choice' . Color::RESET->value);
  }
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['ui' => ['menu' => ['selection_color' => Color::RED]]]));
  expect(SelectionStyle::applyMarked('Choice'))->toBe(Color::LIGHT_RED->value . 'Choice' . Color::RESET->value);
  ConfigStore::remove(ProjectConfig::class);
  expect(SelectionStyle::applyMarked('Choice'))->toBe(Color::BLUE->value . 'Choice' . Color::RESET->value)
    ->and(Color::RESET->getAlternateIntensity())->toBe(Color::RESET);
});
