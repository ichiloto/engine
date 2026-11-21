<?php

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Messaging\Dialogue\DialogueChoiceModal;
use Ichiloto\Engine\UI\Modal\Modal;
use Ichiloto\Engine\UI\Modal\SelectModal;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;

beforeEach(function (): void {
  $this->savedProjectConfig = ConfigStore::has(ProjectConfig::class)
    ? ConfigStore::get(ProjectConfig::class)
    : null;
});

afterEach(function (): void {
  if ($this->savedProjectConfig !== null) {
    ConfigStore::put(ProjectConfig::class, $this->savedProjectConfig);
  } else {
    ConfigStore::remove(ProjectConfig::class);
  }
});

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
  public function __construct(array $buttons, int $activeIndex, int $width = 24)
  {
    $this->buttons = $buttons;
    $this->activeIndex = $activeIndex;
    $this->rect = new Rect(0, 0, $width, 4);
    $this->borderPack = new DefaultBorderPack();
  }

  public function captureButtons(): string
  {
    return $this->renderedButtonLine();
  }
}

class SelectModalHighlightProxy extends SelectModal
{
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
  }

  public function captureOptions(): string
  {
    return implode("\n", $this->renderedOptionLines());
  }
}

class DialogueChoiceHighlightProxy extends DialogueChoiceModal
{
  public function __construct(array $options, int $activeIndex)
  {
    $this->options = $options;
    $this->totalOptions = count($options);
    $this->activeOptionIndex = $activeIndex;
    $this->message = '';
    $this->messageLines = [];
    $this->messageContentHeight = 0;
    $this->rect = new Rect(0, 0, 24, 3);
    $this->borderPack = new DefaultBorderPack();
  }

  public function captureOptions(): string
  {
    return implode("\n", $this->renderedOptionLines());
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

it('draws select modal choices as stacked centred buttons without the list cursor', function () {
  ConfigStore::put(ProjectConfig::class, new ModalArrayConfigStub(['ui' => ['menu' => ['selection_color' => Color::YELLOW]]]));
  // The Quit dialog's choices, as OpenQuitMenuCommand offers them.
  $choices = ['To Title', 'Exit', 'Cancel'];
  $lines = array_slice(explode("\n", new SelectModalHighlightProxy($choices, 0)->captureOptions()), 0, 3);
  $innerWidth = 24 - 2;

  expect(array_map(TerminalText::stripAnsi(...), $lines))
    ->toBe(array_map(fn(string $label): string => TerminalText::padCenter($label, $innerWidth), $choices));
  foreach ($lines as $line) {
    expect(TerminalText::stripAnsi($line))->not->toContain('>');
  }
  // A steady focus: the selection colour covers the focused label alone, with no blink and none of its padding.
  expect($lines[0])->toContain(Color::YELLOW->value . 'To Title' . Color::RESET->value)
    ->not->toContain("\033[5m")
    ->and($lines[1])->not->toContain(Color::YELLOW->value)
    ->and($lines[2])->not->toContain(Color::YELLOW->value);
});

it('highlights the focused modal button by its position, never by matching its text', function () {
  ConfigStore::put(ProjectConfig::class, new ModalArrayConfigStub(['ui' => ['menu' => ['selection_color' => Color::YELLOW]]]));

  expect(new ModalButtonHighlightProxy(['No', 'Not now'], 1)->captureButtons())
    ->toBe('No ' . Color::YELLOW->value . 'Not now' . Color::RESET->value)
    ->and(new ModalButtonHighlightProxy(['Not now', 'No'], 1)->captureButtons())
    ->toBe('Not now ' . Color::YELLOW->value . 'No' . Color::RESET->value);
});

it('keeps dialogue answers as left-aligned list rows with the cursor', function () {
  ConfigStore::put(ProjectConfig::class, new ModalArrayConfigStub(['ui' => ['menu' => ['selection_color' => Color::YELLOW]]]));
  // Andrew, 21 November 2026: "Dialogue stays a list".
  $lines = array_slice(explode("\n", new DialogueChoiceHighlightProxy(['Yes, please.', 'Not now'], 0)->captureOptions()), 0, 2);

  expect($lines[0])->toBe(Color::YELLOW->value . ' > Yes, please.' . Color::RESET->value)
    ->and($lines[1])->toBe('   Not now');
});
