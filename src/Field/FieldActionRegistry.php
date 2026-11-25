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

  /** @param class-string<ActionInterface>|null $secondaryHandler */
  private function __construct(public readonly ?string $secondaryHandler)
  {
  }

  public static function fromProject(string $assetRoot): self
  {
    $filename = Path::join($assetRoot, self::PROJECT_FILE);
    if (!file_exists($filename) && !is_link($filename)) { return new self(null); }

    try {
      if (!is_file($filename) || !is_readable($filename)) {
        throw new InvalidArgumentException('The declaration must be a readable PHP file.');
      }
      $declaration = (static fn(): mixed => require $filename)();
      if (!is_array($declaration) || array_diff(array_keys($declaration), ['secondary_action']) !== []) {
        throw new InvalidArgumentException('Return a keyed declaration containing only secondary_action.');
      }
      $handlerClass = $declaration['secondary_action'] ?? null;
      if ($handlerClass === null) { return new self(null); }
      if (!is_string($handlerClass) || !class_exists($handlerClass) || !is_subclass_of($handlerClass, ActionInterface::class)) {
        throw new InvalidArgumentException('The secondary handler must exist and implement ActionInterface.');
      }
      $reflection = new ReflectionClass($handlerClass);
      if (!$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
        throw new InvalidArgumentException('The secondary handler must be instantiable without required constructor arguments.');
      }
      return new self($handlerClass);
    } catch (Throwable $exception) {
      throw new RuntimeException(sprintf('Field actions %s could not be loaded: %s',
        $filename, $exception->getMessage()), previous: $exception);
    }
  }

  /** Returns whether a project handler ran, not whether its gameplay action succeeded. */
  public function executeSecondaryAction(FieldActionContext $context): bool
  {
    $handlerClass = $this->secondaryHandler;
    if ($handlerClass === null) { return false; }
    $context->scene->fieldState?->cancelWalking();
    // Consume before invoking: a scene or modal opened by Game must not accept this edge.
    InputManager::consumeCurrentInput();
    (new $handlerClass())->execute($context);
    return true;
  }
}
