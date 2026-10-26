<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;

/** Authored classifications of existing cells. Contains no second map grid or collision authority. */
final readonly class MapAtlasTerrain
{
    public const array ROLES = ['ground','road','bridge','water','vegetation','highland','obstacle','building','boundary'];
    public const int MAX_SOURCE_CELLS = 16 * 1024 * 1024;
    private const array SYMBOLS = ['ground'=>' ', 'road'=>':', 'bridge'=>'=', 'water'=>'~',
        'vegetation'=>'*', 'highland'=>'^', 'obstacle'=>'x', 'building'=>'#', 'boundary'=>'+'];
    private const array PRIORITY = [' '=>0,'*'=>1,'^'=>2,'x'=>2,'~'=>3,'#'=>4,'+'=>5,'-'=>5,'|'=>5,':'=>6,'='=>7];

    private function __construct(private array $bindings) {}

    public static function validateDeclaration(mixed $declaration): array
    {
        if (!is_array($declaration) || count($declaration)>100) {
            throw new InvalidArgumentException('Atlas terrain requires at most 100 named source-layer bindings.');
        }
        $parsed=[];
        foreach ($declaration as $layer=>$binding) {
            if (!is_string($layer) || preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/D',$layer)!==1
                || !is_array($binding) || array_diff(array_keys($binding),['symbols','default'])!==[]
                || $binding===[]) {
                throw new InvalidArgumentException('Atlas terrain binds a source layer to symbols and/or a default role.');
            }
            if (array_key_exists('default',$binding) && !in_array($binding['default'],self::ROLES,true)) {
                throw new InvalidArgumentException('Atlas terrain default must be a known terrain role.');
            }
            $symbols=$binding['symbols'] ?? [];
            if (!is_array($symbols) || count($symbols)>256 || (array_key_exists('symbols',$binding) && $binding['symbols']===null)) {
                throw new InvalidArgumentException('Atlas terrain symbols require at most 256 glyph-to-role bindings.');
            }
            $cleanSymbols=[];
            foreach ($symbols as $symbol=>$role) {
                $symbol=(string)$symbol;
                if (TerminalText::visibleSymbols($symbol)!==[$symbol] || TerminalText::stripAnsi($symbol)!==$symbol
                    || preg_match('/[\x00-\x1f\x7f]/u',$symbol) || !in_array($role,self::ROLES,true)) {
                    throw new InvalidArgumentException('Atlas terrain symbols require one plain glyph and a known terrain role.');
                }
                $cleanSymbols[$symbol]=$role;
            }
            $parsed[$layer]=[];
            if (array_key_exists('default',$binding)) { $parsed[$layer]['default']=$binding['default']; }
            if (array_key_exists('symbols',$binding)) { $parsed[$layer]['symbols']=$cleanSymbols; }
        }
        return $parsed;
    }

    public static function readMap(array $map, MapLayerSet $layers): ?self
    {
        $declaration=MapAtlasDefinition::readMap($map)->terrain;
        if ($declaration===[]) { return null; }
        $known=array_column($layers->layers,null,'name');
        foreach (array_keys($declaration) as $name) {
            if (!isset($known[$name])) { throw new InvalidArgumentException('Atlas terrain references missing source layer '.$name.'.'); }
        }
        $bindings=[];
        foreach ($layers->layers as $layer) {
            if (isset($declaration[$layer->name])) { $bindings[]=[$layer,$declaration[$layer->name]]; }
        }
        return new self($bindings);
    }

    public function getRole(int $x, int $y): ?string
    {
        $role=null;
        foreach ($this->bindings as [$layer,$binding]) {
            $glyph=$layer->glyphs[$y][$x] ?? null;
            if ($glyph===null) { continue; }
            $next=$binding['symbols'][$glyph] ?? ($glyph!==' ' ? ($binding['default'] ?? null) : null);
            if ($next!==null) { $role=$next; }
        }
        return $role;
    }

    public function getRoles(): array
    {
        $roles=[];
        foreach ($this->bindings as [, $binding]) {
            if (isset($binding['default'])) { $roles[]=$binding['default']; }
            array_push($roles,...array_values($binding['symbols'] ?? []));
        }
        return array_values(array_unique($roles));
    }

    public function getSymbol(int $x, int $y): ?string
    {
        $role=$this->getRole($x,$y);
        if ($role===null) { return null; }
        if ($role!=='boundary') { return self::SYMBOLS[$role]; }
        $horizontal=$this->getRole($x,$y-1)!=='boundary' || $this->getRole($x,$y+1)!=='boundary';
        $vertical=$this->getRole($x-1,$y)!=='boundary' || $this->getRole($x+1,$y)!=='boundary';
        return $horizontal && $vertical ? '+' : ($horizontal ? '-' : ($vertical ? '|' : ' '));
    }

    /** Preserve narrow roads/bridges through exact source-cell aggregation, including extreme reductions. */
    public function getRegionSymbol(int $left, int $top, int $right, int $bottom): ?string
    {
        if ($right<=$left || $bottom<=$top || ($right-$left)*($bottom-$top)>self::MAX_SOURCE_CELLS) {
            throw new InvalidArgumentException('Atlas terrain source regions must be positive and within 16 Mi cells.');
        }
        $best=null; $priority=-1;
        for ($y=$top;$y<$bottom;$y++) {
            for ($x=$left;$x<$right;$x++) {
                $symbol=$this->getSymbol($x,$y);
                if ($symbol!==null && self::PRIORITY[$symbol]>$priority) { $best=$symbol; $priority=self::PRIORITY[$symbol]; }
            }
        }
        return $best;
    }
}
