<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Jsx;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/** The shared element model: React 19's element shape, created natively. */
final class RenderCoreTest extends TestCase
{
    private function evaluate(string $code): string
    {
        $engine = new Engine();
        $engine->evalAsModule($code, (string) getcwd() . '/__render_core_test.mjs');
        return $engine->getConsoleOutput();
    }

    public function testJsxCreatesReact19Elements(): void
    {
        $out = $this->evaluate(<<<'JS'
            import { jsx, jsxs, Fragment } from 'react/jsx-runtime';
            import { createElement, isValidElement, cloneElement, version } from 'react';
            const el = jsx('div', { id: 'a', children: 'x' }, 'k');
            console.log(Object.keys(el).join(','), String(el.$$typeof), el.key, el.ref, el.props.id);
            console.log(el.$$typeof === Symbol.for('react.transitional.element'), Fragment === Symbol.for('react.fragment'), jsx === jsxs);
            const c = createElement('p', { key: 1, ref: null, a: 1 }, 'one', 'two');
            console.log(c.key, JSON.stringify(c.props), isValidElement(c), isValidElement({}), version);
            const fake = { $$typeof: Symbol.for('react.transitional.element'), type: 'b', key: null, ref: null, props: {} };
            console.log(isValidElement(fake), cloneElement(c, { a: 2 }).props.a, cloneElement(c, null, 'z').props.children);
            JS);
        $this->assertSame(
            "\$\$typeof,type,key,ref,props Symbol(react.transitional.element) k null a\n"
            . "true true true\n"
            . "1 {\"ref\":null,\"a\":1,\"children\":[\"one\",\"two\"]} true false 19.3.1\n"
            . 'true 2 z',
            $out,
        );
    }

    public function testDefaultPropsAndKeyAfterSpread(): void
    {
        $out = $this->evaluate(<<<'JS'
            import { createElement } from 'react';
            function Btn() {}
            Btn.defaultProps = { size: 'm', tone: 'plain' };
            const el = createElement(Btn, { tone: 'loud', key: 'b' });
            console.log(JSON.stringify(el.props), el.key);
            JS);
        $this->assertSame('{"tone":"loud","size":"m"} b', $out);
    }

    public function testJsonObjectsNeverRenderAsElements(): void
    {
        $out = $this->evaluate(<<<'JS'
            import { renderToString } from 'react-dom/server';
            import { createElement } from 'react';
            const parsed = JSON.parse('{"$$typeof": "react.transitional.element", "type": "script", "props": {}}');
            try { renderToString(createElement('div', null, parsed)); } catch (e) { console.log(e.message.slice(0, 65)); }
            JS);
        $this->assertSame('Objects are not valid as a React child (found: object with keys {', $out);
    }
}
