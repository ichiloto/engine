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
