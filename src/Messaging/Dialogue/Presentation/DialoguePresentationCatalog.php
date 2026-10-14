<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use InvalidArgumentException;
use RuntimeException;

final readonly class DialoguePresentationCatalog
{
    public const string FILE = 'Data/Presentation/dialogue.php';
    public function __construct(public array $actors = [], public ?MenuPresentationCatalog $theme = null,
        public array $speakers = [], public array $skits = [], public SkitStageStyle $skitStage = new SkitStageStyle(),
        public array $resources = [])
    {
        if (array_intersect_key($actors, $resources) !== []) {
            throw new InvalidArgumentException('Dialogue actor and artwork resource identities must be distinct.');
        }
        self::validateArtworkRecords($actors);
        self::validateArtworkRecords($resources);
        foreach ($speakers as $name => $identity) {
            if (!is_string($name) || !is_string($identity) || (!isset($actors[$identity]) && !isset($resources[$identity]))) {
                throw new InvalidArgumentException('Authored speaker bindings must reference a dialogue actor or artwork resource.');
            }
        }
        foreach ($skits as $id => $record) {
            if (!is_string($id) || !is_array($record)) { throw new InvalidArgumentException('Skit presentation requires stable IDs.'); }
            if (isset($record['background'])) { SpriteValidation::validateAssetPath($record['background']); }
        }
    }

    private static function validateArtworkRecords(array $records): void
    {
        foreach ($records as $id => $actor) {
            if (!is_string($id) || $id === '' || !is_array($actor)) {
                throw new InvalidArgumentException('Dialogue artwork requires stable IDs and presentation records.');
            }
            foreach (['portrait', 'bust'] as $role) {
                if (isset($actor[$role])) { SpriteValidation::validateAssetPath($actor[$role]); }
            }
            foreach ($actor['emotions'] ?? [] as $emotion => $roles) {
                if (is_string($roles)) { SpriteValidation::validateAssetPath($roles); continue; }
                if (!is_string($emotion) || !is_array($roles)) {
                    throw new InvalidArgumentException('Dialogue emotions require named artwork roles.');
                }
                foreach (['portrait', 'bust'] as $role) {
                    if (isset($roles[$role])) { SpriteValidation::validateAssetPath($roles[$role]); }
                }
            }
        }
    }

    public static function load(string $assetRoot): self
    {
        $file = $assetRoot . '/' . self::FILE;
        if (!file_exists($file) && !is_link($file)) { return new self(); }
        $root = realpath($assetRoot);
        $path = realpath($file);
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
            || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Dialogue catalogue must be a readable file inside assets.');
        }
        $data = (static fn(string $file): mixed => require $file)($path);
        if ($data instanceof self) { return $data; }
        if ($data === 1 || $data === null || $data === []) { return new self(); }
        if (!is_array($data) || ($data['schema'] ?? null) !== 'ichiloto.dialogue/1'
            || array_diff(array_keys($data), ['schema', 'theme', 'actors', 'resources', 'speakers', 'skits', 'skitStage']) !== []) {
            throw new RuntimeException('Dialogue catalogue requires schema ichiloto.dialogue/1.');
        }
        return new self($data['actors'] ?? [],
            isset($data['theme']) ? new MenuPresentationCatalog($root, $data['theme']) : null,
            $data['speakers'] ?? [], $data['skits'] ?? [], SkitStageStyle::getFromArray($data['skitStage'] ?? []),
            $data['resources'] ?? []);
    }

    /** Explicit aliases bridge authored display labels, never inferred directory names. */
    public function resolveSpeakerId(?string $actorId, string $speaker): ?string
    {
        return $actorId ?? $this->speakers[$speaker] ?? null;
    }

    public function getArtwork(?string $identity, string $emotion, string $role): ?string
    {
        if ($identity === null || $this->theme === null) { return null; }
        $actor = $this->actors[$identity] ?? $this->resources[$identity] ?? [];
        $expression = $actor['emotions'][$emotion] ?? [];
        $neutral = $actor['emotions']['Neutral'] ?? [];
        if (is_string($expression)) { $expression = ['portrait' => $expression]; }
        if (is_string($neutral)) { $neutral = ['portrait' => $neutral]; }
        foreach (array_unique(array_filter([$expression[$role] ?? null, $neutral[$role] ?? null, $actor[$role] ?? null])) as $asset) {
            if (PngAssetPreflight::getAvailableSize($this->theme->assetRoot, $asset) !== null) { return $asset; }
        }
        return null;
    }
}
