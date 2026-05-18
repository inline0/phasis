const LRU = LruCache.LRUCache;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const cache = new LRU({ max: 3 });
cache.set("a", 1);
cache.set("b", 2);
cache.set("c", 3);
log("size.3", cache.size);
log("get.a", cache.get("a"));

// Add 4th → evicts least recently used
cache.set("d", 4);
log("size.still.3", cache.size);

// "a" was most recently used (we just got it), so "b" should evict
log("has.a", cache.has("a"));
log("has.b", cache.has("b"));
log("has.c", cache.has("c"));
log("has.d", cache.has("d"));

// Delete
cache.delete("a");
log("after.delete.a", cache.has("a"));

// Clear
cache.clear();
log("after.clear", cache.size);

// Iterate
const c2 = new LRU({ max: 5 });
for (const [k, v] of [["x", 1], ["y", 2], ["z", 3]]) c2.set(k, v);
log("entries", [...c2.entries()].sort((a, b) => a[0].localeCompare(b[0])));

console.log(out.join("\n"));
