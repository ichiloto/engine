<?php

use Ichiloto\Engine\Progression\TutorialCatalog;
use Ichiloto\Engine\Progression\TutorialProjectReferences;
use Ichiloto\Engine\Progression\TutorialStep;
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

it('keeps authored Guide summaries independent of live instruction steps and absent metadata', function () {
  $source = makeTutorialCatalogTestRecord();
  $old = new TutorialCatalog([$source]);
  $source['summary'] = 'Review the existing menu choices.';
  $withSummary = new TutorialCatalog([$source]);
  $plain = $old->findTutorial('guide.sample');
  $guide = $withSummary->findTutorial('guide.sample');
  expect($plain->summary)->toBeNull()->and($guide->summary)->toBe($source['summary'])
    ->and(serialize($guide->steps))->toBe(serialize($plain->steps))
    ->and($guide->replay)->toBe($plain->replay);
});

it('rejects malformed authored Guide summaries', function (mixed $summary) {
  $source = [...makeTutorialCatalogTestRecord(), 'summary' => $summary];
  expect(fn() => new TutorialCatalog([$source]))->toThrow(InvalidArgumentException::class, 'summary');
})->with(['', '   ', null, false, 4, [[]]]);

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

it('requires written instructions for a reading-only Guide entry', function () {
  $record = array_replace(makeTutorialCatalogTestRecord(), ['replay' => 'read']);
  expect(new TutorialCatalog([$record]))->toBeInstanceOf(TutorialCatalog::class);

  unset($record['steps'][0]['say']);
  expect(fn() => new TutorialCatalog([$record]))->toThrow(InvalidArgumentException::class, 'requires written instructions');
});

it('shares project actor ids and validates real screen references without running the follow-on script', function () {
  $root = createTestDirectory('tutorial-project');
  mkdir($root . '/Data/Actors', 0777, true);
  file_put_contents($root . '/Data/Actors/hero.php', '<?php return ["id" => "actor.hero", "name" => "Hero"];');
  $scene = $root . '/Cutscenes/Cinematics/finish-guide';
  mkdir($scene, 0777, true);
  file_put_contents($scene . '/finish-guide.data.php', '<?php return [];');
  file_put_contents($scene . '/finish-guide.script.php', '<?php throw new RuntimeException("Executed a story script during reference validation.");');

  $references = new TutorialProjectReferences($root);
  expect($references->getIds('actor'))->toBe(['actor.hero'])
    ->and($references->getTargetIds('main-menu', 'party'))->toBe(['member-actor.hero'])
    ->and($references->getTargetIds('summons', 'holders'))->toBe(['assignable', 'member-actor.hero']);

  $record = makeTutorialCatalogTestRecord();
  $record['onFinish'] = 'finish-guide';
  $record['steps'] = [[
    'target' => ['screen' => 'main-menu', 'view' => 'party', 'id' => 'member-actor.hero'],
    'action' => 'confirm', 'until' => ['outcome' => 'menu.member-confirmed', 'subject' => 'actor.hero'],
    'speaker' => 'actor.hero', 'say' => 'Choose someone.',
  ]];
  $references->validateCatalog(new TutorialCatalog([$record]));
  $record['steps'][0]['until']['subject'] = 'Hero';
  expect(fn() => $references->validateCatalog(new TutorialCatalog([$record])))
    ->toThrow(InvalidArgumentException::class, 'unknown subject');
  $record['steps'][0]['target']['id'] = 'member-Hero';
  expect(fn() => $references->validateCatalog(new TutorialCatalog([$record])))
    ->toThrow(InvalidArgumentException::class, 'unknown target');
});

it('validates the live start context against the first target and committed battle outcome', function () {
  $references = new TutorialProjectReferences(createTestDirectory('tutorial-context-catalog'));
  $record = array_replace(makeTutorialCatalogTestRecord(), [
    'start' => 'automatic', 'startContext' => 'battle-command', 'replay' => 'read', 'onFinish' => null,
    'steps' => [[
      'target' => ['screen' => 'battle', 'view' => 'command', 'id' => 'summon'],
      'action' => 'action', 'say' => 'Choose Summon.', 'until' => ['outcome' => 'summon.executed'],
    ]],
  ]);
  $references->validateCatalog(new TutorialCatalog([$record]));
  expect($references->getTargetIds('summons', 'list'))->toContain('assigned-contract');

  $record['steps'][0]['until'] = ['type' => 'event', 'name' => 'available'];
  expect(fn() => $references->validateCatalog(new TutorialCatalog([$record])))
    ->toThrow(InvalidArgumentException::class, 'committed battle action');

  $record['steps'][0]['target']['view'] = 'other';
  expect(fn() => $references->validateCatalog(new TutorialCatalog([$record])))
    ->toThrow(InvalidArgumentException::class, 'owning screen and view');
});

it('applies the shared visible-cell limit to a single tutorial instruction line', function () {
  $record = makeTutorialCatalogTestRecord();
  $record['steps'][0]['say'] = 'A' . str_repeat('a', TutorialStep::MAX_INSTRUCTION_CELLS - 2) . '.';
  expect(new TutorialCatalog([$record]))->toBeInstanceOf(TutorialCatalog::class);
  $record['steps'][0]['say'] .= ' More.';
  expect(fn() => new TutorialCatalog([$record]))->toThrow(InvalidArgumentException::class, 'one line');
  $record['steps'][0]['say'] = "Open the menu.\nChoose a row.";
  expect(fn() => new TutorialCatalog([$record]))->toThrow(InvalidArgumentException::class, 'one line');
});
