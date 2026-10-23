import { defineConfig, configDefaults } from 'vitest/config';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
const root = resolve(dirname(fileURLToPath(import.meta.url)), 'upstream/hono-4.13.13');
const project = process.env.PHASIS_HONO_PROJECT || 'main';
const include = project === 'node' ? ['runtime-tests/node/index.test.ts']
  : project === 'toolchain' ? ['build/**/*.{test,spec}.{ts,tsx,js}']
  : project.startsWith('jsx-runtime-') ? ['src/jsx/dom/**/*.{test,spec}.{ts,tsx,js}', 'src/jsx/hooks/dom.test.tsx']
  : ['src/**/*.{test,spec}.{ts,tsx,js}', 'scripts/**/*.{test,spec}.{ts,tsx,js}', 'build/**/*.{test,spec}.{ts,tsx,js}'];
export default defineConfig({
  root,
  oxc: { jsx: { runtime: 'automatic', importSource: resolve(root, project === 'jsx-runtime-dom' ? 'src/jsx/dom' : 'src/jsx') } },
  test: {
    globals: true,
    coverage: { enabled: false },
    maxWorkers: 1,
    include,
    env: project === 'node' ? { NAME: 'Node' } : {},
    exclude: [...configDefaults.exclude, '**/sandbox/**', '**/*.case.test.*'],
  },
});
