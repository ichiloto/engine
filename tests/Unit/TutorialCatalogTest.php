<?php

use Ichiloto\Engine\Progression\TutorialCatalog;
use Ichiloto\Engine\Progression\TutorialStartMode;

function makeTutorialCatalogTestRecord(): array
{
  return [
    'id' => 'guide.sample',
    'category' => 'field',
    'title' => 'Guide',
    'start' => 'script',
    'replay' => 'practice',
    'onFinish' => 'finish_guide',
    'steps' => [
      [
        'target' => ['screen' => 'menu', 'view' => 'root', 'id' => 'sample'],
        'action' => 'confirm',
        'until' => ['outcome' => 'menu.opened', 'subject' => 'sample'],
        'say' => 'Open the menu.',
      ],
      [
        'target' => ['screen' => 'menu', 'view' => 'detail', 'id' => 'apply'],
        'action' => 'confirm',
        'until' => ['type' => 'switch', 'name' => 'applied'],
      ],
    ],
  ];
}

it('loads ordered tutorial steps with view-qualified targets and independent completion sources', function () {
  $tutorial = (new TutorialCatalog([makeTutorialCatalogTestRecord()]))->findTutorial('guide.sample');

  expect($tutorial->start)->toBe(TutorialStartMode::SCRIPT)
    ->and($tutorial->steps[0]->until)->toBe(['outcome' => 'menu.opened', 'subject' => 'sample'])
    ->and($tutorial->steps[1]->target['view'])->toBe('detail')
    ->and($tutorial->steps[1]->until)->toBe(['type' => 'switch', 'name' => 'applied'])
    ->and($tutorial->onFinish)->toBe('finish_guide');
});

it('rejects duplicate tutorials and ambiguous steps before loading project data', function () {
  $record = makeTutorialCatalogTestRecord();
  expect(fn() => new TutorialCatalog([$record, $record]))->toThrow(InvalidArgumentException::class);

  $withoutView = $record;
  unset($withoutView['steps'][0]['target']['view']);
  expect(fn() => new TutorialCatalog([$withoutView]))->toThrow(InvalidArgumentException::class);

  $record['steps'][0]['until']['type'] = 'switch';
  expect(fn() => new TutorialCatalog([$record]))->toThrow(InvalidArgumentException::class);
});

it('requires an automatic condition and a demo for Watch replay', function () {
  $record = makeTutorialCatalogTestRecord();
  expect(fn() => new TutorialCatalog([array_replace($record, ['start' => 'automatic'])]))
    ->toThrow(InvalidArgumentException::class)
    ->and(fn() => new TutorialCatalog([array_replace($record, ['replay' => 'demo'])]))
    ->toThrow(InvalidArgumentException::class);
});
