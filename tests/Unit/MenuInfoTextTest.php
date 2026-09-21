<?php

declare(strict_types=1);

use Ichiloto\Engine\UI\Text\MenuInfoText;
use Ichiloto\Engine\UI\Text\TextPage;
use Ichiloto\Engine\UI\Text\TextViewport;

it('cycles whole two-line pages and retains the last partial page without overlap', function () {
  $model = new MenuInfoText();
  $source = "One\nTwo\nThree\nFour\nFive";
  expect($model->advance())->toBeFalse();
  $page = $model->getPage($source, null, 20);
  expect($page)->toBeInstanceOf(TextPage::class)->and($page->lines)->toBe(['One', 'Two'])->and($page->rows)->toBe(2);
  expect($model->advance())->toBeTrue();
  expect($model->getPage($source, null, 20)->lines)->toBe(['Three', 'Four']);
  $model->advance();
  expect($model->getPage($source, null, 20)->lines)->toBe(['Five']);
  $model->advance();
  expect($model->getPage($source, null, 20)->first)->toBe(0);
});

it('does not advance during repeated reads or reflow between renderers', function () {
  $model = new MenuInfoText();
  $source = str_repeat('Complete source words stay readable. ', 12);
  $model->getPage($source, null, 15);
  $model->advance();
  $page = $model->getPage($source, null, 15);
  for ($i = 0; $i < 5; $i++) {
    $model->getPage($source, null, 49);
    expect($model->getPage($source, null, 15))->toEqual($page);
  }
  expect(fn() => $model->lastPage = null)->toThrow(Error::class);
});

it('retains complete descriptions and statuses through Unicode-safe wrapping', function (int $columns) {
  $model = new MenuInfoText();
  $description = "Caf\u{00e9} \u{6f22}\u{5b57}\r\nS-Potion\tmanual " . str_repeat('LongUnbrokenWord', 5);
  $status = "First outcome\n" . str_repeat('Complete outcome. ', 6);
  $first = $model->getPage($description, $status, $columns);
  $seen = [];
  do {
    $page = $model->getPage($description, $status, $columns);
    array_push($seen, ...$page->lines);
    foreach ($page->lines as $line) {
      expect(mb_check_encoding($line, 'UTF-8'))->toBeTrue()->and(mb_strlen($line))->toBeLessThanOrEqual($columns);
    }
    $model->advance();
  } while ($model->getPage($description, $status, $columns)->first !== 0);
  expect($seen)->toBe(array_column(TextViewport::wrap($first->source, $columns), 1))
    ->and($first->source)->toContain('S-Potion', 'Complete outcome.');
})->with([8, 23, 60]);

it('resets changed sources and explicit identity resets even when text matches', function () {
  $model = new MenuInfoText();
  $source = "One\nTwo\nThree\nFour";
  $model->getPage($source, null, 10);
  $model->advance();
  expect($model->getPage($source, null, 10)->first)->toBe(2);
  expect($model->getPage("New\nText\nThree\nFour", null, 10)->first)->toBe(0);
  $model->advance();
  expect($model->getPage("New\nText\nThree\nFour", 'Outcome', 10)->first)->toBe(0);
  $model->advance();
  $model->reset();
  expect($model->lastPage)->toBeNull()->and($model->getPage("New\nText\nThree\nFour", 'Outcome', 10)->first)->toBe(0);
});

it('leaves a successfully measured page intact when a later layout is invalid', function () {
  $model = new MenuInfoText();
  $page = $model->getPage("One\nTwo\nThree", null, 10);
  expect(fn() => $model->getPage('Changed source', null, 0))->toThrow(InvalidArgumentException::class)
    ->and($model->lastPage)->toBe($page);
  $model->advance();
  expect($model->getPage($page->source, null, 10)->lines)->toBe(['Three']);
});

it('does not manufacture extra pages for empty or already fitting content', function () {
  $model = new MenuInfoText();
  expect($model->getPage('', null, 20)->source)->toBe('')->and($model->advance())->toBeFalse();
  expect($model->getPage('', 'Outcome', 20)->lines)->toBe(['Outcome'])->and($model->advance())->toBeFalse();
  expect($model->getPage('Description', 'Outcome', 20)->lines)->toBe(['Description', 'Outcome'])
    ->and($model->advance())->toBeFalse();
});
