import { readFile, realpath, symlink } from 'node:fs/promises';
import { dirname, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const directory = dirname(fileURLToPath(import.meta.url));
const metadata = JSON.parse(await readFile(resolve(directory, 'upstream.json'), 'utf8'));
const upstream = resolve(directory, metadata.directory);
const published = resolve(directory, '../node_modules/hono');
const packageMetadata = JSON.parse(await readFile(resolve(published, 'package.json'), 'utf8'));
if (packageMetadata.version !== metadata.version) throw new Error('Published Hono fixture version differs from upstream source');
const distribution = await realpath(resolve(published, 'dist'));
const target = resolve(upstream, 'dist');
try {
  if (await realpath(target) !== distribution) throw new Error('Existing upstream dist is not the pinned published package');
} catch (error) {
  if (error.code !== 'ENOENT') throw error;
  await symlink(relative(upstream, distribution), target, 'dir');
}
console.log(`Declaration fixtures use published Hono ${metadata.version}: ${distribution}`);
