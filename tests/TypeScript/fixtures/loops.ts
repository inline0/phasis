for (let i: number = 0, j: number = 2; i < j; i++) console.log('let', i, j)
for (var v: number = 0; v < 1; v++) console.log('var', v)
const node: { readonly value: number; readonly parent: unknown } | null = {
  value: 1,
  parent: null,
}
for (let cursor: typeof node | null = node; cursor; cursor = null)
  console.log('cursor', cursor.value)
for (const limit: number = 1; ; ) {
  console.log('const', limit)
  break
}
for (const [key, value] of Object.entries({ a: 1 })) console.log('of', key, value)
try {
  throw new Error('boom')
} catch (error: unknown) {
  console.log('catch', error instanceof Error ? error.message : '')
}
