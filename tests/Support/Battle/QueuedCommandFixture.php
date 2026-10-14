<?php

declare(strict_types=1);

namespace Tests\Support\Battle;

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\BattlePacing;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleConfig;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\TraditionalTurnBasedBattleEngine;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Turn;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnBasedBattleConfig;
use Ichiloto\Engine\Battle\UI\BattleCharacterNameWindow;
use Ichiloto\Engine\Battle\UI\BattleCharacterStatusWindow;
use Ichiloto\Engine\Battle\UI\BattleCommandContextWindow;
use Ichiloto\Engine\Battle\UI\BattleCommandWindow;
use Ichiloto\Engine\Battle\UI\BattleFieldWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use ReflectionClass;
use ReflectionProperty;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Rendering/GraphicalSpriteFixtures.php';

/** Only terminal-window setup is stubbed; combat, playback and graphical feedback are production code. */
final class TargetExecutionField extends BattleFieldWindow
{
  public array $results = [];
  public function __construct(BattleScreen $screen) { $this->battleScreen = $screen; }
  public function clearTargetIndicators(): void {}
  public function focusPartyBattler(int $index, bool $blink = false): void {}
  public function focusOnTroopBattler(int $index, bool $blink = false): void {}
  public function clearMagicCastEffects(): void {}
  public function clearBattleFlash(): void {}
  public function clearSummonShake(): void {}
  public function getSelectedBattlers(): array { return []; }
  public function getFocusedBattlers(): array { return []; }
  public function getQueuedBattlers(): array { return []; }
  public function showStatChangePopup(CharacterInterface $battler, array $lines, bool $clearExisting = true,
    float $durationSeconds = 0.0): void
  {
    $this->results[] = [$battler, $lines];
    if ($this->battleScreen->usesGraphicalField()) {
      parent::showStatChangePopup($battler, $lines, $clearExisting, $durationSeconds);
    }
  }
}

final class TargetExecutionScreen extends BattleScreen
{
  public array $observed = [];
  public int $controlsHidden = 0;
  public int $controlsRestored = 0;
  public ?string $announcement = null;
  public function __construct(private bool $graphical)
  {
    $this->fieldWindow = new TargetExecutionField($this);
    $this->characterNameWindow = new class extends BattleCharacterNameWindow {
      public function __construct() {}
      public function setActiveSelection(int $index, bool $blink = false): void {}
    };
    $this->characterStatusWindow = new class extends BattleCharacterStatusWindow {
      public function __construct() {}
      public function setCharacters(array $characters): void {}
    };
    $this->commandWindow = new class extends BattleCommandWindow {
      public function __construct() {}
      public function blur(): void {}
    };
    $this->commandContextWindow = new class extends BattleCommandContextWindow {
      public function __construct() {}
      public function clear(): void {}
    };
  }
  public function getPacing(): BattlePacing { return new BattlePacing(); }
  public function usesGraphicalField(): bool { return $this->graphical; }
  public function refresh(): void {}
  public function refreshField(): void
  {
    $playback = $this->fieldWindow->getCommandPlayback();
    if ($playback !== null) {
      $this->observed[] = [$playback->phase, $playback->getPoseRole($playback->actor), $playback->targets];
    }
  }
  // A summon hides and restores the battle menus around its presentation; these windows are never drawn.
  public function hideControls(): void { $this->controlsHidden++; }
  public function showControls(): void { $this->controlsRestored++; }
  public function hideMessage(): void { $this->announcement = null; }
  public function showMessage(string $text): void { $this->announcement = $text; }
  public function alert(string $text): void { $this->announcement = $text; }
}

function createTargetExecutionFixture(bool $activeTime, bool $graphical, AudioManager $audio, array $formation = [],
  ?Stats $actorStats = null): array
{
  $party = new Party();
  $actor = new Character('Caster', 1, $actorStats ?? new Stats(currentHp: 80, totalHp: 100, currentMp: 50, totalMp: 50));
  $ally = new Character('Recipient', 1, new Stats(currentHp: 0, totalHp: 100));
  $party->addMember($actor);
  $party->addMember($ally);
  $enemies = [];
  foreach (['Enemy A', 'Enemy B'] as $name) {
    $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(Enemy::class, 'name')->setValue($enemy, $name);
    new ReflectionProperty(Enemy::class, 'position')->setValue($enemy, new Vector2());
    new ReflectionProperty(Enemy::class, 'rewards')->setValue($enemy, new \Ichiloto\Engine\Battle\BattleRewards(0, 0, []));
    new ReflectionProperty(Enemy::class, 'stats')->setValue($enemy,
      new Stats(currentHp: 100, totalHp: 100, currentMp: 50, totalMp: 50));
    $enemies[] = $enemy;
  }
  $troop = new Troop('Synthetic', $enemies, graphicalFormation: $formation);
  return [...createQueuedBattleFixture($activeTime, $graphical, $audio, $party, $troop), $actor, $ally, $enemies];
}

/** Supply current project battlers without copying combat setup or changing their identities. */
function createQueuedBattleFixture(bool $activeTime, bool $graphical, AudioManager $audio, Party $party, Troop $troop): array
{
  $game = new class extends Game { public function __construct() {} public function __destruct() {} };
  new ReflectionProperty(Game::class, 'audioManager')->setValue($game, $audio);
  $screen = new TargetExecutionScreen($graphical);
  $engine = $activeTime ? new ActiveTimeBattleEngine($game) : new TraditionalTurnBasedBattleEngine($game);
  $engine->configure($activeTime ? new ActiveTimeBattleConfig($party, $troop, $screen)
    : new TurnBasedBattleConfig($party, $troop, $screen));
  $context = new TurnStateExecutionContext($game, $party, $troop, $screen, []);
  new ReflectionProperty($engine, 'turnStateExecutionContext')->setValue($engine, $context);
  return [$engine, $context, $screen];
}

function queueTargetExecution(array $fixture, BattleAction $action, array $targets, ?CharacterInterface $actor = null): Turn
{
  [$engine, $context] = $fixture;
  $actor ??= $fixture[3];
  if ($engine instanceof ActiveTimeBattleEngine) {
    $engine->queueImmediateTurn($context, $actor, $action, $targets);
  } else {
    $turn = new Turn($actor);
    $turn->action = $action;
    $turn->targets = $targets;
    $context->setTurns([$turn]);
    $engine->setState($engine->actionExecutionState);
  }
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 0.0);
  $engine->state->update($context);
  return $context->getTurns()[0];
}

/** Disposable authored resources exercise the production loader, not a replacement resolver. */
function writeQueuedAttackEffects(string $root, int $framesPerStroke = 2): void
{
  mkdir($root . '/assets/Data', 0777, true);
  file_put_contents($root . '/assets/Data/summons.php', '<?php return [];');
  $animations = [
    ['id' => 1, 'name' => 'Impact', 'roles' => ['attack', 'attack-staff', 'attack-flail', 'attack-glove', 'attack-unarmed'],
      'sourceEffect' => 'windup', 'targetEffect' => 'impact'],
    ['id' => 2, 'name' => 'Blade', 'roles' => ['attack-sword'], 'sourceEffect' => 'windup', 'targetEffect' => 'blade'],
    ['id' => 3, 'name' => 'Two strokes', 'sourceEffect' => 'windup', 'targetEffect' => 'double'],
  ];
  file_put_contents($root . '/assets/Data/animations.php', '<?php return ' . var_export($animations, true) . ';');
  foreach (['windup', 'impact', 'blade', 'double'] as $id) {
    $animated = $framesPerStroke > 2;
    $columns = $animated ? $framesPerStroke : 2;
    writeTestPng($root . '/assets/' . $id . '.png', $columns * 4, 4);
    $tracks = [];
    $length = $id === 'windup' ? $framesPerStroke
      : ($id === 'double' ? $framesPerStroke * 2 + 1 : $framesPerStroke + 3);
    foreach (range(0, $id === 'double' ? 1 : 0) as $stroke) {
      $directional = in_array($id, ['blade', 'double'], true);
      $frame = $stroke * ($framesPerStroke + 1);
      $common = ['id' => $id . '-' . $stroke, 'anchor' => $id === 'windup' ? 'caster' : 'target',
        ...($directional ? ['facing' => 'east'] : [])];
      $tracks[] = [...$common, 'id' => 'image-' . $id . '-' . $stroke, 'type' => 'image', 'presentation' => 'graphical',
        'asset' => $id . '.png', 'sheet' => ['columns' => $columns, 'rows' => 1], 'keyframes' => $animated
          ? array_map(static fn(int $index): array => ['frame' => $frame + $index, 'sourceFrame' => $index,
            'flipX' => $stroke === 1], range(0, $framesPerStroke - 1))
          : [['frame' => $frame, 'duration' => $framesPerStroke, 'sourceFrame' => 1, 'flipX' => $stroke === 1]]];
      $tracks[] = [...$common, 'type' => 'glyph', 'presentation' => 'terminal', 'keyframes' => [
        ['frame' => $frame, 'duration' => $framesPerStroke, 'content' => $directional ? ($stroke === 1 ? '\\' : '/') : '*'],
      ]];
    }
    $data = ['fps' => 10, 'lengthFrames' => $length, 'restFrame' => 0, 'tracks' => $tracks,
      ...($id === 'windup' ? [] : ['effectTiming' => ['mode' => 'end']])];
    $directory = $root . '/assets/Animations/' . $id;
    mkdir($directory, 0777, true);
    file_put_contents($directory . '/' . $id . '.timeline.php', '<?php return ' . var_export($data, true) . ';');
  }
}
