// mobx — proxy-based observable state library. Exercises Proxy traps
// heavily, plus reactive autorun / reaction tracking that uses
// micro-task scheduling.
const { makeObservable, observable, action, computed, autorun, runInAction, configure } = MobxBundle.default;
configure({ enforceActions: "never" });

const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// 1. Class-based observable with computed + action
class Counter {
  count = 0;
  step = 1;
  constructor() {
    makeObservable(this, {
      count: observable,
      step: observable,
      doubled: computed,
      increment: action,
      setStep: action,
    });
  }
  get doubled() { return this.count * 2; }
  increment() { this.count += this.step; }
  setStep(n) { this.step = n; }
}

const c = new Counter();
log("initial.count", c.count);
log("initial.doubled", c.doubled);
c.increment();
log("after.inc.count", c.count);
log("after.inc.doubled", c.doubled);
c.setStep(5);
c.increment();
log("after.step5.count", c.count);

// 2. Autorun tracks reads
const traces = [];
const dispose = autorun(() => {
  traces.push("count=" + c.count);
});
c.increment();
c.increment();
dispose();
c.increment();  // not tracked, dispose'd
log("autorun.traces", traces);

// 3. Observable object
const obj = observable({ a: 1, b: 2, nested: { x: 10 } });
const objTraces = [];
const dispose2 = autorun(() => {
  objTraces.push("a=" + obj.a + " x=" + obj.nested.x);
});
runInAction(() => {
  obj.a = 99;
  obj.nested.x = 50;
});
dispose2();
log("obj.traces", objTraces);
log("final.obj", { a: obj.a, b: obj.b, x: obj.nested.x });

// 4. Observable array
const arr = observable([1, 2, 3]);
const arrTraces = [];
const dispose3 = autorun(() => arrTraces.push("len=" + arr.length + " sum=" + arr.reduce((a, b) => a + b, 0)));
arr.push(4);
arr.push(5);
runInAction(() => arr.splice(0, 1));
dispose3();
log("arr.traces", arrTraces);
log("arr.final", Array.from(arr));

// 5. Observable map
const m = observable.map();
m.set("a", 1);
m.set("b", 2);
const mTraces = [];
const dispose4 = autorun(() => mTraces.push("size=" + m.size + " a=" + m.get("a")));
m.set("a", 99);
m.set("c", 3);
dispose4();
log("map.traces", mTraces);
log("map.entries", Array.from(m.entries()));

console.log(out.join("\n"));
