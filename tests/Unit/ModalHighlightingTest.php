<?php

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\UI\Modal\Modal;
use Ichiloto\Engine\UI\Modal\SelectModal;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use Symfony\Component\Console\Output\BufferedOutput;

class ModalArrayConfigStub implements ConfigInterface
{
  public function __construct(private array $values = [])
  {
  }

  public function get(string $path, mixed $default = null): mixed
  {
    $segments = explode('.', $path);
    $value = $this->values;

    foreach ($segments as $segment) {
      if (! is_array($value) || ! array_key_exists($segment, $value)) {
        return $default;
      }

      $value = $value[$segment];
    }

    return $value;
  }

  public function set(string $path, mixed $value): void
  {
    $segments = explode('.', $path);
    $target = &$this->values;

    foreach ($segments as $segment) {
      if (! isset($target[$segment]) || ! is_array($target[$segment])) {
        $target[$segment] = [];
      }

      $target = &$target[$segment];
    }

    $target = $value;
  }

  public function has(string $path): bool
  {
    $sentinel = new stdClass();

    return $this->get($path, $sentinel) !== $sentinel;
  }

  public function persist(): void
  {
    // Test stub; persistence is not needed.
  }
}

class ModalButtonHighlightProxy extends Modal
{
  private BufferedOutput $buffer;

  public function __construct(array $buttons, int $activeIndex, int $width = 24)
  {
    $this->buttons = $buttons;
    $this->activeIndex = $activeIndex;
    $this->rect = new Rect(0, 0, $width, 4);
    $this->borderPack = new DefaultBorderPack();
    $this->output = $this->buffer = new BufferedOutput();
  }

  public function captureButtons(): string
  {
    $this->renderButtons();

    return $this->buffer->fetch();
  }
}

class SelectModalHighlightProxy extends SelectModal
{
  private BufferedOutput $buffer;

  public function __construct(array $options, int $activeIndex, string $message = '')
  {
    $this->options = $options;
    $this->totalOptions = count($options);
    $this->activeOptionIndex = $activeIndex;
    $this->message = $message;
    $this->messageLines = $message === '' ? [] : explode("\n", $message);
    $this->messageContentHeight = count($this->messageLines);
    $this->rect = new Rect(0, 0, 24, 3);
    $this->borderPack = new DefaultBorderPack();
    $this->output = $this->buffer = new BufferedOutput();
  }

  public function captureOptions(): string
  {
    ob_start();
    $this->renderOptions(1, 1);
    ob_end_clean();

    return $this->buffer->fetch();
  }
}

it('uses the configured selection color for modal buttons', function () {
  ConfigStore::put(ProjectConfig::class, new ModalArrayConfigStub([
    'ui' => [
      'menu' => [
        'selection_color' => Color::YELLOW,
      ],
    ],
  ]));

  $modal = new ModalButtonHighlightProxy(['OK', 'Cancel'], 1);
  $rendered = $modal->captureButtons();

  expect($rendered)->toContain(Color::YELLOW->value)
    ->not->toContain(Color::LIGHT_BLUE->value)
    ->toContain('Cancel');
});

it('uses the configured selection color for select modal options', function () {
  ConfigStore::put(ProjectConfig::class, new ModalArrayConfigStub([
    'ui' => [
      'menu' => [
        'selection_color' => Color::LIGHT_GREEN,
      ],
    ],
  ]));

  $modal = new SelectModalHighlightProxy(['Potion', 'Phoenix Down'], 1);
  $rendered = $modal->captureOptions();

  expect($rendered)->toContain(Color::LIGHT_GREEN->value)
    ->not->toContain(Color::LIGHT_BLUE->value)
    ->toContain('Phoenix Down');
});
