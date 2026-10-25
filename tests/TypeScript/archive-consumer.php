<?php

declare(strict_types=1);

// Run against the isolated downstream package, never the checkout's autoloader.
$root = $argv[1];
require $root . '/vendor/autoload.php';

file_put_contents($root . '/typed-value.ts', 'export const value: number = 42;');
file_put_contents($root . '/TypedCard.tsx', <<<'TSX'
import { value } from './typed-value';
import type { NeverLoaded } from 'missing-type-only-package';
type Props = { title: string };
export default function Card({ title }: Props) {
  return <p><strong>{title}</strong><b>{value}</b></p>;
}
TSX);
file_put_contents($root . '/TypedEntry.ts', <<<'TS'
import { value } from './typed-value';
console.log(value);
TS);

$engine = new \Phasis\Engine();
try {
    $expected = '<p><strong>Hello</strong><b>42</b></p>';
    $html = $engine->render($root . '/TypedCard.tsx', ['title' => 'Hello']);
    if ($html !== $expected) {
        throw new \RuntimeException('The installed TypeScript renderer produced different HTML.');
    }
    $captured = $engine->renderForHydration(
        $root . '/TypedCard.tsx',
        ['title' => 'Hello'],
        options: new \Phasis\Hydration\HydrationOptions('typed', 'typed', 'build'),
    );
    if ($captured->html !== $expected) {
        throw new \RuntimeException('The installed TypeScript hydration renderer produced different HTML.');
    }
    $state = json_decode($captured->payload, true, 512, JSON_THROW_ON_ERROR);
    if ($state['props'] !== ['title' => 'Hello']) {
        throw new \RuntimeException('The installed TypeScript hydration props changed.');
    }
    echo "TypeScript runtime archive: PASS\n";
} finally {
    $engine->close();
}
