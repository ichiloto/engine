<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Localization\LocalizedText;
use InvalidArgumentException;

/** Semantic place categories. Theme icon roles are map.category.<stable-id>. */
final readonly class MapCategoryCatalog
{
    public const string FILE = 'Data/map-categories.php';
    public const string SCHEMA = 'ichiloto.map-categories/1';
    public const int MAX_CATEGORIES = 128;
    public array $categories;

    public function __construct(array $categories = [])
    {
        if (count($categories)>self::MAX_CATEGORIES) { throw new InvalidArgumentException('Map categories exceed 128 records.'); }
        $copy=[];
        foreach ($categories as $id=>$entry) {
            if (!is_string($id) || preg_match(MapLandmarkCatalog::ID_PATTERN,$id)!==1
                || MapLandmarkCategory::tryFrom($id)!==null || !is_array($entry)
                || array_diff(array_keys($entry),['name','base'])!==[]
                || !is_string($entry['base'] ?? null) || MapLandmarkCategory::tryFrom($entry['base'])===null) {
                throw new InvalidArgumentException('Project map categories require a unique non-generic stable id, localized name and generic base.');
            }
            LocalizedText::fromArray($entry['name'] ?? null);
            $copy[$id]=$entry;
        }
        $this->categories=$copy;
    }

    public static function load(string $assetRoot): self
    {
        $file=$assetRoot.'/'.self::FILE;
        if (!file_exists($file) && !is_link($file)) { return new self(); }
        $root=realpath($assetRoot); $path=realpath($file);
        if ($root===false || $path===false || !str_starts_with($path,$root.DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new InvalidArgumentException('Map category catalog must be a readable file inside assets.');
        }
        $data=(static fn(string $path): mixed=>require $path)($path);
        if (!is_array($data)) { throw new InvalidArgumentException('Map category catalog must return keyed category records.'); }
        return self::fromArray($data);
    }

    public static function fromArray(mixed $data): self
    {
        if (!is_array($data) || ($data['schema'] ?? null)!==self::SCHEMA
            || array_diff(array_keys($data),['schema','categories'])!==[] || !is_array($data['categories'] ?? null)) {
            throw new InvalidArgumentException('Map categories require schema ichiloto.map-categories/1 and a categories dictionary.');
        }
        return new self($data['categories']);
    }

    public function toArray(): array { return ['schema'=>self::SCHEMA,'categories'=>$this->categories]; }

    public static function getNameMessageKey(string $id): string
    {
        if (preg_match(MapLandmarkCatalog::ID_PATTERN,$id)!==1) { throw new InvalidArgumentException('Invalid map category id.'); }
        return 'map_categories.'.$id.'.name';
    }

    public static function getIconRole(string $id): string
    {
        if (preg_match(MapLandmarkCatalog::ID_PATTERN,$id)!==1) { throw new InvalidArgumentException('Invalid map category id.'); }
        return 'map.category.'.$id;
    }

    public function getName(string $id): string
    {
        $this->getBase($id);
        return isset($this->categories[$id]) ? LocalizedText::fromArray($this->categories[$id]['name'])->getText()
            : \Ichiloto\Engine\Localization\MessageCatalog::getTerm('map.category.'.$id);
    }

    public function getNameDefinition(string $id): ?LocalizedText
    {
        $this->getBase($id);
        return isset($this->categories[$id]) ? LocalizedText::fromArray($this->categories[$id]['name']) : null;
    }

    public function getIds(): array { return [...array_column(MapLandmarkCategory::cases(),'value'),...array_keys($this->categories)]; }

    public function getBase(string $id): MapLandmarkCategory
    {
        return MapLandmarkCategory::tryFrom($id) ?? (isset($this->categories[$id])
            ? MapLandmarkCategory::from($this->categories[$id]['base'])
            : throw new InvalidArgumentException("Unknown map category '{$id}'."));
    }
}
