<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Campaign\CampaignContextInterface;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Progress\AchievementManager;
use Ichiloto\Engine\Quests\QuestManager;
use Tests\Support\PassiveGame;

it('restores progression from owning project facts without a field or current project directory', function () {
    $previous = [];
    foreach ([QuestManager::class, AchievementManager::class] as $class) {
        $previous[$class] = new ReflectionProperty($class, 'current')->getValue();
    }
    $oldDirectory = getcwd();
    try {
        $other = createTestDirectory('other-project-');
        chdir($other);
        foreach (['a', 'b'] as $id) {
            $root = createTestDirectory('campaign-facts-' . $id . '-');
            mkdir($root . '/Data');
            $questId = 'quest.' . $id;
            file_put_contents($root . '/Data/quests.php', '<?php return ' . var_export([
                ['id' => $questId, 'name' => 'Synthetic quest ' . $id,
                    'objectives' => [['type' => 'flag', 'target' => 'done.' . $id]]],
            ], true) . ';');
            $achievementId = 'achievement.' . $id;
            file_put_contents($root . '/Data/achievements.php', '<?php return ' . var_export([
                ['id' => $achievementId, 'name' => 'Synthetic achievement ' . $id, 'points' => 7],
            ], true) . ';');
            $facts = new class implements CampaignContextInterface {
                public GameState $gameState;
                public ?Party $party;
                public string $currentMapId = 'synthetic.map';
                public function __construct() { $this->gameState = new GameState(); $this->party = new Party(); }
            };
            $facts->gameState->setSwitch('done.' . $id, true);
            $game = new PassiveGame();
            $quests = new QuestManager($game, $facts, $root);
            $quests->hydrate(['active' => [$questId => [0]]]);
            $achievements = new AchievementManager($game, $facts, $root);
            $achievements->hydrate([$achievementId => 1234]);
            expect(array_keys($quests->quests))->toBe([$questId])
                ->and($quests->log->isCompleted($questId))->toBeTrue()
                ->and($facts->gameState->hasStoryEvent('quest_completed:' . $questId))->toBeTrue()
                ->and($achievements->isUnlocked($achievementId))->toBeTrue()
                ->and($achievements->earnedPoints())->toBe(7)
                ->and(getcwd())->toBe($other);
        }
    } finally {
        chdir($oldDirectory);
        foreach ($previous as $class => $manager) { new ReflectionProperty($class, 'current')->setValue(null, $manager); }
    }
});


function createDetachedProgressionContext(): CampaignContextInterface
{
    return new class implements CampaignContextInterface {
        public GameState $gameState;
        public ?Party $party;
        public string $currentMapId = 'synthetic.map';
        public function __construct() { $this->gameState = new GameState(); $this->party = new Party(); }
    };
}

it('releases progression owners in either order without retaining a retired owner', function (string $class) {
    $prior = new ReflectionProperty($class, 'current')->getValue();
    $game = new PassiveGame();
    $root = createTestDirectory('progression-release-');
    $a = new $class($game, createDetachedProgressionContext(), $root);
    $b = new $class($game, createDetachedProgressionContext(), $root);
    $c = new $class($game, createDetachedProgressionContext(), $root);
    try {
        $weakB = WeakReference::create($b);
        $b->release();
        expect($class::current())->toBe($c)->and($b->isReleased)->toBeTrue();
        unset($b);
        gc_collect_cycles();
        expect($weakB->get())->toBeNull();
        $c->release();
        expect($class::current())->toBe($a);
        $c->release();
        expect($class::current())->toBe($a);
        $a->release();
        expect($class::current())->toBe($prior);
    } finally {
        $c->release();
        $a->release();
        new ReflectionProperty($class, 'current')->setValue(null, $prior);
    }
})->with([QuestManager::class, AchievementManager::class]);

it('routes inventory changes to their campaign and keeps detached or released owners quiet', function () {
    $prior = new ReflectionProperty(QuestManager::class, 'current')->getValue();
    $a = new class extends QuestManager {
        public int $changes = 0;
        public function __construct() {}
        public function syncCollectObjectives(): void { $this->changes++; }
    };
    $b = new class extends QuestManager {
        public int $changes = 0;
        public function __construct() {}
        public function syncCollectObjectives(): void { $this->changes++; }
    };
    new ReflectionProperty(QuestManager::class, 'current')->setValue(null, $b);
    try {
        $inventory = new \Ichiloto\Engine\Entities\Inventory\Inventory();
        $inventory->bindQuestManager($a);
        $item = new \Ichiloto\Engine\Entities\Inventory\Items\Item('Synthetic', '', '!', 1, id: 'item.synthetic');
        $inventory->addItems($item);
        $inventory->removeItems($item);
        expect($a->changes)->toBe(2)->and($b->changes)->toBe(0);
        $detached = new \Ichiloto\Engine\Entities\Inventory\Inventory();
        $detached->bindItemStore(new class extends \Ichiloto\Engine\Util\Stores\ItemStore { public function __construct() {} });
        $detached->addItems(clone $item);
        expect($b->changes)->toBe(0);
        $a->release();
        $inventory->addItems(clone $item);
        expect($a->changes)->toBe(2)->and($b->changes)->toBe(0);
    } finally {
        $a->release();
        $b->release();
        new ReflectionProperty(QuestManager::class, 'current')->setValue(null, $prior);
    }
});


it('evaluates each campaign quest prerequisite and achievement against its own log', function () {
    $priorQuest = new ReflectionProperty(QuestManager::class, 'current')->getValue();
    $priorAchievement = new ReflectionProperty(AchievementManager::class, 'current')->getValue();
    $root = createTestDirectory('owned-quest-conditions-');
    mkdir($root . '/Data');
    file_put_contents($root . '/Data/quests.php', '<?php return ' . var_export([
        ['id' => 'gate', 'name' => 'Synthetic gate', 'objectives' => [['type' => 'flag', 'target' => 'done']]],
        ['id' => 'follow', 'name' => 'Synthetic follow', 'objectives' => [['type' => 'flag', 'target' => 'later']],
            'prerequisites' => [['type' => 'quest', 'name' => 'gate', 'status' => 'completed']]],
    ], true) . ';');
    file_put_contents($root . '/Data/achievements.php', '<?php return ' . var_export([
        ['id' => 'gate-achievement', 'name' => 'Synthetic recognition',
            'conditions' => [['type' => 'quest', 'name' => 'gate', 'status' => 'completed']]],
    ], true) . ';');
    $game = new PassiveGame();
    $factsA = createDetachedProgressionContext();
    $factsA->gameState->setSwitch('done', true);
    $a = new class($game, $factsA, $root) extends QuestManager {
        protected function notifyQuest(string $title, string $text,
            \Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration $duration,
            ?string $presentationRole = null): void {}
    };
    $a->hydrate(['active' => ['gate' => [0]]]);
    $b = new QuestManager($game, createDetachedProgressionContext(), $root);
    $b->hydrate(['active' => ['gate' => [0]]]);
    $achievements = new class($game, $factsA, $root, $a->log) extends AchievementManager {
        public array $requests = [];
        public function unlock(string $achievementId, ?int $unlockedAt = null): bool {
            $this->requests[] = $achievementId;
            return true;
        }
    };
    try {
        expect(QuestManager::current())->toBe($b)
            ->and($b->log->isCompleted('gate'))->toBeFalse()
            ->and($a->acceptQuest('follow', offer: false))->toBeTrue();
        $achievements->evaluateConditionalAchievements();
        expect($achievements->requests)->toBe(['gate-achievement']);
        $objective = \Ichiloto\Engine\Quests\QuestObjective::fromArray([
            'type' => 'flag', 'target' => 'later', 'description' => 'Undisclosed',
            'revealedDescription' => 'Revealed',
            'revealConditions' => [['type' => 'quest', 'name' => 'gate', 'status' => 'completed']],
        ]);
        expect($a->describeObjective($objective))->toBe('Revealed')
            ->and($b->describeObjective($objective))->toBe('Undisclosed');
    } finally {
        $achievements->release();
        $b->release();
        $a->release();
        new ReflectionProperty(QuestManager::class, 'current')->setValue(null, $priorQuest);
        new ReflectionProperty(AchievementManager::class, 'current')->setValue(null, $priorAchievement);
    }
});


it('restores inventory delivery to the preceding campaign owner after temporary restoration releases', function () {
    $root = createTestDirectory('inventory-campaign-release-');
    $context = createDetachedProgressionContext();
    $game = new PassiveGame();
    $a = new class($game, $context, $root) extends QuestManager {
        public int $changes = 0;
        public function syncCollectObjectives(): void { $this->changes++; }
    };
    $b = new class($game, $context, $root) extends QuestManager {
        public int $changes = 0;
        public function syncCollectObjectives(): void { $this->changes++; }
    };
    try {
        $item = new \Ichiloto\Engine\Entities\Inventory\Items\Item('Synthetic', '', '!', 1, id: 'item.synthetic');
        $context->party->inventory->addItems($item);
        expect($b->changes)->toBe(1)->and($a->changes)->toBe(0);
        $b->release();
        $context->party->inventory->removeItems($item);
        expect($b->changes)->toBe(1)->and($a->changes)->toBe(1);
    } finally { $b->release(); $a->release(); }
});
