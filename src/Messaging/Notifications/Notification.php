<?php

namespace Ichiloto\Engine\Messaging\Notifications;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\NotificationEventType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\NotificationEvent;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationSlideDirection;
use Ichiloto\Engine\Messaging\Notifications\Interfaces\NotificationInterface;
use Ichiloto\Engine\UI\Windows\BorderPacks\SlimBorderPack;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use Ichiloto\Engine\UI\Windows\Enumerations\VerticalAlignment;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\UI\Windows\WindowAlignment;
use Ichiloto\Engine\UI\Windows\WindowPadding;
use Ichiloto\Engine\Util\Config\PlaySettings;

/**
 * Class Notification. Represents a notification. Notifications are displayed on the screen for a given duration.
 * They are displayed in the top right corner of the screen and are dismissed after the given duration. Notifications
 * are useful for displaying information to the player.
 *
 * @package Ichiloto\Engine\Messaging\Notifications
 */
class Notification implements NotificationInterface
{
  protected const float DEFAULT_ANIMATION_DURATION = 0.18;
  protected const string STATE_HIDDEN = 'hidden';
  protected const string STATE_ENTERING = 'entering';
  protected const string STATE_VISIBLE = 'visible';
  protected const string STATE_EXITING = 'exiting';
  protected const string STATE_FINISHED = 'finished';
  /**
   * The notification width.
   */
  public const int WIDTH = 40;
  /**
   * The notification height.
   */
  public const int HEIGHT = 5;
  /**
   * @var Window The notification window.
   */
  protected Window $window;
  /**
   * @var Vector2 The notification position.
   */
  protected Vector2 $position;
  /**
   * @var WindowAlignment The alignment of the notification content.
   */
  protected WindowAlignment $contentAlignment;
  /**
   * @var WindowPadding The padding of the notification content.
   */
  protected WindowPadding $contentPadding;
  /**
   * @var array The notification content.
   */
  protected array $content = [];
  /**
   * @var bool Whether the notification is open.
   */
  protected bool $isOpen = false;
  /**
   * @var bool Whether the notification is dismissing.
   */
  protected bool $isDismissing = false;
  /**
   * @var string The current notification animation state.
   */
  protected string $state = self::STATE_HIDDEN;
  /**
   * @var float The time the current animation phase started.
   */
  protected float $animationStartedAt = 0.0;
  /**
   * @var Vector2 The current animated render position.
   */
  protected Vector2 $renderPosition;
  /**
   * @var Vector2|null The last rendered notification position.
   */
  protected ?Vector2 $lastRenderedPosition = null;
  /**
   * @var EventManager $eventManager The event manager.
   */
  protected EventManager $eventManager;
  /**
   * @var mixed $sceneEventHandler The scene event handler.
   */
  protected mixed $sceneEventHandler = null;
  /**
   * @var mixed $mapEventHandler The map event handler.
   */
  protected string $id = '';

  /**
   * Notification constructor.
   *
   * @param NotificationChannel $channel The notification channel.
   * @param string $contentTitle The notification title.
   * @param string $contentText The notification text.
   * @param NotificationDuration|float $duration The notification duration.
   * @param BorderPackInterface $borderPack The notification border pack.
   */
  public function __construct(
    protected Game $game,
    protected NotificationChannel $channel,
    protected string $contentTitle = '',
    protected string $contentText = '',
    protected NotificationDuration|float $duration = NotificationDuration::LONG,
    protected BorderPackInterface $borderPack = new SlimBorderPack(),
    protected NotificationSlideDirection $enterDirection = NotificationSlideDirection::RIGHT,
    protected ?NotificationSlideDirection $exitDirection = null,
    protected float $animationDuration = self::DEFAULT_ANIMATION_DURATION,
  )
  {
    $this->id = uniqid('notification_');
    $this->eventManager = EventManager::getInstance($this->game);

    $leftMargin = config(PlaySettings::class, 'width', DEFAULT_SCREEN_WIDTH) - self::WIDTH;
    $topMargin = 0;

    $this->position = new Vector2($leftMargin, $topMargin);
    $this->renderPosition = clone $this->position;
    $this->exitDirection ??= $this->enterDirection;
    $this->contentPadding =
      new WindowPadding(0, 1, 0, 1);
    $this->contentAlignment =
      new WindowAlignment(HorizontalAlignment::LEFT, VerticalAlignment::MIDDLE);

    $this->window = new Window(
      $this->channel->value,
      '',
      $this->position,
      self::WIDTH,
      self::HEIGHT,
      $this->borderPack,
      $this->contentAlignment,
      $this->contentPadding
    );
  }

  /**
   * Returns the position of the notification window.
   *
   * @return Vector2 Returns the notification position.
   */
  public function getPosition(): Vector2
  {
    return $this->position;
  }

  /**
   * Updates the notification position.
   *
   * @param Vector2 $position The notification position.
   * @return $this Returns the notification.
   */
  public function setPosition(Vector2 $position): static
  {
    $this->position = $position;
    if (! $this->isOpen || in_array($this->state, [self::STATE_VISIBLE, self::STATE_FINISHED, self::STATE_HIDDEN], true)) {
      $this->renderPosition = clone $position;
      $this->window->setPosition($position);
    }

    return $this;
  }

  /**
   * @inheritDoc
   */
  public function open(?Vector2 $position = null): static
  {
    if ($position) {
      $this->setPosition($position);
    }

    $this->isOpen = true;
    $this->isDismissing = false;
    $this->state = $this->shouldAnimate($this->enterDirection)
      ? self::STATE_ENTERING
      : self::STATE_VISIBLE;
    $this->animationStartedAt = \Ichiloto\Engine\Core\Time::getTime();
    $this->lastRenderedPosition = null;
    $this->buildWindowContent();
    $this->renderPosition = $this->state === self::STATE_ENTERING
      ? $this->getHiddenPosition($this->enterDirection)
      : clone $this->position;
    $this->window->setPosition($this->renderPosition);

    $this->eventManager->dispatchEvent(new NotificationEvent(NotificationEventType::OPEN));
    return $this;
  }

  /**
   * @inheritDoc
   */
  public function dismiss(): static
  {
    if (! $this->isOpen || $this->isDismissing || $this->state === self::STATE_FINISHED) {
      return $this;
    }

    $this->isDismissing = true;
    $this->state = $this->shouldAnimate($this->exitDirection)
      ? self::STATE_EXITING
      : self::STATE_FINISHED;
    $this->animationStartedAt = \Ichiloto\Engine\Core\Time::getTime();

    if ($this->state === self::STATE_FINISHED) {
      $this->erase();
      $this->isOpen = false;
    }

    $this->eventManager->dispatchEvent(new NotificationEvent(NotificationEventType::DISMISS));
    return $this;
  }

  /**
   * @inheritDoc
   */
  public function render(?int $x = null, ?int $y = null): void
  {
    if ($this->isOpen) {
      if ($this->lastRenderedPosition instanceof Vector2 && $this->hasMovedSinceLastRender()) {
        $this->eraseAt($this->lastRenderedPosition, $x, $y);
      }

      $this->renderWindowAt($this->renderPosition, $x, $y);
      $this->lastRenderedPosition = clone $this->renderPosition;
    }

    $this->eventManager->dispatchEvent(new NotificationEvent(NotificationEventType::RENDER));
  }

  /**
   * @inheritDoc
   */
  public function erase(?int $x = null, ?int $y = null): void
  {
    if ($this->lastRenderedPosition instanceof Vector2) {
      $this->eraseAt($this->lastRenderedPosition, $x, $y);
      $this->lastRenderedPosition = null;
    } elseif ($this->isOpen) {
      $this->window->erase($x, $y);
    }

    $this->eventManager->dispatchEvent(new NotificationEvent(NotificationEventType::ERASE));
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    $this->buildWindowContent();

    $this->eventManager->dispatchEvent(new NotificationEvent(NotificationEventType::RESUME));
  }

  /**
   * @inheritDoc
   */
  public function suspend(): void
  {
    $this->erase();

    $this->eventManager->dispatchEvent(new NotificationEvent(NotificationEventType::SUSPEND));
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    if ($this->isOpen) {
      $this->advanceAnimation();
    }

    $this->eventManager->dispatchEvent(new NotificationEvent(NotificationEventType::UPDATE));
  }

  /**
   * @inheritDoc
   */
  public function getChannel(): NotificationChannel
  {
    return $this->channel;
  }

  /**
   * @inheritDoc
   */
  public function setChannel(NotificationChannel $channel): static
  {
    $this->channel = $channel;

    return $this;
  }

  /**
   * @inheritDoc
   */
  public function getContentTitle(): string
  {
    return $this->contentTitle;
  }

  /**
   * @inheritDoc
   */
  public function setContentTitle(string $contentTitle): static
  {
    $this->contentTitle = $contentTitle;
    $this->buildWindowContent();

    return $this;
  }

  /**
   * @inheritDoc
   */
  public function getContentText(): string
  {
    return $this->contentText;
  }

  /**
   * @inheritDoc
   */
  public function setContentText(string $contentText): static
  {
    $this->contentText = $contentText;

    return $this;
  }

  /**
   * @inheritDoc
   */
  public function getDuration(): float
  {
    return $this->duration instanceof NotificationDuration
      ? $this->duration->toFloat()
      : $this->duration;
  }

  /**
   * @inheritDoc
   */
  public function setDuration(NotificationDuration|float $duration): static
  {
    $this->duration = $duration;

    return $this;
  }

  /**
   * @inheritDoc
   */
  public function getAnimationDuration(): float
  {
    return max(0.0, $this->animationDuration);
  }

  /**
   * @inheritDoc
   */
  public function setEnterDirection(NotificationSlideDirection $direction): static
  {
    $this->enterDirection = $direction;

    return $this;
  }

  /**
   * @inheritDoc
   */
  public function setExitDirection(NotificationSlideDirection $direction): static
  {
    $this->exitDirection = $direction;

    return $this;
  }

  /**
   * @inheritDoc
   */
  public function isFinished(): bool
  {
    return $this->state === self::STATE_FINISHED;
  }

  /**
   * Builds the notification window content.
   *
   * @return void
   */
  private function buildWindowContent(): void
  {
    $this->content = [
      $this->getContentTitle(),
      $this->getContentText()
    ];
    $this->window->setContent($this->content);
  }

  /**
   * Advances the active entry or exit animation.
   *
   * @return void
   */
  private function advanceAnimation(): void
  {
    if (! in_array($this->state, [self::STATE_ENTERING, self::STATE_EXITING], true)) {
      return;
    }

    $progress = $this->getAnimationProgress();

    if ($this->state === self::STATE_ENTERING) {
      $this->renderPosition = $this->interpolatePosition(
        $this->getHiddenPosition($this->enterDirection),
        $this->position,
        $progress
      );

      if ($progress >= 1.0) {
        $this->state = self::STATE_VISIBLE;
        $this->renderPosition = clone $this->position;
      }

      return;
    }

    $this->renderPosition = $this->interpolatePosition(
      $this->position,
      $this->getHiddenPosition($this->exitDirection ?? $this->enterDirection),
      $progress
    );

    if ($progress >= 1.0) {
      $this->erase();
      $this->isOpen = false;
      $this->state = self::STATE_FINISHED;
      $this->isDismissing = false;
      $this->renderPosition = clone $this->position;
    }
  }

  /**
   * Returns the current animation progress between 0 and 1.
   *
   * @return float The current animation progress.
   */
  private function getAnimationProgress(): float
  {
    if ($this->getAnimationDuration() <= 0.0) {
      return 1.0;
    }

    $elapsed = \Ichiloto\Engine\Core\Time::getTime() - $this->animationStartedAt;

    return clamp($elapsed / $this->getAnimationDuration(), 0.0, 1.0);
  }

  /**
   * Returns the off-screen position for the requested slide direction.
   *
   * @param NotificationSlideDirection $direction The slide direction.
   * @return Vector2 The hidden position.
   */
  private function getHiddenPosition(NotificationSlideDirection $direction): Vector2
  {
    return match ($direction) {
      NotificationSlideDirection::LEFT => new Vector2(-self::WIDTH, $this->position->y),
      NotificationSlideDirection::RIGHT => new Vector2(get_screen_width(), $this->position->y),
      NotificationSlideDirection::UP => new Vector2($this->position->x, -self::HEIGHT),
      NotificationSlideDirection::DOWN => new Vector2($this->position->x, get_screen_height()),
      NotificationSlideDirection::NONE => clone $this->position,
    };
  }

  /**
   * Linearly interpolates between two positions.
   *
   * @param Vector2 $from The starting position.
   * @param Vector2 $to The target position.
   * @param float $progress The current interpolation progress.
   * @return Vector2 The interpolated position.
   */
  private function interpolatePosition(Vector2 $from, Vector2 $to, float $progress): Vector2
  {
    return new Vector2(
      intval(round($from->x + (($to->x - $from->x) * $progress))),
      intval(round($from->y + (($to->y - $from->y) * $progress)))
    );
  }

  /**
   * Erases the notification window at the requested position.
   *
   * @param Vector2 $position The position to erase.
   * @param int|null $x The external x-offset.
   * @param int|null $y The external y-offset.
   * @return void
   */
  private function eraseAt(Vector2 $position, ?int $x = null, ?int $y = null): void
  {
    $origin = $this->resolveRenderOrigin($position, $x, $y);
    $screenWidth = get_screen_width();
    $screenHeight = get_screen_height();

    foreach ($this->getRenderableLines() as $rowIndex => $line) {
      $targetY = $origin->y + $rowIndex;

      if ($targetY < 1 || $targetY > $screenHeight) {
        continue;
      }

      [$visibleX, $visibleWidth] = $this->getVisibleHorizontalSegment($origin->x, TerminalText::displayWidth($line), $screenWidth);

      if ($visibleWidth < 1) {
        continue;
      }

      Console::cursor()->moveTo($visibleX, $targetY);
      echo str_repeat(' ', $visibleWidth);
    }
  }

  /**
   * Returns whether the notification moved since the previous render.
   *
   * @return bool True when the render position changed.
   */
  private function hasMovedSinceLastRender(): bool
  {
    if (! $this->lastRenderedPosition instanceof Vector2) {
      return true;
    }

    return $this->lastRenderedPosition->x !== $this->renderPosition->x
      || $this->lastRenderedPosition->y !== $this->renderPosition->y;
  }

  /**
   * Returns whether the given direction should animate.
   *
   * @param NotificationSlideDirection|null $direction The direction to inspect.
   * @return bool True when sliding motion should be used.
   */
  private function shouldAnimate(?NotificationSlideDirection $direction): bool
  {
    return $this->getAnimationDuration() > 0.0
      && $direction !== null
      && $direction !== NotificationSlideDirection::NONE;
  }

  /**
   * Renders the notification window at the requested position with screen clipping.
   *
   * @param Vector2 $position The animated window position.
   * @param int|null $x The external x-offset.
   * @param int|null $y The external y-offset.
   * @return void
   */
  private function renderWindowAt(Vector2 $position, ?int $x = null, ?int $y = null): void
  {
    $origin = $this->resolveRenderOrigin($position, $x, $y);
    $screenWidth = get_screen_width();
    $screenHeight = get_screen_height();

    foreach ($this->getRenderableLines() as $rowIndex => $line) {
      $targetY = $origin->y + $rowIndex;

      if ($targetY < 1 || $targetY > $screenHeight) {
        continue;
      }

      [$visibleX, $visibleWidth, $clipOffset] = $this->getVisibleHorizontalSegment(
        $origin->x,
        TerminalText::displayWidth($line),
        $screenWidth,
        true
      );

      if ($visibleWidth < 1) {
        continue;
      }

      $visibleLine = $this->sliceLineByWidth($line, $clipOffset, $visibleWidth);

      if ($visibleLine === '') {
        continue;
      }

      Console::cursor()->moveTo($visibleX, $targetY);
      echo $visibleLine;
    }
  }

  /**
   * Resolves the absolute render origin from the animated position and offsets.
   *
   * @param Vector2 $position The animated notification position.
   * @param int|null $x The external x-offset.
   * @param int|null $y The external y-offset.
   * @return Vector2 The absolute render origin.
   */
  private function resolveRenderOrigin(Vector2 $position, ?int $x = null, ?int $y = null): Vector2
  {
    return new Vector2(
      intval(round($position->x + intval($x ?? 0))),
      intval(round($position->y + intval($y ?? 0)))
    );
  }

  /**
   * Builds the notification's full renderable lines.
   *
   * @return string[] The renderable lines.
   */
  private function getRenderableLines(): array
  {
    $lines = [];
    $title = $this->window->getTitle();
    $titleWidth = TerminalText::displayWidth($title);
    $topBorderLength = max(0, self::WIDTH - $titleWidth - 3);
    $availableWidth = max(
      0,
      self::WIDTH - 2 - $this->contentPadding->getLeftPadding() - $this->contentPadding->getRightPadding()
    );

    $lines[] = $this->borderPack->getTopLeftCorner()
      . $this->borderPack->getHorizontalBorder()
      . $title
      . str_repeat($this->borderPack->getHorizontalBorder(), $topBorderLength)
      . $this->borderPack->getTopRightCorner();

    foreach ($this->content as $line) {
      $lines[] = $this->borderPack->getVerticalBorder()
        . str_repeat(' ', $this->contentPadding->getLeftPadding())
        . TerminalText::padRight(strval($line), $availableWidth)
        . str_repeat(' ', $this->contentPadding->getRightPadding())
        . $this->borderPack->getVerticalBorder();
    }

    $lines[] = $this->borderPack->getBottomLeftCorner()
      . $this->borderPack->getHorizontalBorder()
      . str_repeat($this->borderPack->getHorizontalBorder(), self::WIDTH - 3)
      . $this->borderPack->getBottomRightCorner();

    return $lines;
  }

  /**
   * Returns the visible horizontal segment for a partially clipped line.
   *
   * @param int $startX The line's starting x position.
   * @param int $lineWidth The full line width.
   * @param int $screenWidth The visible screen width.
   * @param bool $includeClipOffset Whether to include the line clip offset.
   * @return array{0: int, 1: int, 2?: int} The visible x position, width, and optional clip offset.
   */
  private function getVisibleHorizontalSegment(
    int $startX,
    int $lineWidth,
    int $screenWidth,
    bool $includeClipOffset = false
  ): array
  {
    $visibleStartX = max(1, $startX);
    $visibleEndX = min($screenWidth, $startX + $lineWidth - 1);
    $visibleWidth = max(0, $visibleEndX - $visibleStartX + 1);
    $clipOffset = max(0, $visibleStartX - $startX);

    return $includeClipOffset
      ? [$visibleStartX, $visibleWidth, $clipOffset]
      : [$visibleStartX, $visibleWidth];
  }

  /**
   * Returns a display-width-aware slice of the given line.
   *
   * @param string $line The line to slice.
   * @param int $startWidth The width offset to skip.
   * @param int $visibleWidth The width to keep.
   * @return string The clipped line.
   */
  private function sliceLineByWidth(string $line, int $startWidth, int $visibleWidth): string
  {
    if ($visibleWidth < 1) {
      return '';
    }

    $currentWidth = 0;
    $output = [];
    $endWidth = $startWidth + $visibleWidth;

    foreach (TerminalText::visibleSymbols($line) as $symbol) {
      $symbolWidth = TerminalText::displayWidth($symbol);
      $nextWidth = $currentWidth + $symbolWidth;

      if ($nextWidth <= $startWidth) {
        $currentWidth = $nextWidth;
        continue;
      }

      if ($currentWidth >= $endWidth || $nextWidth > $endWidth) {
        break;
      }

      if ($currentWidth >= $startWidth) {
        $output[] = $symbol;
      }

      $currentWidth = $nextWidth;
    }

    return implode('', $output);
  }
}
