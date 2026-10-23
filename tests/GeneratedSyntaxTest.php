<?php

declare(strict_types=1);

namespace Tinvar\Tests\Codegen\GeneratedSyntax;

/**
 * Names written in PHP attributes reach the generated TypeScript as string
 * literals, comments, identifiers and module names. Each must stay what it
 * was placed as: a name that closes its quote or its comment otherwise
 * writes declarations of its own into every consumer's types.
 *
 * Registration may refuse such names first; the generator does not rely on
 * it, so these build what it is handed directly where they can.
 */

use Tinvar\Actions\CompiledAction;
use Tinvar\Attributes\Query;
use Tinvar\Codegen\CodegenException;
use Tinvar\Codegen\RouteTypes;
use Tinvar\Codegen\TypeScriptGenerator;
use Tinvar\Routing\RouteKind;

#[Query(name: 'racks.unitLoad */ export declare const injectedByQueryName: 1; /** ')]
final class QueryNameClosingItsComment
{
    public function __invoke(): string
    {
        return '';
    }
}

#[Query(name: "racks/x' {} declare global { var injectedByQueryModule: string } declare module 'y.f")]
final class QueryNameLeavingItsModule
{
    public function __invoke(): string
    {
        return '';
    }
}

#[Query(name: 'q.batch', args: ["unit: string }): Promise<unknown>; } declare global { var injectedByQueryArg: string } declare module 'z' { export function w(args: { a" => \stdClass::class])]
final class QueryArgumentLeavingItsObject
{
    public function __invoke(): string
    {
        return '';
    }
}

#[Query(name: 'racks.delete')]
final class QueryNamedForAReservedWord
{
    public function __invoke(): string
    {
        return '';
    }
}

#[Query(name: "racks.unit\u{2028}Load")]
final class QueryNameWithALineTerminator
{
    public function __invoke(): string
    {
        return '';
    }
}

/** Both files, one after the other: what is asserted here is what text reaches either. */
function emit(array $queries = [], array $routes = [], array $actions = []): string
{
    return implode("\n", (new TypeScriptGenerator())->files([], $queries, $routes, $actions));
}

function page(string $view, ?string $name = null): RouteTypes
{
    return new RouteTypes(RouteKind::Page, $view, $name, $view, [], []);
}

function action(string $name, string $class = 'App\Tools', string $method = 'ping'): CompiledAction
{
    return new CompiledAction($name, $class, $method, [], [], [], [], [], false);
}

/** What the crafted names below were meant to declare, as code. */
const INJECTED = "/^declare global|^export interface (?:Pwn|Q|L) |declare module 'react'/m";

describe('a name in a string literal', function () {
    it('cannot close the quote', function (RouteTypes $route, string $literal) {
        $ts = emit(routes: [$route]);

        expect($ts)->toContain($literal)
            ->not->toMatch(INJECTED);
    })->with([
        'a page view' => [
            page("x': null; }\ndeclare global { interface Window { injectedByView: string } }\nexport interface Pwn { 'y"),
            "'x\\': null; }\\u000adeclare global { interface Window { injectedByView: string } }\\u000aexport interface Pwn { \\'y': null;",
        ],
        'a route name' => [
            page('sites/rack', "n': {}; }\ndeclare module 'react' { export const injectedByName: 1 }\nexport interface Q { 'z"),
            "'n\\': {}; }\\u000adeclare module \\'react\\' { export const injectedByName: 1 }\\u000aexport interface Q { \\'z': Record<string, never>;",
        ],
        'a layout key' => [
            new RouteTypes(RouteKind::Layout, 'k', null, "k': null; }\ndeclare global { var injectedByLayout: number }\nexport interface L { 'm", [], []),
            "'k\\': null; }\\u000adeclare global { var injectedByLayout: number }\\u000aexport interface L { \\'m': null;",
        ],
    ]);

    // A backslash ending the name escaped the closing quote instead.
    it('escapes a backslash, and a line terminator a string cannot hold', function () {
        expect(emit(routes: [page("a\\"), page("b\u{2028}c\u{2029}d\re")]))
            ->toContain("  'a\\\\': null;\n")
            ->toContain("  'b\\u2028c\\u2029d\\u000de': null;\n");
    });

    it('refuses a name that is not UTF-8', function () {
        expect(fn () => emit(routes: [page("x\xff")]))->toThrow(CodegenException::class, 'UTF-8');
    });

    it('quotes a query argument that is not an identifier, rather than writing it as code', function () {
        $ts = emit(queries: [QueryArgumentLeavingItsObject::class]);

        expect($ts)
            ->toContain("export function batch(args: { 'unit: string }): Promise<unknown>; } declare global { var injectedByQueryArg: string } declare module \\'z\\' { export function w(args: { a': string }): Promise<string>;")
            ->not->toContain("declare module 'z'");
    });
});

describe('a name in a comment', function () {
    it('cannot close the comment', function () {
        $ts = emit(actions: [action("Tools.ping */ } declare global { var injectedByActionName: string } declare module '@actions/Tools2' { /**")]);

        expect($ts)
            ->toContain("  /** Posts to the PHP action `Tools.ping *\\/ } declare global { var injectedByActionName: string } declare module '@actions/Tools2' { /**`. */\n  export function ping(")
            ->not->toContain('Tools.ping */');
    });

    it('stays on its line', function () {
        expect(emit(actions: [action("Tools.ping\n*/ declare global {}")]))
            ->toContain('/** Posts to the PHP action `Tools.ping *\\/ declare global {}`. */');
    });
});

describe('a name written as an identifier', function () {
    it('refuses a query whose function name is not one', function (string $class, string $message) {
        expect(fn () => emit(queries: [$class]))->toThrow(CodegenException::class, $message);
    })->with([
        'code after the name' => [QueryNameClosingItsComment::class, 'not a TypeScript identifier'],
        'a reserved word' => [QueryNamedForAReservedWord::class, 'reserved word'],
        'a line terminator' => [QueryNameWithALineTerminator::class, 'not a TypeScript identifier'],
    ]);

    it('refuses an action whose method is a reserved word', function () {
        expect(fn () => emit(actions: [action('Tools.delete', method: 'delete')]))
            ->toThrow(CodegenException::class, 'reserved word');
    });

    it('refuses an action whose method is not an identifier', function () {
        expect(fn () => emit(actions: [action('Tools.x', method: 'x(args: {}): void; declare global {} export function y')]))
            ->toThrow(CodegenException::class, 'not a TypeScript identifier');
    });
});

describe('a module name', function () {
    it('refuses a query prefix that is not a module path', function () {
        expect(fn () => emit(queries: [QueryNameLeavingItsModule::class]))
            ->toThrow(CodegenException::class, 'not a module name');
    });

    it('refuses an action class that is not a module path', function () {
        expect(fn () => emit(actions: [action('Tools.ping', class: "App\\Tools' {} declare global {} declare module 'x")]))
            ->toThrow(CodegenException::class, 'not a module name');
    });
});
