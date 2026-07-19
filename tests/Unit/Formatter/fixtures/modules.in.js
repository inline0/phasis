import defaultExport, { named, other as renamed } from 'some-module';
import * as ns from 'namespace-module';
import 'side-effect';
export { a, b as c };
export default function main() { return 1; }
export const value = 42;
export * from 'reexport';
export * as bundle from 'bundle-module';
