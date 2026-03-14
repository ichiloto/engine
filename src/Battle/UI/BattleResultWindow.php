<?php

namespace Ichiloto\Engine\Battle\UI;

use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\UI\Windows\WindowAlignment;

/**
 * Displays the result of a battle.
 *
 * @package Ichiloto\Engine\Battle\UI
 */
class BattleResultWindow extends Window
{
  const int WIDTH = 64;
  const int HEIGHT = 8;
  /**
   * @var BattleResult|null The result currently being displayed.
   */
  protected ?BattleResult $result = null;
  /**
   * @var int The number of reward values already revealed.
   */
  protected int $revealedEntryCount = 0;
  /**
   * @var bool Whether the staged reveal is complete.
   */
  protected bool $isRevealComplete = true;
  /**
   * @var float The next time a reward value should reveal.
   */
  protected float $nextRevealAt = 0.0;
  /**
   * @var float The delay between reward reveals in seconds.
   */
  protected float $revealDelay = 0.75;

  public function __construct(protected BattleScreen $battleScreen)
  {
    parent::__construct(
      '',
      'enter:Continue',
      new Vector2(),
      self::WIDTH,
      self::HEIGHT,
      $this->battleScreen->borderPack,
      WindowAlignment::middleLeft()
    );

    $this->refreshLayout();
  }

  /**
   * Displays the provided battle result.
   *
   * @param BattleResult $result The battle result to display.
   * @return void
   */
  public function display(BattleResult $result): void
  {
    $this->result = $result;
    $this->revealedEntryCount = 0;
    $this->setTitle($result->title);

    if (! empty($result->entries)) {
      $this->isRevealComplete = false;
      $this->nextRevealAt = Time::getTime() + $this->revealDelay;
      $this->setHelp('enter:Fast Forward');
      $this->refreshContent();
      return;
    }

    $this->isRevealComplete = true;
    $this->setHelp('enter:Continue');
    $this->setContent($this->buildWrappedLines($result->lines));
    $this->render();
  }

  /**
   * Updates the staged result reveal.
   *
   * @return bool True if a new reward value was revealed.
   */
  public function update(): bool
  {
    if ($this->isRevealComplete || ! $this->result || empty($this->result->entries)) {
      return false;
    }

    if (Time::getTime() < $this->nextRevealAt) {
      return false;
    }

    return $this->revealNextEntry();
  }

  /**
   * Fast-forwards the current staged reward reveal.
   *
   * @return bool True when the result is already fully revealed.
   */
  public function advance(): bool
  {
    if ($this->isRevealComplete) {
      return true;
    }

    $this->revealNextEntry();
    return false;
  }

  /**
   * Returns whether the staged reveal is complete.
   *
   * @return bool True when all entries are visible.
   */
  public function isComplete(): bool
  {
    return $this->isRevealComplete;
  }

  /**
   * Re-centers the result window within the current battle frame.
   *
   * @return void
   */
  public function refreshLayout(): void
  {
    $leftMargin = $this->battleScreen->screenDimensions->getLeft()
      + intdiv($this->battleScreen->screenDimensions->getWidth() - self::WIDTH, 2);
    $topMargin = $this->battleScreen->screenDimensions->getTop()
      + intdiv($this->battleScreen->screenDimensions->getHeight() - self::HEIGHT, 2);

    $this->setPosition(new Vector2($leftMargin, $topMargin));
  }

  /**
   * Reveals the next reward value and redraws the window content.
   *
   * @return bool True if a new reward value was revealed.
   */
  protected function revealNextEntry(): bool
  {
    if (! $this->result || $this->isRevealComplete) {
      return false;
    }

    $entryCount = count($this->result->entries);

    if ($entryCount < 1) {
      $this->isRevealComplete = true;
      $this->setHelp('enter:Continue');
      return false;
    }

    $this->revealedEntryCount = min($entryCount, $this->revealedEntryCount + 1);
    $this->isRevealComplete = $this->revealedEntryCount >= $entryCount;
    $this->nextRevealAt = Time::getTime() + $this->revealDelay;
    $this->setHelp($this->isRevealComplete ? 'enter:Continue' : 'enter:Fast Forward');
    $this->refreshContent();

    return true;
  }

  /**
   * Rebuilds the visible result content for the current reveal step.
   *
   * @return void
   */
  protected function refreshContent(): void
  {
    if (! $this->result) {
      $this->setContent(array_fill(0, self::HEIGHT - 2, ''));
      $this->render();
      return;
    }

    if (empty($this->result->entries)) {
      $this->setContent($this->buildWrappedLines($this->result->lines));
      $this->render();
      return;
    }

    $lines = [];
    $nextEntryIndex = min($this->revealedEntryCount, count($this->result->entries) - 1);

    foreach ($this->result->entries as $index => $entry) {
      if ($index < $this->revealedEntryCount) {
        $lines[] = sprintf('%s %s', $entry['label'], $entry['value']);
        continue;
      }

      if ($index === $nextEntryIndex && ! $this->isRevealComplete) {
        $lines[] = $entry['label'];
      }

      break;
    }

    $this->setContent($this->buildWrappedLines($lines));
    $this->render();
  }

  /**
   * Wraps result lines to fit inside the result window.
   *
   * @param string[] $lines The lines to wrap.
   * @return string[] The wrapped and padded lines.
   */
  protected function buildWrappedLines(array $lines): array
  {
    $content = [];

    foreach ($lines as $line) {
      $content = array_merge($content, explode("\n", wrap_text($line, self::WIDTH - 4)));
    }

    $content = array_slice($content, 0, self::HEIGHT - 2);
    return array_pad($content, self::HEIGHT - 2, '');
  }
}
