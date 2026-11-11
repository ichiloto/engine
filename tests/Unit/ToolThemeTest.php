<?php

declare(strict_types=1);

use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\UI\Presentation\{MenuCanvas, MenuPresentationCatalog, MenuThemeMetrics, ToolColor, ToolTheme};
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

it('loads every tool chrome role and its Terminal mapping from one Engine resource', function () {
  $data = json_decode(file_get_contents(ToolTheme::FILE), true, flags: JSON_THROW_ON_ERROR);
  $theme = ToolTheme::load();
  expect(array_keys($data['colors']))->toEqual(array_column(ToolColor::cases(), 'value'));
  foreach (ToolColor::cases() as $role) {
    $rgb = $theme->getColor($role)->toArray();
    expect(sprintf('#%02x%02x%02x', $rgb['r'], $rgb['g'], $rgb['b']))->toBe($data['colors'][$role->value])
      ->and(SgrColorParser::parse($theme->getTerminalColor($role)->value)['foreground']?->toArray())
      ->toBe(['kind' => 'ansi16', 'index' => $data['terminal'][$role->value]]);
  }
});

it('maps a replaceable token resource into the existing menu components without project art', function () {
  $data = json_decode(file_get_contents(ToolTheme::FILE), true, flags: JSON_THROW_ON_ERROR);
  $data['colors']['accent'] = '#123456';
  $data['terminal']['accent'] = 13;
  $path = getTestTemporaryDirectory() . '/tool-theme.json';
  file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
  $theme = ToolTheme::load($path);
  $catalog = $theme->createMenuCatalog(getTestTemporaryDirectory());
  expect($catalog->colors['accent']->toArray())->toBe(['kind' => 'rgb', 'r' => 18, 'g' => 52, 'b' => 86])
    ->and($catalog->frames)->toBe([])->and($catalog->icons)->toBeNull()->and($catalog->controls)->toBe([])
    ->and($catalog->portraits)->toBe([])->and($catalog->rows->artwork)->toBe([])
    ->and($catalog->metrics->panelBorderWidth)->toBe(1)
    ->and(SgrColorParser::parse($theme->getTerminalColor(ToolColor::ACCENT)->value)['foreground']?->toArray()['index'])->toBe(13);
});

it('rejects incomplete, malformed and unknown tool tokens without loading a game theme', function (string $case) {
  $data = json_decode(file_get_contents(ToolTheme::FILE), true, flags: JSON_THROW_ON_ERROR);
  switch ($case) {
    case 'schema': $data['schema'] = 'ichiloto.tool-theme/2'; break;
    case 'missing': unset($data['colors']['accent']); break;
    case 'unknown': $data['colors']['game'] = '#ffffff'; break;
    case 'rgb': $data['colors']['text'] = '#fff'; break;
    case 'ansi': $data['terminal']['text'] = 16; break;
    case 'typed': $data['terminal']['text'] = '7'; break;
    case 'terminal missing': unset($data['terminal']['text']); break;
  }
  expect(fn() => new ToolTheme($data))->toThrow(InvalidArgumentException::class);
})->with(['schema', 'missing', 'unknown', 'rgb', 'ansi', 'typed', 'terminal missing']);

it('draws plain tool borders through shared frame metrics and leaves game defaults unchanged', function () {
  $box = new CanvasRectangle(10, 20, 300, 200);
  $theme = ToolTheme::load()->createMenuCatalog(getTestTemporaryDirectory());
  $view = new MenuCanvas($theme);
  $view->frame('tool-panel', $box);
  $canvas = $view->finish();
  $edges = array_values(array_filter($canvas->textLayers, fn($layer) => str_starts_with($layer->id, 'tool-panel-outline-')));
  expect($edges)->toHaveCount(4);
  foreach ($edges as $edge) { expect($edge->runs[0]->background)->toEqual($theme->colors['edge']); }
  $game = new MenuPresentationCatalog(getTestTemporaryDirectory(), ['schema' => 'ichiloto.menu/1']);
  $view = new MenuCanvas($game); $view->frame('game-panel', $box);
  expect($game->metrics->panelBorderWidth)->toBe(0)
    ->and(array_filter($view->finish()->textLayers, fn($layer) => str_starts_with($layer->id, 'game-panel-outline-')))->toBe([])
    ->and(fn() => new MenuThemeMetrics(panelBorderWidth: -1))->toThrow(InvalidArgumentException::class);
});
