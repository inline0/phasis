import { run } from '../harness.mjs';
import './timeout-scaling.test.mjs';
run().then(report => {
  globalThis.__honoReport = report;
  if (globalThis.__honoComplete) globalThis.__honoComplete();
  else process.stdout.write('__PHASIS_HONO_REPORT__' + JSON.stringify(report) + '\n', () => process.exit(0));
});
