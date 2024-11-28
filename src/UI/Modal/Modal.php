<?php

namespace Ichiloto\Engine\UI\Modal;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Events\Enumerations\ModalEventType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Events\Interfaces\StaticObserverInterface;
use Ichiloto\Engine\Events\ModalEvent;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Modal. Represents a modal.
 *
 * @package Ichiloto\Engine\UI\Modal
 */
abstract class Modal implements ModalInterface
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
      '',
      $this->rect->position,
      $this->rect->getWidth(),
      $this->rect->getHeight(),
      $this->borderPack
    );
    $this->window->setContent($this->content);
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {

    Console::cursor()->moveTo($this->leftMargin, $this->topMargin);
    $this->renderTopBorder();
    Console::cursor()->moveTo($this->leftMargin, $this->topMargin + 1);
    $this->renderContent();
    Console::cursor()->moveTo($this->leftMargin, $this->topMargin + 1 + $this->contentHeight);
    $this->renderButtons();
    Console::cursor()->moveTo($this->leftMargin, $this->topMargin + 1 + $this->contentHeight + 1);
    $this->renderBottomBorder();
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
    $this->eventManager->dispatchEvent(new ModalEvent(ModalEventType::SHOW, true));
  }

  /**
   * @inheritDoc
   */
  public function hide(): void
  {
    $this->erase();
    $this->isShowing = false;
    $this->eventManager->dispatchEvent(new ModalEvent(ModalEventType::HIDE, false));
  }

  /**
   * @inheritDoc
   */
  public function open(): mixed
  {
    $this->eventManager->dispatchEvent(new ModalEvent(ModalEventType::OPEN, true));
    $this->leftMargin = (int)( (get_screen_width() / 2) - ($this->rect->getWidth() / 2) );
    $this->topMargin = (int)( (get_screen_height() / 2) - ($this->rect->getHeight() / 2) );
    $this->show();
    $sleepTime = (int)(1000000 / 60);

    while ($this->isShowing) {
      $this->handleInput();
      $this->update();
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
    $this->eventManager->dispatchEvent(new ModalEvent(ModalEventType::CLOSE, $this->value));
    $this->hide();
    return $this->value;
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
    $this->value = $this->buttons[$this->activeIndex];
    $this->hide();
  }

  /**
   * Cancels the modal.
   */
  protected function cancel(): void
  {
    $this->value = null;
    $this->hide();
  }

  /**
   * Renders the top border.
   *
   * @return void
   */
  protected function renderTopBorder(): void
  {
    $titleLength = strlen($this->title);
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
    $helpLength = strlen($this->help);
    $horizontalBorder = str_repeat($this->borderPack->getHorizontalBorder(), $this->rect->getWidth() - $helpLength - 3);
    $output = $this->borderPack->getBottomLeftCorner() .
      $this->borderPack->getHorizontalBorder() .
      $this->help .
      $horizontalBorder .
      $this->borderPack->getBottomRightCorner();
    $this->output->write($output);
  }

  /**
   * Renders the content.
   *
   * @return void
   */
  protected function renderContent(): void
  {
    foreach ($this->content as $line) {
      $output = $this->borderPack->getVerticalBorder() .
        str_pad($line, $this->rect->getWidth() - 2, ' ', STR_PAD_BOTH) .
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
    $activeColor = Color::LIGHT_BLUE;
    $buttonOutput = implode(' ', $this->buttons);
    $buttonOutputLength = strlen($buttonOutput);
    $padding = (int) (($this->rect->getWidth() - 2 - $buttonOutputLength) / 2);
    $output = str_repeat(' ', $padding) . $buttonOutput . str_repeat(' ', $padding);
    if (strlen($output) % 2 !== 0) {
      $output .= ' ';
    }
    $output =
      $this->borderPack->getVerticalBorder() .
      str_replace($this->buttons[$this->activeIndex] ?? '', Color::apply($this->buttons[$this->activeIndex] ?? '', $activeColor), $output) .
      $this->borderPack->getVerticalBorder();
    $this->output->write($output);
  }
}