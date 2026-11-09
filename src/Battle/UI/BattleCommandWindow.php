<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Battle\BattleCommand;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Battle\Presentation\BattleHudListSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleHudRow;
use Ichiloto\Engine\Core\Interfaces\CanChangeSelection;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Interfaces\CanFocus;
use Ichiloto\Engine\UI\Presentation\TutorialTerminalPresentation;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Represents the command window.
 *
 * @package Ichiloto\Engine\Battle\UI
 */
class BattleCommandWindow extends Window implements CanFocus, CanChangeSelection
{
  /**
   * The width of the window.
   */
  const int WIDTH = 14;
  /**
   * The height of the window.
   */
  const int HEIGHT = 6;
  /** Space for a full technique name and a measured held-control hint. */
  public const int ALTERNATIVE_WIDTH = 26;
  /**
   * The index of the active command.
   */
  protected(set) int $activeCommandIndex = -1;
  /**
   * @var bool Whether the active command should blink.
   */
  protected bool $blinkActiveSelection = false;
  /**
   * @var int The first visible command index.
   */
  protected int $scrollOffset = 0;
  /**
   * @var string The base title shown above the command list.
   */
  protected string $titleBase = 'Command';
  /**
   * @var list<BattleCommand|string> Semantic commands; strings remain supported for legacy UI callers.
   */
  public array $commands = [] {
    get {
      return $this->commands;
    }
    set {
      $this->commands = $value;
      $this->totalCommands = count($this->commands);
      $this->updateContent();
    }
  }

  protected int $totalCommands = 0;
  private ?string $attackAlternativeLabel = null;
  private bool $attackAlternativeSelected = false;
  private ?BattleCommandType $tutorialTarget = null;

  public function setTutorialTarget(?BattleCommandType $target): void
  {
    if ($this->tutorialTarget !== $target) {
      $this->tutorialTarget = $target;
      $this->updateContent();
    }
  }

  /** Only presentation changes; the command retains its Attack identity. */
  public function setAttackAlternative(?string $label, bool $selected = false): void
  {
    if ($label === $this->attackAlternativeLabel && $selected === $this->attackAlternativeSelected) { return; }
    $this->attackAlternativeLabel = $label;
    $this->attackAlternativeSelected = $selected;
    $this->updateContent();
  }

  private function getCommandLabel(BattleCommand|string $command): string
  {
    return $command instanceof BattleCommand && $command->type === BattleCommandType::ATTACK
      && $this->attackAlternativeSelected && $this->attackAlternativeLabel !== null
        ? $this->attackAlternativeLabel : (string)$command;
  }


  public function getActiveCommand(): ?BattleCommand
  {
    $command = $this->commands[$this->activeCommandIndex] ?? null;
    if ($command instanceof BattleCommand) { return $command; }
    if (is_string($command) && ($type = BattleCommandType::fromCommandName($command)) !== null) {
      return new BattleCommand($type, $command);
    }
    return null;
  }

  /**
   * Creates a new instance of the BattleCommandWindow class.
   *
   * @param BattleScreen $battleScreen The battle screen.
   */
  public function __construct(protected BattleScreen $battleScreen)
  {
    $leftMargin = $this->battleScreen->screenDimensions->getLeft();
    $topMargin = $this->battleScreen->screenDimensions->getTop() + $this->battleScreen->fieldWindow->height;

    $position = new Vector2($leftMargin, $topMargin);

    parent::__construct(
      'Command',
      '',
      $position,
      self::WIDTH,
      self::HEIGHT,
      $this->battleScreen->borderPack
    );
    $this->setControlHints([BattleControlHints::getInfoHint()]);
  }

  /**
   * @inheritDoc
   */
  public function focus(): void
  {
    $this->tutorialTarget = null;
    $this->blinkActiveSelection = true;
    $this->scrollOffset = 0;
    $this->activeCommandIndex = $this->totalCommands > 0 ? 0 : -1;
    $this->updateContent();
  }

  /**
   * @inheritDoc
   */
  public function blur(): void
  {
    $this->tutorialTarget = null;
    $this->attackAlternativeLabel = null;
    $this->attackAlternativeSelected = false;
    $this->setControlHints([BattleControlHints::getInfoHint()]);
    $this->blinkActiveSelection = false;
    $this->scrollOffset = 0;
    $this->activeCommandIndex = -1;
    $this->updateContent();
  }

  /**
   * Toggles blink state for the active command without changing the selection.
   *
   * @param bool $blink Whether the active command should blink.
   * @return void
   */
  public function setSelectionBlink(bool $blink): void
  {
    $this->blinkActiveSelection = $blink;
    $this->updateContent();
  }

  public function clear(): void
  {
    $this->tutorialTarget = null;
    $this->attackAlternativeLabel = null;
    $this->attackAlternativeSelected = false;
    $this->commands = [];
    $this->activeCommandIndex = -1;
    $this->totalCommands = 0;
    $this->scrollOffset = 0;
    $this->updateContent();
  }

  /** Reads the owned viewport without scrolling, styling, or rendering it. */
  public function presentationSnapshot(): BattleHudListSnapshot
  {
    $visibleRowCount = min(self::HEIGHT - 2, $this->getVisibleRowCount());
    $rows = [];
    foreach (array_slice($this->commands, $this->scrollOffset, $visibleRowCount, true) as $index => $command) {
      $rows[] = new BattleHudRow($index, $this->getCommandLabel($command), $this->activeCommandIndex === $index,
        hasAlternative: $command instanceof BattleCommand && $command->type === BattleCommandType::ATTACK
          && $this->attackAlternativeLabel !== null,
        tutorialTarget: $command instanceof BattleCommand && $command->type === $this->tutorialTarget);
    }
    [$currentPage, $totalPages] = $this->getPagination();

    return new BattleHudListSnapshot(
      $this->title, $this->help, $rows, $this->activeCommandIndex, $this->scrollOffset,
      $visibleRowCount, $this->totalCommands, $currentPage, $totalPages, hints: $this->getControlHints(),
      preferredColumns: $this->attackAlternativeLabel === null ? self::WIDTH
        : max(self::ALTERNATIVE_WIDTH, TerminalText::displayWidth($this->attackAlternativeLabel) + 7),
    );
  }

  /**
   * Updates the content of the window.
   *
   * @return void
   */
  public function updateContent(): void
  {
    $content = [];
    $availableWidth = $this->getContentWidth();
    $visibleRowCount = $this->getVisibleRowCount();

    $this->syncScrollOffset();
    $this->updateTitle();

    foreach (array_slice($this->commands, $this->scrollOffset, $visibleRowCount, true) as $index => $command) {
      $selected = $this->activeCommandIndex === $index;
      $marked = $command instanceof BattleCommand && $command->type === $this->tutorialTarget;
      $prefix = $selected ? '>' : ($marked ? '!' : ' ');
      $label = $this->getCommandLabel($command);
      $line = TerminalText::padRight("$prefix $label", $availableWidth);

      if ($marked) {
        $line = TutorialTerminalPresentation::highlight($line);
      } elseif ($selected) {
        $line = $this->battleScreen->styleSelectionLine($line, $this->blinkActiveSelection);
      }

      $content[] = $line;
    }

    $content = array_pad($content, $visibleRowCount, '');
    $this->setContent($content);
    $this->render();
  }

  /**
   * @inheritDoc
   */
  public function selectPrevious(): void
  {
    if ($this->totalCommands < 1) {
      return;
    }

    $index = wrap($this->activeCommandIndex - 1, 0, $this->totalCommands - 1);
    $this->activeCommandIndex = $index;
    $this->syncScrollOffset();
    $this->updateContent();
  }

  /**
   * @inheritDoc
   */
  public function selectNext(): void
  {
    if ($this->totalCommands < 1) {
      return;
    }

    $index = wrap($this->activeCommandIndex + 1, 0, $this->totalCommands - 1);
    $this->activeCommandIndex = $index;
    $this->syncScrollOffset();
    $this->updateContent();
  }

  /**
   * Returns the number of command rows visible at once.
   *
   * @return int The visible row count.
   */
  protected function getVisibleRowCount(): int
  {
    return max(0, $this->height - 2);
  }

  /**
   * Keeps the active command inside the visible scroll window.
   *
   * @return void
   */
  protected function syncScrollOffset(): void
  {
    $visibleRowCount = $this->getVisibleRowCount();

    if ($visibleRowCount < 1 || $this->activeCommandIndex < 0) {
      $this->scrollOffset = 0;
      return;
    }

    if ($this->activeCommandIndex < $this->scrollOffset) {
      $this->scrollOffset = $this->activeCommandIndex;
      return;
    }

    $lastVisibleIndex = $this->scrollOffset + $visibleRowCount - 1;

    if ($this->activeCommandIndex > $lastVisibleIndex) {
      $this->scrollOffset = $this->activeCommandIndex - $visibleRowCount + 1;
    }
  }

  /**
   * Updates the title with the current command-list page.
   *
   * @return void
   */
  protected function updateTitle(): void
  {
    [$currentPage, $totalPages] = $this->getPagination();

    $this->setTitle(
      $totalPages > 1
        ? sprintf('%s %d/%d', $this->titleBase, $currentPage, $totalPages)
        : $this->titleBase
    );
  }

  /** @return array{int, int} Current page and page count used by the window title. */
  protected function getPagination(): array
  {
    $visibleRowCount = max(1, $this->getVisibleRowCount());
    $totalPages = max(1, (int)ceil($this->totalCommands / $visibleRowCount));
    $currentPage = $this->activeCommandIndex < 0
      ? 1
      : max(1, (int)floor($this->activeCommandIndex / $visibleRowCount) + 1);

    return [$currentPage, $totalPages];
  }
}
