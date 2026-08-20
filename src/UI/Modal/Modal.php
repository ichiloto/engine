<?php

namespace Ichiloto\Engine\UI\Modal;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\ModalEventType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Events\Interfaces\StaticObserverInterface;
use Ichiloto\Engine\Events\ModalEvent;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Interfaces\LayeredPresentationInterface;
use Ichiloto\Engine\UI\Enumerations\PresentationPriority;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\UI\Windows\WindowAlignment;
use Ichiloto\Engine\UI\Windows\WindowPadding;
use Ichiloto\Engine\Util\Debug;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Modal. Represents a modal.
 *
 * @package Ichiloto\Engine\UI\Modal
 */
abstract class Modal implements ModalInterface, LayeredPresentationInterface
{
  /**
   * @var Window $window The window of the modal.
   */
  protected Window $window;
  /**
   * @var int $activeIndex The active index.
   */
  protected(set) int $activeIndex = 0;
  /**
   * @var string $activeButton The active button.
   */
  public string $activeButton {
    get {
      return $this->buttons[$this->activeIndex] ?? '';
    }
  }
  /**
   * @var ItemList<ObserverInterface> $observers The observers of the modal.
   */
  protected ItemList $observers;
  /**
   * @var ItemList<StaticObserverInterface> $staticObservers The static observers of the modal.
   */
  protected ItemList $staticObservers;
  /**
   * @var mixed $value The value of the modal.
   */
  protected mixed $value = null;
  /**
   * @var EventManager $eventManager The event manager.
   */
  protected EventManager $eventManager;
  /**
   * @var bool $isShowing Whether the modal is showing.
   */
  protected(set) bool $isShowing = false;
  /**
   * @var int $width The width of the modal.
   */
  protected int $leftMargin = 0;
  /**
   * @var int $height The height of the modal.
   */
  protected int $topMargin = 0;
  /**
   * @var int $contentHeight The height of the content.
   */
  protected int $contentHeight = 0;
  /**
   * @var OutputInterface $output The output.
   */
  protected OutputInterface $output;
  /**
   * @var string[] $content The content of the modal.
   */
  protected array $content = [];

  /**
   * Modal constructor.
   *
   * @param Game $game The game instance.
   * @param string $message The content of the modal.
   * @param string $title The title of the modal.
   * @param Rect $rect The rectangle of the modal.
   * @param array $buttons The buttons of the modal.
   * @param string|null $help The help of the modal.
   * @param BorderPackInterface $borderPack The border pack of the modal.
   */
  public function __construct(
    protected Game $game,
    public string $message {
      get {
        return $this->message;
      }
      set {
        $this->message = $value;
        $this->content = explode("\n", $value);
        $this->contentHeight = count($this->content);
      }
    },
    public string $title = '',
    protected(set) Rect $rect = new Rect(0, 0, DEFAULT_DIALOG_WIDTH, DEFAULT_DIALOG_HEIGHT),
    public array $buttons = ['OK'],
    protected ?string $help = 'c:cancel',
    public BorderPackInterface $borderPack = new DefaultBorderPack()
  )
  {
    $this->observers = new ItemList(ObserverInterface::class);
    $this->staticObservers = new ItemList(StaticObserverInterface::class);
    $this->eventManager = EventManager::getInstance($this->game);
    $this->output = new ConsoleOutput();
    $this->window = new Window(
      $this->title,
      $this->help ?? '',
      $this->rect->position,
      $this->rect->getWidth(),
      $this->rect->getHeight(),
      $this->borderPack,
      WindowAlignment::middleCenter(),
      new WindowPadding(),
    );
    $this->window->setContent($this->content);
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    $this->window->setTitle($this->title);
    $this->window->setHelp($this->help ?? '');
    $this->window->setContent([
      ...$this->content,
      $this->renderedButtonLine(),
    ]);
    $this->window->render();
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    $this->window->erase();
  }

  /**
   * Handles the input.
   */
  protected function handleInput(): void
  {
    InputManager::handleInput();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $h = Input::getAxis(AxisName::HORIZONTAL);

    if (abs($h) > 0) {
      if ($h < 0) {
        $this->activeIndex = wrap($this->activeIndex - 1, 0, count($this->buttons) - 1);
      } else {
        $this->activeIndex = wrap($this->activeIndex + 1, 0, count($this->buttons) - 1);
      }

      if (count($this->buttons) > 1) {
        $this->game->audioManager->playSystemSound(SystemSound::CURSOR);
      }
    }

    if (Input::isButtonDown("confirm")) {
      $this->submit();
    }

    if (Input::isButtonDown("cancel")) {
      $this->cancel();
    }
  }

  /**
   * @inheritDoc
   */
  public function show(): void
  {
    $this->isShowing = true;
    $this->getUIManager()?->present($this);
    $this->eventManager->dispatchEvent(new ModalEvent(ModalEventType::SHOW, true));
  }

  /**
   * @inheritDoc
   */
  public function hide(): void
  {
    if ($this->isShowing) {
      $this->erase();
      $this->isShowing = false;
      $this->getUIManager()?->dismiss($this);
      $this->eventManager->dispatchEvent(new ModalEvent(ModalEventType::HIDE, false));
    }
  }

  /** @inheritDoc */
  public function getPresentationBounds(): Rect
  {
    return new Rect(
      $this->rect->getX(),
      $this->rect->getY(),
      $this->rect->getWidth(),
      $this->rect->getHeight(),
    );
  }

  /** @inheritDoc */
  public function getPresentationPriority(): PresentationPriority
  {
    return PresentationPriority::MODAL;
  }

  /** Returns the active scene's UI manager when the game is fully booted. */
  protected function getUIManager(): ?UIManager
  {
    if (! isset($this->game->sceneManager)) {
      return null;
    }

    return $this->game->sceneManager->currentScene?->getUI();
  }

  /**
   * @inheritDoc
   */
  public function open(): mixed
  {
    $this->eventManager->dispatchEvent(new ModalEvent(ModalEventType::OPEN, true));
    // Fit before centring, so the box is measured at its final height.
    $this->fitContentToWidth();
    $this->positionForOpen();
    $this->show();
    $this->render();
    $sleepTime = (int)(1000000 / 60);

    while ($this->isShowing) {
      $this->handleInput();
      $this->update();

      // submit() and cancel() hide and erase the modal. Drawing again in the
      // same iteration resurrects the overlay after dismissal, and close()
      // will not erase it a second time because it is already hidden.
      if (! $this->isShowing) {
        break;
      }

      // The game loop is not running while this modal is up, so the world is
      // ticked from here: music keeps looping and engine time keeps advancing
      // rather than jumping when the modal closes.
      $this->game->tickWhileBlocked();

      // A modal is the highest-precedence screen layer. Timed lower layers
      // (notably sliding notifications) update above, then the modal owns the
      // final composition for every row in its footprint.
      $this->render();

      usleep($sleepTime);
    }

    return $this->close();
  }

  /**
   * @inheritDoc
   */
  public function close(): mixed
  {
    // Remove the overlay before the scene resumes. FieldState::resume()
    // redraws the world in response to CLOSE; erasing after that redraw would
    // blank the modal footprint and leave dynamic field objects missing.
    $this->hide();
    $this->eventManager->dispatchEvent(new ModalEvent(ModalEventType::CLOSE, $this->value));
    return $this->value;
  }

  /** @inheritDoc */
  public function isShowing(): bool
  {
    return $this->isShowing;
  }

  /**
   * @inheritDoc
   */
  public function addObserver(StaticObserverInterface|ObserverInterface|string $observer): void
  {
    if ($observer instanceof StaticObserverInterface) {
      $this->staticObservers->add($observer);
    }

    if ($observer instanceof ObserverInterface) {
      $this->observers->add($observer);
    }

    if (is_string($observer)) {
      if (class_exists($observer)) {
        if (is_subclass_of($observer, ObserverInterface::class)) {
          $this->observers->add(new $observer());
        }
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function removeObserver(StaticObserverInterface|ObserverInterface|string $observer): void
  {    if ($observer instanceof StaticObserverInterface) {
    $this->staticObservers->remove($observer);
  }

    if ($observer instanceof ObserverInterface) {
      $this->observers->remove($observer);
    }
  }

  /**
   * @inheritDoc
   */
  public function notify(object $entity, EventInterface $event): void
  {
    foreach ($this->observers as $observer) {
      $observer->onNotify($entity, $event);
    }

    foreach ($this->staticObservers as $observer) {
      $observer::onNotify($entity, $event);
    }
  }

  /**
   * Submits the modal.
   */
  protected function submit(): void
  {
    $this->playInteractionSound(SystemSound::CONFIRM);
    $this->value = $this->buttons[$this->activeIndex];
    $this->hide();
  }

  /**
   * Cancels the modal.
   */
  protected function cancel(): void
  {
    $this->playInteractionSound(SystemSound::CANCEL);
    $this->value = null;
    $this->hide();
  }

  /**
   * Plays a system sound for a modal interaction.
   *
   * Modals that repurpose the interaction flow for quiet flows (e.g. the
   * dialogue text box, where confirm merely advances the message) override
   * this to stay silent.
   *
   * @param SystemSound $sound The system sound to play.
   * @return void
   */
  protected function playInteractionSound(SystemSound $sound): void
  {
    $this->game->audioManager->playSystemSound($sound);
  }

  /**
   * Renders the top border.
   *
   * @return void
   */
  protected function renderTopBorder(): void
  {
    $titleLength = TerminalText::displayWidth($this->title);
    $horizontalBorder = str_repeat($this->borderPack->getHorizontalBorder(), $this->rect->getWidth() - $titleLength - 3);
    $output = $this->borderPack->getTopLeftCorner() .
      $this->borderPack->getHorizontalBorder() .
      $this->title .
      $horizontalBorder .
      $this->borderPack->getTopRightCorner();
    $this->output->write($output);
  }

  /**
   * Renders the bottom border.
   *
   * @return void
   */
  protected function renderBottomBorder(): void
  {
    $helpLength = TerminalText::displayWidth($this->help);
    $horizontalBorder = str_repeat($this->borderPack->getHorizontalBorder(), $this->rect->getWidth() - $helpLength - 3);
    $output = $this->borderPack->getBottomLeftCorner() .
      $this->borderPack->getHorizontalBorder() .
      $this->help .
      $horizontalBorder .
      $this->borderPack->getBottomRightCorner();
    $this->output->write($output);
  }

  /**
   * Wraps the message to the box's inner width and grows the box to fit.
   *
   * Without this a message longer than the dialog is simply cut off mid
   * sentence. The player sees "The shop is still shuttered. Perhaps someone
   * at" and never learns the rest. Wrapping is done on word boundaries, and
   * the box grows downward to hold the result.
   *
   * Subclasses that lay out their own text (the dialogue text box types its
   * message character by character) override this to do nothing.
   *
   * @return void
   */
  protected function fitContentToWidth(): void
  {
    $innerWidth = max(1, $this->rect->getWidth() - 2);
    $lines = [];

    foreach (explode("\n", $this->message) as $paragraph) {
      foreach (explode("\n", wordwrap($paragraph, $innerWidth, "\n", true)) as $line) {
        $lines[] = $line;
      }
    }

    $this->content = $lines;
    $this->contentHeight = count($lines);

    // Top border + content + button row + bottom border.
    $this->rect->setHeight($this->contentHeight + 3);
    // Window has no resize, so rebuild it at the fitted height. It owns the
    // erase footprint and would otherwise leave the extra rows on screen.
    $this->rebuildWindow();
    $this->window->setContent($this->content);
  }

  /** Rebuilds the canonical render/erase window after modal geometry changes. */
  protected function rebuildWindow(): void
  {
    $this->window = new Window(
      $this->title,
      $this->help ?? '',
      $this->rect->position,
      $this->rect->getWidth(),
      $this->rect->getHeight(),
      $this->borderPack,
      WindowAlignment::middleCenter(),
      new WindowPadding(),
    );
  }

  /**
   * Moves the modal rectangle and its erase/render window together.
   *
   * Modal geometry has one owner: if the visible box and the backing window
   * diverge, dismissal clears a different area than the one that was drawn.
   *
   * @param int $left The zero-based left edge.
   * @param int $top The zero-based top edge.
   * @return void
   */
  protected function setPosition(int $left, int $top): void
  {
    $this->leftMargin = max(0, $left);
    $this->topMargin = max(0, $top);
    $this->rect->position = new Vector2($this->leftMargin, $this->topMargin);
    $this->window->setPosition($this->rect->position);
  }

  /** Positions ordinary blocking modals in the center of the game screen. */
  protected function positionForOpen(): void
  {
    $this->setPosition(
      (int)((get_screen_width() - $this->rect->getWidth()) / 2),
      (int)((get_screen_height() - $this->rect->getHeight()) / 2),
    );
  }

  /** Returns the centered action text with the active button styled. */
  protected function renderedButtonLine(): string
  {
    $activeButton = $this->buttons[$this->activeIndex] ?? '';
    $buttonOutput = implode(' ', $this->buttons);

    if ($activeButton === '') {
      return $buttonOutput;
    }

    return str_replace(
      $activeButton,
      Color::apply($activeButton, $this->getSelectionColor()),
      $buttonOutput,
    );
  }

  /**
   * Renders the content.
   *
   * @return void
   */
  protected function renderContent(): void
  {
    foreach ($this->content as $index => $line) {
      // Each line is placed explicitly. Writing them back to back leaves the
      // second one trailing off the end of the first row.
      Console::cursor()->moveTo($this->leftMargin + 1, $this->topMargin + 2 + $index);
      $output = $this->borderPack->getVerticalBorder() .
        TerminalText::padCenter($line, $this->rect->getWidth() - 2) .
        $this->borderPack->getVerticalBorder();
      $this->output->write($output);
    }
  }

  /**
   * Renders the buttons.
   *
   * @return void
   */
  protected function renderButtons(): void
  {
    $activeColor = $this->getSelectionColor();
    $buttonOutput = implode(' ', $this->buttons);
    $output = TerminalText::padCenter($buttonOutput, $this->rect->getWidth() - 2);
    $output =
      $this->borderPack->getVerticalBorder() .
      str_replace($this->buttons[$this->activeIndex] ?? '', Color::apply($this->buttons[$this->activeIndex] ?? '', $activeColor), $output) .
      $this->borderPack->getVerticalBorder();
    $this->output->write($output);
  }

  /**
   * Resolves the configured highlight color for modal actions.
   *
   * @return Color The configured selection color.
   */
  protected function getSelectionColor(): Color
  {
    return SelectionStyle::resolveColor();
  }
}
