<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Storybook;

use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** Exact narration with independently optional artwork; no image metadata is persisted. */
final readonly class StorybookPage
{
    public const array FIELDS = ['id', 'text', 'picture'];

    /** @param list<string> $diagnostics */
    private function __construct(
        public string $id,
        public string $text,
        public ?string $picture,
        public array $diagnostics,
    ) {}

    public static function fromArray(array $source, string $context = 'Storybook page'): self
    {
        if (array_diff(array_keys($source), self::FIELDS) !== []) {
            throw new InvalidArgumentException("{$context} accepts id, text and optional picture.");
        }
        $id = $source['id'] ?? null;
        $text = $source['text'] ?? null;
        if (!is_string($id) || preg_match(StorybookDefinition::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException("{$context} requires a stable lowercase id.");
        }
        if (!is_string($text) || trim($text) === '' || str_contains($text, "\0") || preg_match('//u', $text) !== 1) {
            throw new InvalidArgumentException("{$context} requires nonempty UTF-8 narration.");
        }
        $picture = $source['picture'] ?? null;
        $diagnostics = [];
        if ($picture !== null) {
            try {
                if (!is_string($picture)) {
                    throw new InvalidArgumentException('Picture must be an asset-relative PNG path.');
                }
                SpriteValidation::validateAssetPath($picture);
                if (strtolower(pathinfo($picture, PATHINFO_EXTENSION)) !== 'png') {
                    throw new InvalidArgumentException('Picture must name a PNG asset.');
                }
            } catch (InvalidArgumentException $error) {
                $diagnostics[] = "{$context} picture unavailable: " . $error->getMessage();
                $picture = null;
            }
        }
        return new self($id, $text, $picture, $diagnostics);
    }

    /**
     * Current header/bounds facts only; native image preparation remains the decode authority.
     * Missing/invalid files use the shared diagnostic and remain a readable text page.
     * @return array{width: int, height: int}|null
     */
    public function getPictureSize(string $assetRoot): ?array
    {
        return $this->picture === null ? null : PngAssetPreflight::getAvailableSize($assetRoot, $this->picture);
    }
}
