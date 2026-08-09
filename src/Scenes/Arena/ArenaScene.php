<?php

namespace Ichiloto\Engine\Scenes\Arena;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/**
 * A room with nothing in it but the project's troops.
 *
 * Testing a battle by playing to it costs a walk, an encounter roll, and
 * whatever the party picked up on the way. The arena is the fight on its own:
 * pick a troop, fight it for real with the game's own battle system, and land
 * back here afterwards with the party restored, ready to try the next one.
 *
 * @package Ichiloto\Engine\Scenes\Arena
 */
class ArenaScene extends AbstractScene
{
  protected const int PANEL_WIDTH = 60;
  protected const int LIST_HEIGHT = 18;
  protected const int INFO_HEIGHT = 5;

  /**
   * @var Troop[] The troops that can be fought.
   */
  protected array $troops = [];
  /**
   * @var Party|null The party doing the fighting.
   */
  protected ?Party $party = null;
  /**
   * @var array<string, array{0: int, 1: int}> The health the party started with, by member name.
   */
  protected array $fullHealth = [];
  /**
   * @var int The selected troop.
   */
  protected int $activeIndex = 0;
  /**
   * @var int The scroll offset.
   */
  protected int $scrollOffset = 0;
  protected int $leftMargin = 0;
  protected int $topMargin = 0;
  protected ?Window $listPanel = null;
  protected ?Window $infoPanel = null;

  /**
   * @inheritDoc
   */
  public function getBackgroundMusic(): ?string
  {
    return $this->getConfiguredBackgroundMusic('audio.bgm.battle');
  }

  /**
   * @inheritDoc
   */
  public function start(): void
  {
    parent::start();

    $this->troops = $this->loadTroops();
    $this->party ??= $this->loadParty();
    $this->rememberHealth();
    $this->buildPanels();

    Console::clear();
    $this->render();

    // Booted with a troop named: skip the list and fight it.
    $wanted = strval($this->getGame()->options['arena_troop'] ?? '');

    if ($wanted !== '') {
      $this->fightByName($wanted);
    }
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    parent::resume();

    // Back from a fight: the party is patched up so the next troop is fought
    // by a fresh party rather than whatever the last one left.
    $this->restoreHealth();
    Console::clear();
    $this->render();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    parent::update();

    if ($this->troops === []) {
      if (Input::isButtonDown('quit') || Input::isButtonDown('cancel')) {
        $this->getGame()->quit();
      }

      return;
    }

    $vertical = Input::getAxis(AxisName::VERTICAL);

    if (abs($vertical) > 0.1) {
      $this->activeIndex = wrap($this->activeIndex + ($vertical > 0 ? 1 : -1), 0, count($this->troops) - 1);
      $this->scrollToActive();
      $this->render();
    }

    if (Input::isButtonDown('confirm') || Input::isButtonDown('action')) {
      $this->fight($this->troops[$this->activeIndex]);

      return;
    }

    if (Input::isAnyKeyPressed([KeyCode::Q, KeyCode::q]) || Input::isButtonDown('cancel')) {
      $this->getGame()->quit();
    }
  }

  /**
   * Sets the party the arena fights with.
   *
   * @param Party $party The party.
   * @return void
   */
  public function useParty(Party $party): void
  {
    $this->party = $party;
    $this->rememberHealth();
  }

  /**
   * Starts a fight against a troop, by name.
   *
   * Used to drop straight into one fight rather than the list.
   *
   * @param string $troopName The troop's name.
   * @return bool True when the troop exists and the fight started.
   */
  public function fightByName(string $troopName): bool
  {
    foreach ($this->troops as $index => $troop) {
      if (strcasecmp($troop->name, $troopName) === 0) {
        $this->activeIndex = $index;
        $this->fight($troop);

        return true;
      }
    }

    return false;
  }

  /**
   * Fights a troop for real.
   *
   * @param Troop $troop The troop to fight.
   * @return void
   */
  protected function fight(Troop $troop): void
  {
    if (! $this->party instanceof Party) {
      return;
    }

    $this->restoreHealth();

    try {
      $this->getGame()->sceneManager->loadBattleScene($this->party, clone $troop);
    } catch (Throwable $exception) {
      Debug::error(sprintf('The arena could not start a battle: %s', $exception->getMessage()));
    }
  }

  /**
   * Loads the project's troops.
   *
   * @return Troop[] The troops.
   */
  protected function loadTroops(): array
  {
    $troops = [];

    foreach ((array) asset('Data/troops.php', true) as $data) {
      if (! is_array($data)) {
        continue;
      }

      try {
        $troops[] = Troop::fromArray($data);
      } catch (Throwable $exception) {
        Debug::warn(sprintf('The arena skipped a troop: %s', $exception->getMessage()));
      }
    }

    return $troops;
  }

  /**
   * Builds the party the arena fights with.
   *
   * @return Party|null The project's starting party.
   */
  protected function loadParty(): ?Party
  {
    try {
      return GameLoader::getInstance($this->getGame())->loadNewGame()->party;
    } catch (Throwable $exception) {
      Debug::error(sprintf('The arena could not build a party: %s', $exception->getMessage()));

      return null;
    }
  }

  /**
   * Records the party's health, so it can be put back between fights.
   *
   * @return void
   */
  protected function rememberHealth(): void
  {
    foreach ($this->party?->battlers?->toArray() ?? [] as $battler) {
      $this->fullHealth[$battler->name] ??= [$battler->stats->currentHp, $battler->stats->currentMp];
    }
  }

  /**
   * Patches the party up.
   *
   * @return void
   */
  protected function restoreHealth(): void
  {
    foreach ($this->party?->battlers?->toArray() ?? [] as $battler) {
      [$hp, $mp] = $this->fullHealth[$battler->name] ?? [$battler->stats->totalHp, $battler->stats->totalMp];

      $battler->stats->currentHp = $hp;
      $battler->stats->currentMp = $mp;
    }
  }

  /**
   * Builds the screen's panels.
   *
   * @return void
   */
  protected function buildPanels(): void
  {
    $borderPack = new DefaultBorderPack();
    $this->leftMargin = max(0, intdiv(get_screen_width() - self::PANEL_WIDTH, 2));
    $this->topMargin = max(0, intdiv(get_screen_height() - (self::LIST_HEIGHT + self::INFO_HEIGHT), 2));

    $this->listPanel = new Window(
      'Arena',
      'enter:Fight  q:Quit',
      new Vector2($this->leftMargin, $this->topMargin),
      self::PANEL_WIDTH,
      self::LIST_HEIGHT,
      $borderPack
    );

    $this->infoPanel = new Window(
      'Party',
      '',
      new Vector2($this->leftMargin, $this->topMargin + self::LIST_HEIGHT),
      self::PANEL_WIDTH,
      self::INFO_HEIGHT,
      $borderPack
    );
  }

  /**
   * Keeps the selection on screen.
   *
   * @return void
   */
  protected function scrollToActive(): void
  {
    $visibleRows = self::LIST_HEIGHT - 2;

    if ($this->activeIndex < $this->scrollOffset) {
      $this->scrollOffset = $this->activeIndex;
    } elseif ($this->activeIndex >= $this->scrollOffset + $visibleRows) {
      $this->scrollOffset = $this->activeIndex - $visibleRows + 1;
    }
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    $innerWidth = self::PANEL_WIDTH - 4;
    $visibleRows = self::LIST_HEIGHT - 2;
    $content = [];

    if ($this->troops === []) {
      $content[] = ' This project has no troops to fight.';
    }

    foreach (array_slice($this->troops, $this->scrollOffset, $visibleRows, true) as $index => $troop) {
      $prefix = $index === $this->activeIndex ? '>' : ' ';
      $line = TerminalText::padRight(
        sprintf(' %s %s', $prefix, $this->describeTroop($troop)),
        $innerWidth
      );

      $content[] = $index === $this->activeIndex ? SelectionStyle::apply($line) : $line;
    }

    $this->listPanel?->setContent(array_pad($content, $visibleRows, ''));
    $this->listPanel?->render();

    $this->infoPanel?->setContent($this->describeParty());
    $this->infoPanel?->render();
  }

  /**
   * Describes a troop for the list.
   *
   * @param Troop $troop The troop.
   * @return string The description.
   */
  protected function describeTroop(Troop $troop): string
  {
    $members = $troop->members->toArray();
    $levels = array_map(static fn(object $enemy): int => $enemy->level ?? 1, $members);

    return sprintf(
      '%-24s %d %s, level %s',
      $troop->name,
      count($members),
      count($members) === 1 ? 'enemy' : 'enemies',
      $levels === [] ? '?' : (min($levels) === max($levels) ? min($levels) : min($levels) . '-' . max($levels))
    );
  }

  /**
   * Describes the party for the info panel.
   *
   * @return string[] The rows.
   */
  protected function describeParty(): array
  {
    $rows = [];

    foreach ($this->party?->battlers?->toArray() ?? [] as $battler) {
      $rows[] = sprintf(
        ' %-14s Lv %-3d HP %d/%d  MP %d/%d',
        $battler->name,
        $battler->level,
        $battler->stats->currentHp,
        $battler->stats->totalHp,
        $battler->stats->currentMp,
        $battler->stats->totalMp
      );
    }

    return $rows === [] ? [' No party could be built from this project.'] : $rows;
  }
}
