// nanostores — tiny reactive state library. Atoms + maps + computed.
const { atom, map, computed } = NanostoresBundle.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// Atom
const $count = atom(0);
const counts = [];
const off1 = $count.listen((v) => counts.push(v));
$count.set(1);
$count.set(2);
$count.set(3);
log("atom.listens", counts);
log("atom.final", $count.get());

// Map (shallow object state)
const $user = map({ name: "Alice", age: 30 });
const userTraces = [];
const off2 = $user.listen((v) => userTraces.push({ ...v }));
$user.setKey("name", "Bob");
$user.setKey("age", 31);
log("map.traces", userTraces);
log("map.final", $user.get());

// Computed
const $double = computed($count, (c) => c * 2);
const dTraces = [];
const off3 = $double.listen((v) => dTraces.push(v));
$count.set(10);
$count.set(20);
log("computed.traces", dTraces);
log("computed.final", $double.get());

off1();
off2();
off3();

console.log(out.join("\n"));
