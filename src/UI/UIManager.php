<?php

namespace Ichiloto\Engine\UI;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Core\Interfaces\CanStart;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\Interfaces\UIElementInterface;
use Ichiloto\Engine\UI\Interfaces\LayeredPresentationInterface;
use Ichiloto\Engine\UI\Interfaces\LayeredUIElementInterface;

/**
 * The UI manager.
 */
class UIManager implements CanRender, CanUpdate, CanResume, CanStart
{
  /**
   * @var UIManager The instance of the UI manager.
   */
  protected static UIManager $instance;
  /**
   * @var LocationHUDWindow The location HUD window.
   */
  public LocationHUDWindow $locationHUDWindow;
  /**
   * @var ItemList<UIElementInterface> The UI elements.
   */
  protected(set) ItemList $uiElements;
  /** @var array<int, LayeredPresentationInterface> Active higher layers. */
  protected array $presentations = [];
  /** @var array<int, true> Layers to remove at the next frame boundary. */
  protected array $pendingPresentationDismissals = [];
  /** @var array<int, true> UI elements suppressed during the last render. */
  protected array $suppressedUiElements = [];
  /** @var array<int, true> UI elements known to have drawn a footprint. */
  protected array $renderedUiElements = [];

  /**
   * UIManager constructor.
   */
  protected function __construct(protected(set) Game $game)
  {
    $this->uiElements = new ItemList(UIElementInterface::class);
  }

  /**
   * Returns the instance of the UI manager.
   *
   * @param Game $game The game.
   */
  public static function getInstance(Game $game): self
  {
    if (!isset(self::$instance)) {
      self::$instance = new self($game);
    }

    return self::$instance;
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    foreach ($this->uiElements as $uiElement) {
      $id = spl_object_id($uiElement);

      if (! $this->isVisible($uiElement)) {
        unset($this->suppressedUiElements[$id], $this->renderedUiElements[$id]);
        continue;
      }

      if ($uiElement instanceof LayeredPresentationInterface && $this->isSuppressed($uiElement)) {
        $this->suppressedUiElements[$id] = true;
        unset($this->renderedUiElements[$id]);
        continue;
      }

      unset($this->suppressedUiElements[$id]);
      PresentationLayerPolicy::ui($uiElement, $uiElement->render(...));
      $this->renderedUiElements[$id] = true;
    }
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    foreach ($this->uiElements as $uiElement) {
      if ($this->isVisible($uiElement)) {
        $uiElement->erase();
      }

      unset(
        $this->renderedUiElements[spl_object_id($uiElement)],
        $this->suppressedUiElements[spl_object_id($uiElement)],
      );
    }
  }

  /**
   * Registers a higher-precedence presentation before it is drawn.
   *
   * Any already-rendered lower layer is erased first, so a partially
   * intersecting modal never leaves the remainder of that HUD behind.
   */
  public function present(LayeredPresentationInterface $presentation): void
  {
    $id = spl_object_id($presentation);
    $this->presentations[$id] = $presentation;
    unset($this->pendingPresentationDismissals[$id]);

    foreach ($this->uiElements as $uiElement) {
      $uiId = spl_object_id($uiElement);

      if (
        $this->isVisible($uiElement)
        && $uiElement instanceof LayeredPresentationInterface
        && isset($this->renderedUiElements[$uiId])
        && $this->isSuppressed($uiElement)
      ) {
        $uiElement->erase();
        unset($this->renderedUiElements[$uiId]);
        $this->suppressedUiElements[$uiId] = true;
      }
    }
  }

  /**
   * Defers removal until the next game-render boundary.
   *
   * Story commands can replace dialogue with another page or a choice in one
   * update. Keeping the old precedence reservation through that update stops
   * lower HUD layers flashing between the two presentations.
   */
  public function dismiss(LayeredPresentationInterface $presentation): void
  {
    $id = spl_object_id($presentation);

    if (isset($this->presentations[$id])) {
      $this->pendingPresentationDismissals[$id] = true;
    }
  }

  /** Applies deferred presentation removal at the main frame boundary. */
  public function commitPresentationChanges(): void
  {
    foreach ($this->pendingPresentationDismissals as $id => $_) {
      unset($this->presentations[$id]);
    }

    $this->pendingPresentationDismissals = [];
  }

  /** Returns whether a lower layer currently yields to any intersecting one. */
  public function isSuppressed(LayeredPresentationInterface $presentation): bool
  {
    foreach ($this->presentations as $higherPresentation) {
      if (
        $higherPresentation !== $presentation
        && $higherPresentation->getPresentationPriority()->value
          > $presentation->getPresentationPriority()->value
        && $higherPresentation->getPresentationBounds()->intersects(
          $presentation->getPresentationBounds(),
        )
      ) {
        return true;
      }
    }

    return false;
  }

  /** Returns whether an element intends to draw before occlusion is applied. */
  protected function isVisible(UIElementInterface $uiElement): bool
  {
    return $uiElement->isActive
      && (! $uiElement instanceof LayeredUIElementInterface
        || $uiElement->isPresentationVisible());
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    foreach ($this->uiElements as $uiElement) {
      if ($uiElement->isActive && $uiElement instanceof CanResume) {
        $uiElement->resume();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function suspend(): void
  {
    foreach ($this->uiElements as $uiElement) {
      if ($uiElement->isActive && $uiElement instanceof CanResume) {
        $uiElement->suspend();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function start(): void
  {
    // Do nothing. The UI manager is always running.
  }

  /**
   * @inheritDoc
   */
  public function stop(): void
  {
    // Do nothing. The UI manager is always running.
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    // Do nothing. The UI manager is always running.
  }
}
