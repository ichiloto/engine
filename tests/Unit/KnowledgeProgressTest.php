<?php

use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Progress\Bestiary;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeDepth;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeProgress;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeProgressService;

/** @return array<string, mixed> */
function knowledgeFixture(): array
{
  return [
    'recordTypes' => ['creature', 'place'],
    'subjects' => [
      [
        'id' => 'creature.wolf',
        'recordType' => 'creature',
        'displayName' => 'Wolf',
        'quickCard' => 'A quick card.',
        'deepCard' => 'A supported observation card.',
        'family' => 'canid',
        'tags' => ['mobile'],
        'habitats' => ['field'],
        'observations' => ['tracks', 'call'],
        'relationships' => [['type' => 'seen-near', 'subject' => 'place.bridge']],
      ],
      [
        'id' => 'place.bridge',
        'recordType' => 'place',
        'displayName' => 'Bridge',
        'quickCard' => 'A noncombat subject.',
      ],
      [
        'id' => 'creature.hidden',
        'recordType' => 'creature',
        'displayName' => 'Hidden Subject',
        'quickCard' => 'Not listed before discovery.',
        'hidden' => true,
      ],
    ],
    'enemyMappings' => [
      'Great Wolf' => 'creature.wolf',
      'Agitated Wolf' => 'creature.wolf',
    ],
    'reports' => [
      [
        'id' => 'report.wolf.initial',
        'subject' => 'creature.wolf',
        'title' => 'Initial report',
        'summary' => 'First interpretation.',
        'disagreesWith' => ['report.wolf.revised'],
      ],
      [
        'id' => 'report.wolf.revised',
        'subject' => 'creature.wolf',
        'title' => 'Revised report',
        'summary' => 'Later interpretation.',
        'disagreesWith' => ['report.wolf.initial'],
      ],
    ],
  ];
}

it('validates project-owned subjects variants noncombat records and public relationships', function () {
  $catalog = new KnowledgeCatalog(knowledgeFixture());

  expect($catalog->subjectForEnemy('Great Wolf')?->id)->toBe('creature.wolf')
    ->and($catalog->subjectForEnemy('Agitated Wolf')?->id)->toBe('creature.wolf')
    ->and($catalog->subject('place.bridge')?->recordType)->toBe('place')
    ->and($catalog->subject('creature.wolf')?->relationships)->toBe([
      ['type' => 'seen-near', 'subject' => 'place.bridge'],
    ]);
});

it('rejects malformed identities unresolved references and private author truth', function () {
  expect(fn() => new KnowledgeCatalog([
    'recordTypes' => ['creature'],
    'subjects' => [[
      'id' => 'Bad Subject',
      'recordType' => 'creature',
      'displayName' => 'Bad',
      'quickCard' => 'Bad.',
    ]],
  ]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new KnowledgeCatalog(['privateTruth' => ['answer' => 'spoiler']]))->toThrow(InvalidArgumentException::class);
});

it('uses one service for battle discovery noncombat observations reports and outcomes', function () {
  $catalog = new KnowledgeCatalog(knowledgeFixture());
  $service = new KnowledgeProgressService($catalog);
  $enemy = (new ReflectionClass(Enemy::class))->newInstanceWithoutConstructor();
  (new ReflectionProperty(Enemy::class, 'name'))->setValue($enemy, 'Great Wolf');
  (new ReflectionProperty(Enemy::class, 'knowledgeSubjectId'))->setValue($enemy, 'creature.wolf');

  expect($service->discoverEnemy($enemy))->toBeTrue()
    ->and($service->recordEnemyOutcome($enemy, 'escaped'))->toBeTrue()
    ->and($service->recordObservation('creature.wolf', 'tracks', 'field.search', 0.65))->toBeTrue()
    ->and($service->unlockReport('creature.wolf', 'report.wolf.initial', 'field.report', 0.7))->toBeTrue()
    ->and($service->amendReport('creature.wolf', 'report.wolf.initial', 'field.followup', 0.8))->toBeFalse()
    ->and($service->supersedeReport('creature.wolf', 'report.wolf.initial', 'report.wolf.revised', 'field.review'))->toBeTrue()
    ->and($service->discoverSubject('place.bridge', 'field.visit'))->toBeTrue()
    ->and($service->progress->depth('creature.wolf'))->toBe(KnowledgeDepth::CONTESTED)
    ->and($service->progress->outcomeCount('creature.wolf', 'encountered'))->toBe(1)
    ->and($service->progress->outcomeCount('creature.wolf', 'escaped'))->toBe(1)
    ->and($service->progress->reportState('creature.wolf', 'report.wolf.initial')['status'])->toBe('superseded')
    ->and($service->progress->reportState('creature.wolf', 'report.wolf.initial')['supersededBy'])->toBe('report.wolf.revised');
});

it('round-trips bounded stable progress without authored display text or hidden denominators', function () {
  $service = new KnowledgeProgressService(new KnowledgeCatalog(knowledgeFixture()));
  $service->apply('discover', ['subject' => 'creature.wolf', 'source' => 'story.event']);
  $service->apply('observe', [
    'subject' => 'creature.wolf',
    'observation' => 'call',
    'source' => 'story.event',
    'confidence' => 0.5,
  ]);
  $service->apply('record_outcome', ['subject' => 'creature.wolf', 'outcome' => 'assisted']);

  $payload = $service->progress->toArray();
  $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
  $restored = KnowledgeProgress::fromArray($payload);

  expect($encoded)->toContain('creature.wolf')
    ->and($encoded)->not->toContain('Wolf')
    ->and($encoded)->not->toContain('quick card')
    ->and($restored->toArray())->toBe($payload)
    ->and(array_map(static fn($subject) => $subject->id, $service->discoveredSubjects()))
    ->toBe(['creature.wolf']);
});

it('keeps the historical Bestiary API as a view over stable knowledge progress', function () {
  $service = new KnowledgeProgressService(new KnowledgeCatalog(knowledgeFixture()));
  $bestiary = new Bestiary($service);

  expect($bestiary->recordSeen('Great Wolf'))->toBeTrue()
    ->and($bestiary->recordSeen('Agitated Wolf'))->toBeFalse()
    ->and($bestiary->recordDefeated('Great Wolf'))->toBeTrue()
    ->and($bestiary->timesSeen('Great Wolf'))->toBe(2)
    ->and($bestiary->timesDefeated('Agitated Wolf'))->toBe(1)
    ->and($service->progress->discoveredSubjectIds())->toBe(['creature.wolf']);
});
