<?php

declare(strict_types=1);

use Ichiloto\Engine\Util\PlayerText;
use Ichiloto\Engine\Progression\WorldAbilityCatalog;

it('validates sentence case and punctuation without rewriting international or styled prose', function (string $text) {
  $original = $text;
  PlayerText::assertSentence($text, 'Synthetic description');
  expect($text)->toBe($original);
})->with(['Run faster.', 'A complete sentence!', 'What changed?', '“A quoted sentence.”',
  '<info>Énergie restored.</info>', '日本語の文章。', '10% more speed.', 'A pause…', "First sentence.\n\nSecond paragraph."]);

it('reports malformed sentence prose with its source context', function (string $text, string $reason) {
  expect(fn() => PlayerText::assertSentence($text, 'Synthetic description'))->toThrow(InvalidArgumentException::class, 'Synthetic description ' . $reason);
})->with([
  ['run faster.', 'must start'], ['Run faster', 'must end'], ['“run faster.”', 'must start'],
  ["First sentence.\n\nsecond paragraph.", 'must start'], ['', 'requires a visible'], ["\xFF", 'must be UTF-8'],
]);

it('guards every authored world-ability description and first-use sentence before delivery', function () {
  foreach ([['description' => 'lowercase.'], ['description' => 'Missing full stop'], ['firstUse' => ['message' => 'lowercase.']],
    ['firstUse' => ['message' => 'Missing full stop']]] as $extra) {
    expect(fn() => new WorldAbilityCatalog([['id' => 'synthetic', 'name' => 'Name', ...$extra]]))->toThrow(InvalidArgumentException::class);
  }
  PlayerText::assertSentence('', 'Optional copy', allowEmpty: true);
  expect(new WorldAbilityCatalog([['id' => 'synthetic', 'name' => 'Name']])->getAbility('synthetic')->description)->toBe('');
});
