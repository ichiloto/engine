<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasColorTransform, CanvasColorTreatment, CanvasComposite, CanvasGroup, CanvasImage, CanvasImageBottomFade, CanvasImageBudgetExceeded, CanvasImagePreflight, CanvasRectangle, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;

beforeEach(function () {
  $this->root = getTestTemporaryDirectory() . '/canvas-colour-budget-' . bin2hex(random_bytes(5));
  mkdir($this->root);
  $this->png = function (string $name, int $width = 100, int $height = 80): void {
    file_put_contents($this->root . '/' . $name, "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . pack('NN', $width, $height));
  };
  $this->invert = new CanvasColorTransform([[-1,0,0],[0,-1,0],[0,0,-1]], [1,1,1]);
  $this->dim = new CanvasColorTransform([[.5,0,0],[0,.5,0],[0,0,.5]], [0,0,0]);
});
afterEach(function () {
  foreach (glob($this->root . '/*') ?: [] as $file) { unlink($file); }
  rmdir($this->root);
});

it('composes treatments in renderer order and normalizes only exact identities and signed zero', function () {
  $effective = CanvasColorTreatment::getEffective(CanvasColorTreatment::getEffective(null,$this->dim),$this->invert);
  expect($effective->matrix)->toBe([[-.5,0.0,0.0],[0.0,-.5,0.0],[0.0,0.0,-.5]])
    ->and($effective->offset)->toBe([.5,.5,.5])
    ->and(CanvasColorTreatment::getEffective(CanvasColorTreatment::getEffective(null,$this->invert),$this->invert))->toBeNull()
    ->and(CanvasColorTreatment::getEffective(null,null))->toBeNull();
  $identity = new CanvasColorTransform([[1,-0.0,0],[0,1,0],[0,0,1]], [-0.0,0,0]);
  expect(CanvasColorTreatment::getEffective(null,$identity))->toBeNull();
  $near = new CanvasColorTransform([[1-1e-13,0,0],[0,1,0],[0,0,1]], [0,0,0]);
  expect(CanvasColorTreatment::getEffective(null,$near))->not->toBeNull();
  $one = new CanvasColorTransform([[.5,-0.0,0],[0,.5,0],[0,0,.5]], [-0.0,0,0]);
  expect(CanvasColorTreatment::getEffective(null,$one)->getCacheKey())->toBe(CanvasColorTreatment::getEffective(null,$this->dim)->getCacheKey());
  $noise = new CanvasColorTransform([[1+5e-13,0,0],[0,1,0],[0,0,1]], [0,0,0]);
  $composed = null;
  for ($i=0;$i<8;$i++) { $composed=CanvasColorTreatment::getEffective($composed,$noise); }
  expect($composed->matrix[0][0])->toBeGreaterThan(1+1e-12); // Valid authored maps are not refused again after composition.

});

it('deduplicates reused crops by effective treatment while keeping untreated and differently treated variants', function () {
  ($this->png)('sheet.png'); symlink($this->root.'/sheet.png',$this->root.'/alias.png');
  $image = new CanvasImage('image','sheet.png',new CanvasRectangle(0,0,10,10),sourceRect:new SpriteSourceRect(1,2,20,30));
  $alias = new CanvasImage('alias','alias.png',new CanvasRectangle(0,0,8,9),sourceRect:new SpriteSourceRect(1,2,20,30));
  $child = new PresentationCanvas(100,80,[$image]);
  $root = new PresentationCanvas(100,80,[$alias],groups:[
    new CanvasGroup('first',0,0,$child,colorTransform:$this->dim),
    new CanvasGroup('second',7,9,$child,colorTransform:$this->dim),
    new CanvasGroup('third',0,0,$child,colorTransform:$this->invert),
  ]);
  $budget=CanvasImagePreflight::inspectCanvases([$root],$this->root);
  expect($budget['sources'])->toHaveCount(1)->and($budget['regions'])->toHaveCount(3)
    ->and(array_sum($budget['regions']))->toBe(3*24*34*4);
});

it('elides a composed identity and preserves noncommuting parent-child costs', function () {
  ($this->png)('sheet.png');
  $child=new PresentationCanvas(100,80,[new CanvasImage('image','sheet.png',new CanvasRectangle(0,0,10,10))]);
  $nested=new PresentationCanvas(100,80,groups:[new CanvasGroup('inner',0,0,$child,colorTransform:$this->invert)]);
  $root=new PresentationCanvas(100,80,groups:[new CanvasGroup('outer',0,0,$nested,colorTransform:$this->invert)]);
  expect(CanvasImagePreflight::inspectCanvases([$root],$this->root)['regions'])->toHaveCount(1);
  $root=new PresentationCanvas(100,80,groups:[new CanvasGroup('outer',0,0,$nested,colorTransform:$this->dim)]);
  $budget=CanvasImagePreflight::inspectCanvases([$root],$this->root);
  expect($budget['regions'])->toHaveCount(2)
    ->and(implode('|',array_keys($budget['regions'])))->toContain(CanvasColorTreatment::getEffective(CanvasColorTreatment::getEffective(null,$this->dim),$this->invert)->getCacheKey());
});

it('charges one combined colour-tone-flip-rotation variant rather than intermediate variants', function () {
  ($this->png)('sheet.png');
  $image=new CanvasImage('image','sheet.png',new CanvasRectangle(30,20,20,30),sourceRect:new SpriteSourceRect(0,0,20,30),
    brightness:.5,flipX:true,rotation:45,pivot:new \Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot(.5,.5));
  $root=new PresentationCanvas(100,80,groups:[new CanvasGroup('group',0,0,new PresentationCanvas(100,80,[$image]),colorTransform:$this->dim)]);
  $budget=CanvasImagePreflight::inspectCanvases([$root],$this->root);
  expect($budget['regions'])->toHaveCount(2)
    ->and(array_sum($budget['regions']))->toBe(24*34*4+CanvasImage::getRotatedRegionBytes(20,30,45));
});

it('charges distinct bottom fades including otherwise untreated and coloured drawings', function () {
  ($this->png)('sheet.png'); $images=[];
  foreach ([.2,.4,.4] as $i=>$depth) {
    $images[]=new CanvasImage('image-'.$i,'sheet.png',new CanvasRectangle(0,0,10,10),bottomFade:new CanvasImageBottomFade([.5,.5],$depth));
  }
  expect(CanvasImagePreflight::inspect($images,$this->root)['regions'])->toHaveCount(3);
  $root=new PresentationCanvas(100,80,$images,groups:[new CanvasGroup('group',0,0,new PresentationCanvas(100,80,$images),colorTransform:$this->dim)]);
  expect(CanvasImagePreflight::inspectCanvases([$root],$this->root)['regions'])->toHaveCount(5);
});

it('charges finished guarded composite variants once by content and treatment independent of placement', function () {
  $one=new CanvasComposite('one',40,30,new CanvasRectangle(0,0,40,30),[]);
  $two=new CanvasComposite('two',40,30,new CanvasRectangle(20,10,20,15),[],opacity:.5);
  $child=new PresentationCanvas(100,80,composites:[$one,$two]);
  $root=new PresentationCanvas(100,80,composites:[$one],groups:[
    new CanvasGroup('first',0,0,$child,colorTransform:$this->dim),
    new CanvasGroup('second',0,0,$child,colorTransform:$this->invert),
  ]);
  $budget=CanvasImagePreflight::inspectCanvases([$root],$this->root);
  expect($budget['sources'])->toBe([])->and($budget['regions'])->toHaveCount(2)
    ->and(array_sum($budget['regions']))->toBe(2*44*34*4);
});

it('rejects treated crops that previously slipped through the prepared-region ceiling', function () {
  ($this->png)('sheet.png',3000,3000);
  $child=new PresentationCanvas(10,10,[new CanvasImage('image','sheet.png',new CanvasRectangle(0,0,10,10))]);
  CanvasImagePreflight::runForSession(static fn(string $cap):bool=>true,function () use ($child):void {
    expect(array_sum(CanvasImagePreflight::inspectCanvases([$child],$this->root)['regions']))->toBe(3004*3004*4);
    $root=new PresentationCanvas(10,10,groups:[new CanvasGroup('treated',0,0,$child,colorTransform:$this->dim)]);
    expect(fn()=>CanvasImagePreflight::inspectCanvases([$root],$this->root))->toThrow(CanvasImageBudgetExceeded::class,'prepared regions');
  });
});

it('shares resource accounting across export roots without imposing per-frame drawing counts', function () {
  ($this->png)('sheet.png');
  $root=new PresentationCanvas(10,10,[new CanvasImage('image','sheet.png',new CanvasRectangle(0,0,10,10))]);
  $roots=array_fill(0,1025,$root);
  expect(CanvasImagePreflight::inspectCanvasResources($roots,$this->root)['regions'])->toHaveCount(1);
  expect(fn()=>CanvasImagePreflight::inspectCanvases($roots,$this->root))->toThrow(InvalidArgumentException::class,'image');
});

it('retains hierarchy with the still renderer optional-asset filter and strict runtime admission', function () {
  ($this->png)('sheet.png');
  $child=new PresentationCanvas(100,80,[new CanvasImage('present','sheet.png',new CanvasRectangle(0,0,10,10)),
    new CanvasImage('missing','missing.png',new CanvasRectangle(0,0,10,10))]);
  $root=new PresentationCanvas(100,80,groups:[new CanvasGroup('treated',0,0,$child,colorTransform:$this->dim)]);
  $budget=CanvasImagePreflight::inspectCanvasResources([$root],$this->root,static fn($drawing):bool=>$drawing->id!=='missing');
  expect($budget['regions'])->toHaveCount(2);
  expect(fn()=>CanvasImagePreflight::inspectCanvases([$root],$this->root))->toThrow(RuntimeException::class);
});
