<?php

namespace Ichiloto\Engine\Field;

use Assegai\Util\Path;
use Ichiloto\Engine\Entities\Actions\FieldActionContext;
use Ichiloto\Engine\Entities\Interfaces\ActionInterface;
use Ichiloto\Engine\IO\InputManager;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;
use Throwable;

/** Project-owned secondary interaction, independent of NPC dialogue completion. */
final class FieldActionRegistry
{
  public const string PROJECT_FILE = 'Data/field-actions.php';

  /** @var class-string<ActionInterface>|null */
  private static ?string $secondaryHandler = null;

  public static function configureFromProject(?string $assetRoot = null): void
  {
    self::reset();
    $assetRoot ??= Path::join(Path::getCurrentWorkingDirectory(), 'assets');
    $filename = Path::join($assetRoot, self::PROJECT_FILE);
    if (!file_exists($filename) && !is_link($filename)) { return; }

    try {
      if (!is_file($filename) || !is_readable($filename)) {
        throw new InvalidArgumentException('The declaration must be a readable PHP file.');
      }
      $declaration = (static fn(): mixed => require $filename)();
      if (!is_array($declaration) || array_diff(array_keys($declaration), ['secondary_action']) !== []) {
        throw new InvalidArgumentException('Return a keyed declaration containing only secondary_action.');
      }
      self::configure($declaration['secondary_action'] ?? null);
    } catch (Throwable $exception) {
      throw new RuntimeException(sprintf('Field actions %s could not be loaded: %s',
        $filename, $exception->getMessage()), previous: $exception);
    }
  }

  /** @param class-string<ActionInterface>|null $handlerClass */
  public static function configure(?string $handlerClass): void
  {
    self::reset();
    if ($handlerClass === null) { return; }
    if (!class_exists($handlerClass) || !is_subclass_of($handlerClass, ActionInterface::class)) {
      throw new InvalidArgumentException('The secondary handler must exist and implement ActionInterface.');
    }
    $reflection = new ReflectionClass($handlerClass);
    if (!$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
      throw new InvalidArgumentException('The secondary handler must be instantiable without required constructor arguments.');
    }
    self::$secondaryHandler = $handlerClass;
  }

  /** Returns whether a project handler ran, not whether its gameplay action succeeded. */
  public static function executeSecondaryAction(FieldActionContext $context): bool
  {
    $handlerClass = self::$secondaryHandler;
    if ($handlerClass === null) { return false; }
    $context->scene->fieldState?->cancelWalking();
    // Consume before invoking: a scene or modal opened by Game must not accept this edge.
    InputManager::consumeCurrentInput();
    (new $handlerClass())->execute($context);
    return true;
  }

  public static function reset(): void { self::$secondaryHandler = null; }
}
