import { pathToFileURL } from 'node:url';
import path from 'node:path';

const fixture = import.meta.dirname;
const [kind, target] = process.argv.slice(2);
let result;
try {
  if (kind === 'import') {
    const namespace = await import(target);
    result = { status: 'ok', value: Object.keys(namespace).sort().map(key => [key, typeof namespace[key]]) };
  } else if (kind === 'behavior') {
    const { run } = await import(pathToFileURL(path.join(fixture, target)));
    result = { status: 'ok', value: await run() };
  } else {
    throw new Error(`Unknown probe kind: ${kind}`);
  }
} catch (error) {
  result = { status: 'error', name: error.name, message: String(error.message).replaceAll(fixture, '<fixture>') };
}
// Some upstream entry points install hooks. Every probe owns a disposable process.
process.stdout.write('PHASIS_REMIX_RESULT:' + JSON.stringify(result) + '\n', () => process.exit(0));
