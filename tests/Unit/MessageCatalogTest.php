<?php

use Ichiloto\Engine\Localization\MessageCatalog;

it('substitutes positional placeholders', function () {
  expect(MessageCatalog::format('%1 %2 found!', 1000, 'G'))->toBe('1000 G found!')
    ->and(MessageCatalog::format('%1\'s Party', 'Kaelion'))->toBe("Kaelion's Party");
});

it('leaves unmatched placeholders untouched', function () {
  // A partially-argued string still reads intelligibly.
  expect(MessageCatalog::format('%1 of %2', 'Half'))->toBe('Half of %2');
});

it('returns the message unchanged when there is nothing to substitute', function () {
  expect(MessageCatalog::format('No placeholders here', 'unused'))->toBe('No placeholders here')
    ->and(MessageCatalog::format('%1 stays', ))->toBe('%1 stays');
});

it('replaces double-digit placeholders before single-digit ones', function () {
  $arguments = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'TENTH'];

  // %10 must not be consumed by %1 followed by a literal 0.
  expect(MessageCatalog::format('%10', ...$arguments))->toBe('TENTH');
});

it('accepts numeric arguments', function () {
  expect(MessageCatalog::format('%1 EXP and %2 G', 250, 40))->toBe('250 EXP and 40 G');
});

it('falls back to the default when no project config is loaded', function () {
  // The config store is empty in unit tests, which is the same path a
  // project without a messages tree takes.
  expect(MessageCatalog::resolve('confirm.quit', 'Are you sure?'))->toBe('Are you sure?')
    ->and(MessageCatalog::get('obtained_gold', '%1 %2 found!', 10, 'G'))->toBe('10 G found!')
    ->and(MessageCatalog::locale())->toBe('');
});
