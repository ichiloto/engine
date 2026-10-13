<?php

namespace Ichiloto\Engine\Scenes\Game;

use Assegai\Util\Path;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;
use Throwable;

/** Owned by one Game, never a process-global project declaration. */
final class SavedGameResumeRegistry
{
    public const string PROJECT_FILE = 'Data/saved-game-resume.php';

    /** @var class-string<SavedGameResumeHandlerInterface>|null */
    public private(set) ?string $handlerClass = null;
    public private(set) int $revision = 0;

    public function configureFromProject(?string $assetRoot = null): void
    {
        $this->configure(null);
        $assetRoot ??= Path::join(Path::getCurrentWorkingDirectory(), 'assets');
        $filename = Path::join($assetRoot, self::PROJECT_FILE);
        if (!file_exists($filename) && !is_link($filename)) { return; }

        try {
            if (!is_file($filename) || !is_readable($filename)) {
                throw new InvalidArgumentException('The declaration must be a readable PHP file.');
            }
            $declaration = (static fn(): mixed => require $filename)();
            if (!is_string($declaration)) {
                throw new InvalidArgumentException('Return a saved-game resume handler class string.');
            }
            $this->configure($declaration);
        } catch (Throwable $error) {
            throw new RuntimeException(sprintf('Saved-game resume %s could not be loaded: %s',
                $filename, $error->getMessage()), previous: $error);
        }
    }

    /** Validate the project-supplied string before retaining a handler class. */
    public function configure(?string $handlerClass): void
    {
        $this->handlerClass = null;
        $this->revision++;
        if ($handlerClass === null) { return; }
        if (!class_exists($handlerClass) || !is_subclass_of($handlerClass, SavedGameResumeHandlerInterface::class)) {
            throw new InvalidArgumentException('The handler must exist and implement SavedGameResumeHandlerInterface.');
        }
        $reflection = new ReflectionClass($handlerClass);
        if (!$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
            throw new InvalidArgumentException('The handler must be instantiable without required constructor arguments.');
        }
        $this->handlerClass = $handlerClass;
    }
}
