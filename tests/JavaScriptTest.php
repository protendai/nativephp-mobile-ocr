<?php

beforeEach(function () {
    $this->root = dirname(__DIR__);
    $this->js = file_get_contents($this->root.'/resources/js/index.js');
    $this->dts = file_get_contents($this->root.'/resources/js/index.d.ts');
    $this->manifest = json_decode(file_get_contents($this->root.'/nativephp.json'), true);
});

it('exports one function per bridge function, calling it by its manifest name', function () {
    foreach ($this->manifest['bridge_functions'] as $function) {
        $name = lcfirst(explode('.', $function['name'])[1]);

        expect($this->js)->toContain("export async function {$name}(")
            ->toContain("bridgeCall('{$function['name']}'")
            ->and($this->dts)->toContain("export declare function {$name}(");
    }
});

it('exports the event class names the manifest declares', function () {
    foreach ($this->manifest['events'] as $event) {
        $short = class_basename($event);

        expect($this->js)->toContain("{$short}: '".str_replace('\\', '\\\\', $event)."'");
    }
});

it('uses the NativePHP bridge endpoint and native-event channel', function () {
    expect($this->js)->toContain("const baseUrl = '/_native/api/call';")
        ->toContain("document.addEventListener('native-event'");
});

it('ships a package.json pointing at the library and its types', function () {
    $package = json_decode(file_get_contents($this->root.'/package.json'), true);

    expect($package['name'])->toBe('@protendai/nativephp-mobile-ocr')
        ->and($package['private'])->toBeFalse()
        ->and($package['license'])->toBe('MIT')
        ->and($package['version'])->toBe($this->manifest['version'])
        ->and($package['main'])->toBe('resources/js/index.js')
        ->and($package['types'])->toBe('resources/js/index.d.ts');
});
