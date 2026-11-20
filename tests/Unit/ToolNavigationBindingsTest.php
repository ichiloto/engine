<?php

use Ichiloto\Engine\UI\Navigation\{ToolNavigationAction, ToolNavigationBindings, DirectionalFocusGraph, FocusDirection};
use Ichiloto\Engine\IO\{ControlHint, InputManager};
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputSources\TerminalInputSource;
use Ichiloto\Engine\Scenes\Arena\BattleTestMenuList;
use Ichiloto\Engine\Rendering\Transport\{RendererSessionConfig, RendererGridConfig};
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;

it('shares tool actions and hints independently of game bindings and keeps bare Q and Escape distinct from Quit', function () {
  $before = InputManager::getBindings();
  $bindings = new ToolNavigationBindings();
  foreach ([["\t", ToolNavigationAction::NEXT_PANE, KeyCode::TAB], ["\033[Z", ToolNavigationAction::PREVIOUS_PANE, KeyCode::SHIFT_TAB],
    ["\033", ToolNavigationAction::CANCEL, KeyCode::ESCAPE], ["\x11", ToolNavigationAction::QUIT, KeyCode::CTRL_Q]] as [$raw, $action, $key]) {
    $stream = fopen('php://memory', 'w+'); fwrite($stream, $raw); rewind($stream);
    try {
      $source = new TerminalInputSource($stream);
      expect($source->poll())->toBe($key)->and($bindings->findAction($key))->toBe($action)
        ->and($bindings->decodeAction($raw))->toBe($action)
        ->and($bindings->controlForAction($action->value))->toEqual(ControlHint::keyboard($key));
    } finally { fclose($stream); }
  }
  expect($bindings->findAction(KeyCode::q))->toBeNull()->and($bindings->findAction(KeyCode::Q))->toBeNull()
    ->and($bindings->decodeAction("\x11q"))->toBeNull()->and(InputManager::getBindings())->toBe($before);
  $list = new BattleTestMenuList('Tool', [], null, [[ToolNavigationAction::QUIT->value, 'Quit'], [ToolNavigationAction::CANCEL->value, 'Back']]);
  expect(array_map(static fn($hint) => $hint->control?->label, $list->resolveHints()))->toBe(['Ctrl+Q', 'Esc']);
});

it('keeps explicit tool overrides and unbound actions while rejecting ambiguous control ownership', function () {
  $bindings = new ToolNavigationBindings([ToolNavigationAction::NEXT_PANE->value => [KeyCode::F6], ToolNavigationAction::QUIT->value => []]);
  expect($bindings->findAction(KeyCode::TAB))->toBeNull()->and($bindings->findAction(KeyCode::F6))->toBe(ToolNavigationAction::NEXT_PANE)
    ->and($bindings->controlForAction(ToolNavigationAction::QUIT->value))->toBeNull();
  expect(fn() => new ToolNavigationBindings([ToolNavigationAction::NEXT_PANE->value => [KeyCode::ESCAPE]]))
    ->toThrow(InvalidArgumentException::class, 'one action');
});

it('cycles exactly the visible pane IDs in both directions while preserving or explicitly replacing focus', function () {
  $graph = new DirectionalFocusGraph();
  $graph->replaceCycle(['troops', 'party'], 'troops');
  foreach (['party', 'troops', 'party'] as $id) {
    $graph->moveFocus(FocusDirection::RIGHT); expect($graph->getFocusedNode()->id)->toBe($id);
  }
  $graph->replaceCycle(['troops', 'party', 'inspector'], 'troops');
  expect($graph->getFocusedNode()->id)->toBe('party');
  $graph->moveFocus(FocusDirection::LEFT); expect($graph->getFocusedNode()->id)->toBe('troops');
  $graph->moveFocus(FocusDirection::LEFT); expect($graph->getFocusedNode()->id)->toBe('inspector');
  expect(fn() => $graph->replaceCycle(['party', 'party'], 'party'))->toThrow(InvalidArgumentException::class);
  expect($graph->getFocusedNode()->id)->toBe('inspector');
  $graph->replaceCycle(['troops'], 'troops');
  expect($graph->moveFocus(FocusDirection::RIGHT))->toBeFalse();
  $graph->replaceCycle([]); expect($graph->getFocusedNode())->toBeNull();
});

it('negotiates canonical keyboard chords explicitly so older clients do not receive new key identities', function () {
  $grid = new RendererGridConfig(80, 30);
  $root = createTestDirectory('tool-chords');
  $ordinary = new RendererSessionConfig('Ordinary', $root, $grid, protocol: RendererProtocolVersion::V2);
  $tool = new RendererSessionConfig('Tool', $root, $grid, protocol: RendererProtocolVersion::V2,
    requiredCapabilities: [RendererSessionConfig::KEYBOARD_CHORDS]);
  expect($ordinary->getNegotiableCapabilities())->not->toContain(RendererSessionConfig::KEYBOARD_CHORDS)
    ->and($tool->getNegotiableCapabilities())->toContain(RendererSessionConfig::KEYBOARD_CHORDS);
});
