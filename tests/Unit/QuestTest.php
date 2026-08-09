<?php

use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration;
use Ichiloto\Engine\Quests\Quest;
use Ichiloto\Engine\Quests\QuestLog;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Quests\QuestObjective;
use Ichiloto\Engine\Quests\QuestObjectiveType;

/* Definitions */

it('hydrates a quest from its data-file entry', function () {
  $quest = Quest::fromArray([
    'id' => 'breakfast-duty',
    'name' => 'Breakfast Duty',
    'description' => 'Stock the pantry.',
    'giver' => 'Mom',
    'objectives' => [
      ['type' => 'reach_map', 'target' => 'happyville/town-center'],
      ['type' => 'collect', 'target' => 'S-Mana', 'quantity' => 2],
    ],
    'rewards' => ['gold' => 200, 'experience' => 50, 'items' => ['S-Potion']],
    'prerequisites' => [['type' => 'quest', 'name' => 'intro', 'status' => 'completed']],
  ]);

  expect($quest->id)->toBe('breakfast-duty')
    ->and($quest->name)->toBe('Breakfast Duty')
    ->and($quest->giver)->toBe('Mom')
    ->and($quest->objectives)->toHaveCount(2)
    ->and($quest->objectives[0]->type)->toBe(QuestObjectiveType::REACH_MAP)
    ->and($quest->objectives[1]->quantity)->toBe(2)
    ->and($quest->describeRewards())->toBe('200 G, 50 EXP, S-Potion');
});

it('rejects quests without ids or objectives', function () {
  expect(fn() => Quest::fromArray(['name' => 'Nameless']))->toThrow(InvalidArgumentException::class)
    ->and(fn() => Quest::fromArray(['id' => 'empty']))->toThrow(InvalidArgumentException::class);
});

it('derives objective descriptions from their type', function () {
  $objective = QuestObjective::fromArray(['type' => 'defeat', 'target' => 'Sewer Rat', 'quantity' => 2]);

  expect($objective->description)->toBe('Defeat Sewer Rat x2')
    ->and($objective->matches('sewer rat'))->toBeTrue()
    ->and($objective->matches('Great Wolf'))->toBeFalse();
});

it('rejects objectives with unknown types or blank targets', function () {
  expect(fn() => QuestObjective::fromArray(['type' => 'juggle', 'target' => 'Balls']))->toThrow(InvalidArgumentException::class)
    ->and(fn() => QuestObjective::fromArray(['type' => 'collect', 'target' => ' ']))->toThrow(InvalidArgumentException::class);
});

/* The log */

it('accepts a quest once', function () {
  $log = new QuestLog();

  expect($log->accept('breakfast-duty', 2))->toBeTrue()
    ->and($log->isActive('breakfast-duty'))->toBeTrue()
    ->and($log->accept('breakfast-duty', 2))->toBeFalse()
    ->and($log->getProgress('breakfast-duty'))->toBe([0, 0]);
});

it('tracks per-objective progress', function () {
  $log = new QuestLog();
  $log->accept('breakfast-duty', 2);

  expect($log->setProgress('breakfast-duty', 0, 1))->toBeTrue()
    ->and($log->setProgress('breakfast-duty', 0, 1))->toBeFalse() // unchanged
    ->and($log->setProgress('breakfast-duty', 5, 1))->toBeFalse() // unknown index
    ->and($log->getProgress('breakfast-duty'))->toBe([1, 0]);
});

it('moves completed quests out of the active list', function () {
  $log = new QuestLog();
  $log->accept('breakfast-duty', 1);

  expect($log->markCompleted('breakfast-duty'))->toBeTrue()
    ->and($log->isActive('breakfast-duty'))->toBeFalse()
    ->and($log->isCompleted('breakfast-duty'))->toBeTrue()
    ->and($log->accept('breakfast-duty', 1))->toBeFalse() // completed quests never reactivate
    ->and($log->markCompleted('breakfast-duty'))->toBeFalse();
});

it('round-trips through toArray and fromArray', function () {
  $log = new QuestLog();
  $log->accept('breakfast-duty', 2);
  $log->setProgress('breakfast-duty', 1, 1);
  $log->accept('pest-control', 1);
  $log->markCompleted('pest-control');

  $restored = QuestLog::fromArray($log->toArray());

  expect($restored->isActive('breakfast-duty'))->toBeTrue()
    ->and($restored->getProgress('breakfast-duty'))->toBe([0, 1])
    ->and($restored->isCompleted('pest-control'))->toBeTrue()
    ->and($restored->completed)->toBe(['pest-control']);
});

it('ignores malformed persisted entries', function () {
  $restored = QuestLog::fromArray([
    'active' => ['' => [0], 'ok' => [2, '3'], 'bad' => 'nope'],
    'completed' => ['done', 42, 'done'],
  ]);

  expect($restored->activeQuestIds())->toBe(['ok'])
    ->and($restored->getProgress('ok'))->toBe([2, 3])
    ->and($restored->completed)->toBe(['done']);
});

/* Optional (side) quests */

it('marks a quest optional from its data-file entry', function () {
  $side = Quest::fromArray([
    'id' => 'lost-cat',
    'name' => 'Lost Cat',
    'description' => 'Whiskers wandered off again.',
    'optional' => true,
    'objectives' => [['type' => 'talk_to', 'target' => 'Whiskers']],
    'rewards' => ['gold' => 50],
  ]);
  $main = Quest::fromArray([
    'id' => 'breakfast-duty',
    'name' => 'Breakfast Duty',
    'objectives' => [['type' => 'collect', 'target' => 'S-Mana']],
  ]);

  expect($side->isOptional)->toBeTrue()
    ->and($main->isOptional)->toBeFalse()
    ->and($side->describeOffer())->toBe("Whiskers wandered off again.\nReward: 50 G\nAccept this quest?");
});

it('offers a side quest and honours the answer', function () {
  $manager = new OfferRecordingQuestManager([
    'lost-cat' => Quest::fromArray([
      'id' => 'lost-cat',
      'name' => 'Lost Cat',
      'optional' => true,
      'objectives' => [['type' => 'talk_to', 'target' => 'Whiskers']],
    ]),
  ]);

  $manager->answer = false;

  expect($manager->acceptQuest('lost-cat'))->toBeFalse()
    ->and($manager->offered)->toBe(['lost-cat'])
    ->and($manager->log->isActive('lost-cat'))->toBeFalse()
    ->and($manager->declined)->toBe(['quest_declined:lost-cat']);

  // Declining leaves it offerable, so the giver can ask again.
  $manager->answer = true;

  expect($manager->acceptQuest('lost-cat'))->toBeTrue()
    ->and($manager->offered)->toBe(['lost-cat', 'lost-cat'])
    ->and($manager->log->isActive('lost-cat'))->toBeTrue();
});

it('never re-offers a quest already in the journal', function () {
  $manager = new OfferRecordingQuestManager([
    'lost-cat' => Quest::fromArray([
      'id' => 'lost-cat',
      'name' => 'Lost Cat',
      'optional' => true,
      'objectives' => [['type' => 'talk_to', 'target' => 'Whiskers']],
    ]),
  ]);

  expect($manager->acceptQuest('lost-cat'))->toBeTrue()
    ->and($manager->acceptQuest('lost-cat'))->toBeFalse()
    ->and($manager->offered)->toBe(['lost-cat']);
});

it('accepts a main-story quest without asking', function () {
  $manager = new OfferRecordingQuestManager([
    'breakfast-duty' => Quest::fromArray([
      'id' => 'breakfast-duty',
      'name' => 'Breakfast Duty',
      'objectives' => [['type' => 'collect', 'target' => 'S-Mana']],
    ]),
  ]);

  expect($manager->acceptQuest('breakfast-duty'))->toBeTrue()
    ->and($manager->offered)->toBe([])
    ->and($manager->log->isActive('breakfast-duty'))->toBeTrue();
});

it('grants an optional quest outright when the author asks it to', function () {
  $manager = new OfferRecordingQuestManager([
    'lost-cat' => Quest::fromArray([
      'id' => 'lost-cat',
      'name' => 'Lost Cat',
      'optional' => true,
      'objectives' => [['type' => 'talk_to', 'target' => 'Whiskers']],
    ]),
  ]);

  $manager->answer = false;

  expect($manager->acceptQuest('lost-cat', offer: false))->toBeTrue()
    ->and($manager->offered)->toBe([])
    ->and($manager->log->isActive('lost-cat'))->toBeTrue();
});

/**
 * Drives the offer flow without a running game: the prompt, the notification,
 * and the world-state reads all belong to a live scene.
 */
class OfferRecordingQuestManager extends QuestManager
{
  public array $offered = [];
  public array $declined = [];
  public bool $answer = true;

  public function __construct(array $quests)
  {
    $this->quests = $quests;
    $this->log = new QuestLog();
  }

  protected function confirmAcceptance(Quest $quest): bool
  {
    $this->offered[] = $quest->id;

    return $this->answer;
  }

  protected function recordQuestDeclined(Quest $quest): void
  {
    $this->declined[] = sprintf('quest_declined:%s', $quest->id);
  }

  protected function meetsPrerequisites(Quest $quest): bool
  {
    return true;
  }

  public function refreshStatefulObjectives(bool $quiet = false): void
  {
    // Needs a live scene; irrelevant to the offer flow.
  }

  protected function notifyQuest(string $title, string $text, NotificationDuration $duration): void
  {
    // Needs a live game; irrelevant to the offer flow.
  }
}
