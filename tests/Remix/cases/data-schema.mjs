import { object, string, enum_, parse, parseSafe } from 'remix/data-schema';
import { min, minLength } from 'remix/data-schema/checks';
import * as coerce from 'remix/data-schema/coerce';

export function run() {
  const schema = object({ name: string().pipe(minLength(3)), age: coerce.number().pipe(min(13)), role: enum_(['admin', 'guest']) });
  const valid = parse(schema, { name: 'Ada', age: '37', role: 'admin' });
  const invalid = parseSafe(schema, { name: 'A', age: '2', role: 'other' });
  return { valid, success: invalid.success, issues: invalid.issues.map(issue => ({ path: issue.path, message: issue.message })) };
}
