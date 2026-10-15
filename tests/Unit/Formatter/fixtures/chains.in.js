async function load(source) {
  const value = (await source.read()).trim();
  const parsed = (await source.read()).json().then(check).catch(report);
  const picked = (flag ? first : second).pick().sort().reverse();
  const negated = !source
    .ready;
  const awaited = await source
    .pending;
  return (await import('./module.js')).default;
}

const schema = z.string().optional().default('x');
const object = z.object({ id: z.string() }).strict().optional();
const mapped = items.filter(Boolean).map((item) => item.id).join(',');

const make = (id) => ({ id, label: String(id) });
const nested = (id) => ({ ...defaults, id });

const total = first + /* operand */ second;
const sum = left + // why
  right;
const assigned = /* value */ compute();
