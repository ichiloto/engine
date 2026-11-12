<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\{EngineStoryEvent, GameState};
use Ichiloto\Engine\Quests\{Quest, QuestLog, QuestManager};
use Ichiloto\Engine\Quests\Story\StoryContentReader;
use Ichiloto\Engine\Scenes\Game\GameScene;

it('keeps automatic story flags byte-compatible with saved progress and authored conditions', function (EngineStoryEvent $kind, string $expected) {
  expect($kind->getName('subject-id'))->toBe($expected)
    ->and(EngineStoryEvent::usesNamespace($expected))->toBeTrue();
  $state = GameState::fromArray(['storyEvents' => [$expected, 'authored:custom']]);
  expect($state->hasStoryEvent($kind->getName('subject-id')))->toBeTrue();
  $state->recordStoryEvent($kind->getName('subject-id'));
  expect(GameState::fromArray($state->toArray())->storyEvents)->toBe([$expected, 'authored:custom']);
})->with([
  [EngineStoryEvent::SKIT_SEEN, 'skit_seen:subject-id'],
  [EngineStoryEvent::QUEST_COMPLETED, 'quest_completed:subject-id'],
  [EngineStoryEvent::QUEST_DECLINED, 'quest_declined:subject-id'],
  [EngineStoryEvent::CINEMATIC_COMPLETED, 'cinematic:subject-id:completed'],
]);

it('recognizes the full automatic namespaces conservatively without matching similar authored names', function (string $name, bool $expected) {
  expect(EngineStoryEvent::usesNamespace($name))->toBe($expected);
})->with([
  ['skit_seen:', true], ['quest_completed:', true], ['quest_declined:chapter:task', true],
  ['cinematic:unknown:resolved', true], ['cinematic:scene:completed', true],
  ['', false], ['skit_seen', false], ['skit_seen_again:scene', false], ['quest_completed_elsewhere', false],
  ['not_cinematic:scene:completed', false], ['cinematic_scene_completed', false], ['authored:chapter', false],
]);

it('keeps automatic quest completion and decline writers on the same persistent naming contract', function () {
  $manager = new class extends QuestManager {
    public function __construct()
    {
      $this->gameScene = new class extends GameScene {
        public function __construct() { $this->gameState = new GameState(); }
      };
      $this->log = new QuestLog();
    }
    public function finishQuest(Quest $quest): void { $this->log->accept($quest->id, 1); $this->completeQuest($quest, true); }
    public function declineQuest(Quest $quest): void { $this->recordQuestDeclined($quest); }
    protected function grantRewards(Quest $quest): array { return []; }
    public GameState $state { get => $this->gameScene->gameState; }
  };
  $quest = new Quest('synthetic-quest', 'Synthetic quest');
  $manager->declineQuest($quest);
  $manager->finishQuest($quest);
  $manager->finishQuest($quest);
  expect($manager->state->storyEvents)->toBe(['quest_declined:synthetic-quest', 'quest_completed:synthetic-quest']);
});

it('shares cinematic completion names with static story effects without executing content', function () {
  $reader = new StoryContentReader(static fn(): mixed => null,
    static fn(): array => [['script' => [['type' => 'record_event', 'name' => 'authored-finish']]]]);
  expect($reader->collectEffects(['cinematicId' => 'synthetic-scene'])->flags)
    ->toBe(['cinematic:synthetic-scene:completed' => true, 'authored-finish' => true]);
});
