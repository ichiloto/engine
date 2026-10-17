<?php

declare(strict_types=1);

use Ichiloto\Engine\UI\Navigation\DirectionalFocusGraph;
use Ichiloto\Engine\UI\Navigation\DirectionalFocusNode;
use Ichiloto\Engine\UI\Navigation\DirectionalFocusNodeInterface;
use Ichiloto\Engine\UI\Navigation\FocusDirection;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuGraphNodeInterface;

it('shares only navigation data with menu nodes without importing menu membership', function () {
  expect(is_subclass_of(MenuGraphNodeInterface::class, DirectionalFocusNodeInterface::class))->toBeTrue()
    ->and(method_exists(DirectionalFocusNodeInterface::class, 'getMenu'))->toBeFalse()
    ->and(method_exists(MenuGraphNodeInterface::class, 'getMenu'))->toBeTrue()
    ->and(method_exists(MenuGraphNodeInterface::class, 'getTop'))->toBeTrue();
});

it('moves focus along each explicit cardinal edge', function (FocusDirection $direction, string $targetId) {
  $graph = new DirectionalFocusGraph([
    new DirectionalFocusNode('center', up: 'north', right: 'east', down: 'south', left: 'west'),
    new DirectionalFocusNode('north', down: 'center'),
    new DirectionalFocusNode('east', left: 'center'),
    new DirectionalFocusNode('south', up: 'center'),
    new DirectionalFocusNode('west', right: 'center'),
  ], 'center');

  expect($graph->moveFocus($direction))->toBeTrue()
    ->and($graph->getFocusedNode()?->id)->toBe($targetId);
})->with([
  [FocusDirection::UP, 'north'], [FocusDirection::RIGHT, 'east'],
  [FocusDirection::DOWN, 'south'], [FocusDirection::LEFT, 'west'],
]);

it('leaves focus unchanged at an absent edge', function (FocusDirection $direction) {
  $graph = new DirectionalFocusGraph([new DirectionalFocusNode('isolated')], 'isolated');
  $focused = $graph->getFocusedNode();
  expect($graph->moveFocus($direction))->toBeFalse()
    ->and($graph->getFocusedNode())->toBe($focused);
})->with(FocusDirection::cases());

it('follows directed cycles without inventing reverse edges and treats self edges as unchanged', function () {
  $graph = new DirectionalFocusGraph([
    new DirectionalFocusNode('first', up: 'first', right: 'second'),
    new DirectionalFocusNode('second', right: 'third'),
    new DirectionalFocusNode('third', right: 'first'),
  ], 'first');

  expect($graph->moveFocus(FocusDirection::UP))->toBeFalse();
  foreach (['second', 'third', 'first'] as $id) {
    expect($graph->moveFocus(FocusDirection::RIGHT))->toBeTrue()
      ->and($graph->getFocusedNode()?->id)->toBe($id)
      ->and($graph->moveFocus(FocusDirection::LEFT))->toBeFalse();
  }
});

it('sets focus by exact stable ID and refuses unknown targets without changing focus', function () {
  $graph = new DirectionalFocusGraph([
    new DirectionalFocusNode('0'), new DirectionalFocusNode('00'), new DirectionalFocusNode('other'),
  ], '00');
  expect($graph->setFocus('0'))->toBeTrue()
    ->and($graph->getFocusedNode()?->id)->toBe('0')
    ->and($graph->setFocus('0'))->toBeFalse()
    ->and($graph->setFocus('00'))->toBeTrue();
  foreach (['missing', '', ' '] as $id) {
    expect(fn() => $graph->setFocus($id))->toThrow(InvalidArgumentException::class)
      ->and($graph->getFocusedNode()?->id)->toBe('00');
  }
});

it('preserves stable focus through reordering and replaces its navigation data', function () {
  $graph = new DirectionalFocusGraph([
    new DirectionalFocusNode('first', right: 'second'), new DirectionalFocusNode('second'),
  ], 'first');
  $graph->setFocus('second');
  $graph->replaceNodes([
    new DirectionalFocusNode('second', left: 'third'), new DirectionalFocusNode('third'),
  ], 'third');

  expect($graph->getFocusedNode()?->id)->toBe('second')
    ->and($graph->moveFocus(FocusDirection::LEFT))->toBeTrue()
    ->and($graph->getFocusedNode()?->id)->toBe('third');
  $graph->replaceNodes([new DirectionalFocusNode('third', up: 'third')]);
  expect($graph->getFocusedNode()?->id)->toBe('third')
    ->and($graph->moveFocus(FocusDirection::UP))->toBeFalse();
});

it('uses the named fallback rather than list order when the focused ID is removed', function () {
  $graph = new DirectionalFocusGraph([new DirectionalFocusNode('removed')], 'removed');
  $graph->replaceNodes([
    new DirectionalFocusNode('first'), new DirectionalFocusNode('fallback'),
  ], 'fallback');
  expect($graph->getFocusedNode()?->id)->toBe('fallback');
});

it('keeps disabled nodes in navigation and retains focus when availability changes', function () {
  $graph = new DirectionalFocusGraph([
    new DirectionalFocusNode('first', right: 'second'),
    new DirectionalFocusNode('second', enabled: false, left: 'first'),
  ], 'first');
  expect($graph->moveFocus(FocusDirection::RIGHT))->toBeTrue()
    ->and($graph->getFocusedNode()?->id)->toBe('second')
    ->and($graph->getFocusedNode()?->enabled)->toBeFalse()
    ->and($graph->moveFocus(FocusDirection::LEFT))->toBeTrue();
  $graph->replaceNodes([
    new DirectionalFocusNode('first', enabled: false, right: 'second'),
    new DirectionalFocusNode('second', enabled: false, left: 'first'),
  ], 'second');
  expect($graph->getFocusedNode()?->id)->toBe('first')
    ->and($graph->getFocusedNode()?->enabled)->toBeFalse()
    ->and($graph->setFocus('second'))->toBeTrue();
  $graph->replaceNodes([new DirectionalFocusNode('only', enabled: false)], 'only');
  expect($graph->getFocusedNode()?->id)->toBe('only')
    ->and($graph->getFocusedNode()?->enabled)->toBeFalse();
});

it('has no focus in an empty graph and clears prior focus when emptied', function () {
  $graph = new DirectionalFocusGraph();
  expect($graph->getFocusedNode())->toBeNull();
  foreach (FocusDirection::cases() as $direction) {
    expect($graph->moveFocus($direction))->toBeFalse();
  }
  expect(fn() => $graph->setFocus('missing'))->toThrow(InvalidArgumentException::class);
  $graph->replaceNodes([new DirectionalFocusNode('first')], 'first');
  $graph->replaceNodes([]);
  expect($graph->getFocusedNode())->toBeNull()
    ->and($graph->moveFocus(FocusDirection::RIGHT))->toBeFalse();
  $graph->replaceNodes([new DirectionalFocusNode('next')], 'next');
  expect($graph->getFocusedNode()?->id)->toBe('next');
});

it('requires explicit initial focus for a nonempty graph', function () {
  expect(fn() => new DirectionalFocusGraph([new DirectionalFocusNode('first')]))
    ->toThrow(InvalidArgumentException::class)
    ->and(fn() => new DirectionalFocusGraph([], 'missing'))->toThrow(InvalidArgumentException::class);
});

it('rejects malformed replacements atomically even when the old focus survives', function (array $nodes, ?string $fallbackId) {
  $graph = new DirectionalFocusGraph([
    new DirectionalFocusNode('first', right: 'second'), new DirectionalFocusNode('second', left: 'first'),
  ], 'first');
  $focused = $graph->getFocusedNode();

  expect(fn() => $graph->replaceNodes($nodes, $fallbackId))->toThrow(InvalidArgumentException::class)
    ->and($graph->getFocusedNode())->toBe($focused)
    ->and($graph->moveFocus(FocusDirection::RIGHT))->toBeTrue()
    ->and($graph->getFocusedNode()?->id)->toBe('second')
    ->and($graph->moveFocus(FocusDirection::LEFT))->toBeTrue()
    ->and($graph->getFocusedNode())->toBe($focused);
})->with([
  'duplicate ID' => [[new DirectionalFocusNode('first'), new DirectionalFocusNode('first')], 'first'],
  'unknown up' => [[new DirectionalFocusNode('first', up: 'missing')], 'first'],
  'unknown right' => [[new DirectionalFocusNode('first', right: 'missing')], 'first'],
  'unknown down' => [[new DirectionalFocusNode('first', down: 'missing')], 'first'],
  'unknown left' => [[new DirectionalFocusNode('first', left: 'missing')], 'first'],
  'unknown fallback with preserved focus' => [[new DirectionalFocusNode('first')], 'missing'],
  'unknown fallback with removed focus' => [[new DirectionalFocusNode('other')], 'missing'],
  'missing fallback after removing focus' => [[new DirectionalFocusNode('other')], null],
  'empty graph with fallback' => [[], 'first'],
  'non-list definitions' => [[1 => new DirectionalFocusNode('first')], 'first'],
  'untyped definition after a valid node' => [[new DirectionalFocusNode('first'), new stdClass()], 'first'],
]);

it('rejects empty node IDs and empty neighbour IDs instead of treating them as absent', function (string $id, ?string $neighbourId) {
  expect(fn() => new DirectionalFocusNode($id, right: $neighbourId))->toThrow(InvalidArgumentException::class);
})->with([['', null], [" \t\n", null], ['valid', ''], ['valid', " \t\n"]]);

it('owns immutable snapshots rather than retaining caller definitions or lists', function () {
  $definition = new class implements DirectionalFocusNodeInterface {
    public string $id = 'first';
    public bool $enabled = true;
    public ?string $right = 'second';

    public function getNeighbourId(FocusDirection $direction): ?string
    {
      return $direction === FocusDirection::RIGHT ? $this->right : null;
    }
  };
  $nodes = [$definition, new DirectionalFocusNode('second')];
  $graph = new DirectionalFocusGraph($nodes, 'first');
  $focused = $graph->getFocusedNode();
  expect($focused)->toBeInstanceOf(DirectionalFocusNode::class)->not->toBe($definition);
  $definition->id = 'renamed';
  $definition->enabled = false;
  $definition->right = 'missing';
  $nodes[1] = new DirectionalFocusNode('replacement');

  expect($focused?->id)->toBe('first')
    ->and($focused?->enabled)->toBeTrue()
    ->and($focused?->getNeighbourId(FocusDirection::RIGHT))->toBe('second')
    ->and(fn() => $focused->id = 'corrupted')->toThrow(Error::class)
    ->and(fn() => $focused->enabled = false)->toThrow(Error::class)
    ->and(fn() => $focused->right = 'missing')->toThrow(Error::class)
    ->and($graph->moveFocus(FocusDirection::RIGHT))->toBeTrue()
    ->and($graph->getFocusedNode()?->id)->toBe('second');
});

it('validates mutable contract implementations before replacing the owned snapshot', function (string $id, ?string $neighbourId) {
  $definition = new class($id, $neighbourId) implements DirectionalFocusNodeInterface {
    public bool $enabled = true;

    public function __construct(public string $id, public ?string $right) {}

    public function getNeighbourId(FocusDirection $direction): ?string
    {
      return $direction === FocusDirection::RIGHT ? $this->right : null;
    }
  };
  $graph = new DirectionalFocusGraph([new DirectionalFocusNode('first')], 'first');
  $focused = $graph->getFocusedNode();
  expect(fn() => $graph->replaceNodes([$definition], 'first'))->toThrow(InvalidArgumentException::class)
    ->and($graph->getFocusedNode())->toBe($focused);
})->with([['', null], [' ', null], ['first', ''], ['first', ' '], ['first', 'missing']]);

it('builds stable spatial neighbours without importing gameplay connections',function() {
  $graph=DirectionalFocusGraph::createFromPositions(['center'=>[0,0],'north'=>[0,-1],
    'east'=>[2,0],'diagonal'=>[1,3],'0'=>[0,5]],'center');
  expect($graph->moveFocus(FocusDirection::UP))->toBeTrue()->and($graph->getFocusedNode()->id)->toBe('north');
  $graph->setFocus('center');
  expect($graph->moveFocus(FocusDirection::RIGHT))->toBeTrue()->and($graph->getFocusedNode()->id)->toBe('east');
  $graph->setFocus('0');
  expect($graph->moveFocus(FocusDirection::DOWN))->toBeFalse();
});

it('refuses malformed spatial coordinates before constructing a focus graph',function(array $point) {
  expect(fn()=>DirectionalFocusGraph::createFromPositions(['a'=>$point],'a'))->toThrow(InvalidArgumentException::class);
})->with([[['1',2]],[[INF,0]],[[NAN,1]],[[1]],[[1,2,3]]]);

it('keeps spatial navigation finite across very large supported coordinates',function() {
  $graph=DirectionalFocusGraph::createFromPositions(['west'=>[-PHP_FLOAT_MAX,0],'east'=>[PHP_FLOAT_MAX,0]],'west');
  expect($graph->moveFocus(FocusDirection::RIGHT))->toBeTrue()->and($graph->getFocusedNode()->id)->toBe('east');
});
