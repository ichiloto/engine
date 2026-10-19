<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use InvalidArgumentException;

/** Authored map scope and physical parent entry. No duplicated marker coordinates. */
final readonly class MapAtlasDefinition
{
    public const string DATA_KEY = 'atlas';
    public const int MAX_PARENT_DEPTH = 32;
    public const array SCOPES = ['local','global'];
    public const array TERMINAL_VIEWS = ['places','floorplan'];

    private function __construct(public bool $global, public ?string $parentMap, public ?string $parentLandmark,
        public string $terminalView, public array $terrain) {}

    public static function readMap(array $map): self
    {
        $data=$map[self::DATA_KEY] ?? [];
        if (!is_array($data) || array_diff(array_keys($data),['scope','parent','terminalView','terrain'])!==[]
            || !in_array($data['scope'] ?? 'local',self::SCOPES,true)
            || (array_key_exists('terminalView',$data) && !in_array($data['terminalView'],self::TERMINAL_VIEWS,true))) {
            throw new InvalidArgumentException('Map atlas accepts local/global scope, an optional parent entry, Terminal view places/floorplan and terrain bindings.');
        }
        $global=($data['scope'] ?? 'local')==='global';
        $terminalView=$data['terminalView'] ?? ($global ? 'places' : 'floorplan');
        $terrain=MapAtlasTerrain::validateDeclaration(array_key_exists('terrain',$data) ? $data['terrain'] : []);
        $parent=$data['parent'] ?? null;
        if ($parent===null) { return new self($global,null,null,$terminalView,$terrain); }
        if ($global || !is_array($parent) || count($parent)!==2 || array_diff(array_keys($parent),['map','landmark'])!==[]
            || !is_string($parent['map'] ?? null) || preg_match('~\A[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\z~D',$parent['map'])!==1
            || !is_string($parent['landmark'] ?? null) || preg_match(MapLandmarkCatalog::ID_PATTERN,$parent['landmark'])!==1) {
            throw new InvalidArgumentException('A local atlas parent requires a safe map id and stable landmark id; global maps have no parent.');
        }
        return new self(false,$parent['map'],$parent['landmark'],$terminalView,$terrain);
    }

    /** Resolver reads current source; callers may supply unsaved Editor models. Returns the entry ON the global map. */
    public static function resolveGlobal(string $mapId, \Closure $readMap, ?MapCategoryCatalog $categories = null): ?array
    {
        $seen=[]; $entry=null;
        for ($depth=0;$depth<self::MAX_PARENT_DEPTH;$depth++) {
            if (isset($seen[$mapId])) { throw new InvalidArgumentException('Atlas parent references contain a cycle.'); }
            $seen[$mapId]=true;
            $source=$readMap($mapId);
            $definition=self::readMap($source['data']);
            if ($definition->global) { return ['map'=>$mapId,'landmark'=>$entry]; }
            if ($definition->parentMap===null) { return null; }
            $parent=$readMap($definition->parentMap);
            $catalog=MapLandmarkCatalog::readMap($parent['data'],$parent['tiles'],$categories);
            if (!array_any($catalog->landmarks,static fn(MapLandmarkDefinition $item)=>$item->id===$definition->parentLandmark)) {
                throw new InvalidArgumentException('Atlas parent landmark does not exist on its referenced map.');
            }
            $entry=$definition->parentLandmark;
            $mapId=$definition->parentMap;
        }
        throw new InvalidArgumentException('Atlas parent references exceed 32 maps.');
    }

    /** Enumerate explicit global declarations for browsing; filenames identify resources, never map scope. */
    public static function readGlobalMaps(string $mapsDirectory): array
    {
        if (!is_dir($mapsDirectory)) { return []; }
        $root=realpath($mapsDirectory); $maps=[];
        $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(),'.data.php')) { continue; }
            $relative=substr($file->getPath(),strlen($root)+1);
            $id=str_replace(DIRECTORY_SEPARATOR,'/',$relative);
            if ($id==='') { continue; }
            try {
                $source=MapSourceReader::readEventSource(MapSourceReader::resolvePaths($root,$id));
                if (self::readMap($source['data'])->global) { $maps[$id]=['map'=>$id,'landmark'=>null]; }
            } catch (\Throwable $error) { \Ichiloto\Engine\Util\Debug::warn('Atlas source '.$id.': '.$error->getMessage()); }
        }
        ksort($maps);
        return array_values($maps);
    }
}
