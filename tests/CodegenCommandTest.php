<?php

declare(strict_types=1);

/**
 * What `tinvar codegen` removes, where it writes and what it says when it
 * cannot (ADR 0222): it removes only a file it wrote, anywhere, whatever the
 * platform's separator; it refuses an --out that names no directory or the
 * root; and a file it could not write fails the run instead of reading as
 * written.
 */

namespace Tinvar\Tests\Codegen\CodegenCommand;

use Tinvar\Codegen\CodegenCommand;
use Tinvar\Support\PrivateDirectory;

/**
 * @param list<string> $arguments
 * @return array{int, string} the exit status and what was printed
 */
function runCodegen(string $out, array $arguments = []): array
{
    ob_start();
    try {
        $status = new CodegenCommand($out)->run($arguments);
    } finally {
        $printed = (string) ob_get_clean();
    }

    return [$status, $printed];
}

function removeTree(string $directory): void
{
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        \assert($file instanceof \SplFileInfo);
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    PrivateDirectory::remove($directory);
}

it('sorts what is stale by the path under the output directory, with either separator', function () {
    $out = 'C:\app\resources\types';
    $found = [
        'C:\app\resources\types\actions\Gone.ts',
        'C:\app\resources\types\actions\Kept.ts',
        'C:\app\resources\types/actions\Hand.ts',
        'C:\app\resources\types\testing\fakes.ts',
        'C:\app\resources\types\testing\helpers.ts',
        'C:\app\resources\types\testing\queries\gone.ts',
    ];
    $written = ['actions/Kept.ts' => '', 'testing/fakes.ts' => ''];
    $ours = static fn (string $file): bool => str_ends_with(strtolower($file), 'gone.ts');

    expect(CodegenCommand::stale($out, $found, $written, $ours))->toBe([
        'remove' => ['C:\app\resources\types\actions\Gone.ts', 'C:\app\resources\types\testing\queries\gone.ts'],
        'leave' => ['C:\app\resources\types/actions\Hand.ts', 'C:\app\resources\types\testing\helpers.ts'],
    ]);
});

it('names a file by its path under the output directory with forward slashes', function () {
    expect(CodegenCommand::relative('C:\app\types\\', 'C:\app\types\testing\queries\x.ts'))->toBe('testing/queries/x.ts')
        ->and(CodegenCommand::relative('/app/types', '/app/types/actions/X.ts'))->toBe('actions/X.ts');
});

it('leaves an actions module codegen did not write, and names it', function () {
    $out = PrivateDirectory::create('tinvar-codegen');
    mkdir("{$out}/actions");
    file_put_contents("{$out}/actions/Hand.ts", "export const mine = 1;\n");
    file_put_contents("{$out}/actions/Gone.ts", CodegenCommand::HEADER . "\nexport {};\n");

    try {
        [$status, $printed] = runCodegen($out);
        $hand = file_get_contents("{$out}/actions/Hand.ts");
        $gone = file_exists("{$out}/actions/Gone.ts");
    } finally {
        removeTree($out);
    }

    expect($status)->toBe(0)
        ->and($hand)->toBe("export const mine = 1;\n")
        ->and($gone)->toBeFalse()
        ->and($printed)->toContain("removed {$out}/actions/Gone.ts")
        ->and($printed)->not->toContain('Hand.ts');
});

it('refuses an --out that names no directory, or the root', function (string $argument) {
    [$status, $printed] = runCodegen(sys_get_temp_dir() . '/tinvar-never-written', [$argument]);

    expect($status)->toBe(64)
        ->and($printed)->toBe('');
})->with(['--out=', '--out=/', '--out=//', '--out=\\', '--out=C:\\', '--out=c:/']);

it('fails, and does not say wrote, when a file cannot be written', function () {
    $out = PrivateDirectory::create('tinvar-codegen');
    // A directory where generated.ts goes: nothing can write it.
    mkdir("{$out}/generated.ts");

    try {
        [$status, $printed] = runCodegen($out);
    } finally {
        removeTree($out);
    }

    expect($status)->toBe(1)
        ->and($printed)->not->toContain('generated.ts');
});

it('fails when a directory for a file cannot be made', function () {
    $out = PrivateDirectory::create('tinvar-codegen');
    // A file where the testing directory goes.
    file_put_contents("{$out}/testing", '');

    try {
        [$status, $printed] = runCodegen($out);
    } finally {
        removeTree($out);
    }

    expect($status)->toBe(1)
        ->and($printed)->not->toContain('wrote ' . "{$out}/testing/");
});
