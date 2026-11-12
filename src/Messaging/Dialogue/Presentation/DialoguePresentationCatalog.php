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
    public const string SCHEMA = 'ichiloto.dialogue/1';
    /** The keys the catalogue holds. A skit's backdrop belongs to the place it plays in, never to this file. */
    public const array KEYS = ['schema', 'theme', 'actors', 'resources', 'speakers', 'skitStage'];
    /** The key format 3 kept skit backgrounds under; format 4 moved them to {@see \Ichiloto\Engine\Field\SkitBackdrop}. */
    public const string RETIRED_SKITS_KEY = 'skits';

    public function __construct(public array $actors = [], public ?MenuPresentationCatalog $theme = null,
        public array $speakers = [], public SkitStageStyle $skitStage = new SkitStageStyle(),
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
        if (is_array($data) && array_key_exists(self::RETIRED_SKITS_KEY, $data)) {
            throw new RuntimeException('The dialogue catalogue\'s skits key is retired: a skit\'s backdrop now follows the map, '
                . 'its region or the skit itself. Run `ichiloto upgrade` to move each background to its skit.');
        }
        if (!is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA || array_diff(array_keys($data), self::KEYS) !== []) {
            throw new RuntimeException('Dialogue catalogue requires schema ichiloto.dialogue/1.');
        }
        return new self($data['actors'] ?? [],
            isset($data['theme']) ? new MenuPresentationCatalog($root, $data['theme']) : null,
            $data['speakers'] ?? [], SkitStageStyle::getFromArray($data['skitStage'] ?? []),
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
