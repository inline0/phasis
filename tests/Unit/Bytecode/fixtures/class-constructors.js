const constructorResults = [];
const constructorEvents = [];
function constructorOutcome(action) {
  try { return action(); } catch (error) { return error.name; }
}
class ConstructorBase {
  constructor(value) { this.value = value; this.array = [value]; this.object = {value}; }
}
class ConstructorDerived extends ConstructorBase {
  field = constructorEvents.push('derived-field');
  constructor(value) { super(value); this.after = value + 1; }
}
class ConstructorGrandchild extends ConstructorDerived {
  constructor(value) { super(value); this.last = value + 2; }
}
class ConstructorDefaultDerived extends ConstructorDerived {
  last = constructorEvents.push('default-field');
}
class ConstructorReplacementBase {
  baseField = constructorEvents.push('base-field');
  constructor(value) { constructorEvents.push('base-body'); return {replacement: value}; }
}
class ConstructorReplacement extends ConstructorReplacementBase {
  childField = constructorEvents.push('child-field');
  constructor(value) { super(value); this.saved = this.replacement; }
}
class ConstructorCapture extends ConstructorBase {
  constructor(value) {
    super(value);
    value += 10;
    this.after = value;
    this.read = () => [value, this.value];
  }
}
class ConstructorHoisted extends ConstructorBase {
  constructor(value) {
    super(value);
    function value() { return 'hoisted'; }
    this.same = this.value === value;
  }
}
class ConstructorPrivateBase {
  #secret = 9;
  constructor(value = this.#secret) { this.value = value; }
  read() { return this.#secret; }
}
class ConstructorPrivateDerived extends ConstructorPrivateBase {
  #secret = 11;
  constructor(value) { super(value); this.child = this.#secret; }
}
class ConstructorRest {
  constructor(first, ...values) { this.first = first; this.values = values; }
}
class ConstructorUndefined extends ConstructorBase {
  constructor(value) { super(value); return; }
}
class ConstructorPrimitive extends ConstructorBase {
  constructor(value) { super(value); return 7; }
}
class ConstructorObject extends ConstructorBase {
  constructor(value) { super(value); return {other: value}; }
}
function constructorReturn(value) { return value; }
class ConstructorReturnBase {
  constructor(value) { this.value = value; return constructorReturn(value); }
}
class ConstructorThrowBase {
  constructor(value) { constructorEvents.push('throw-base'); throw value; }
}
class ConstructorThrow extends ConstructorThrowBase {
  constructor(value) { super(value); constructorEvents.push('unreachable'); }
}
class ConstructorBeforeThis extends ConstructorBase {
  constructor(value) { this.before = value; super(value); }
}
class ConstructorDouble extends ConstructorBase {
  constructor(value) { super(value); super(value); }
}
class ConstructorOtherBase {
  constructor(value) { this.other = value; }
}
class ConstructorNewTarget extends ConstructorBase {
  constructor(value) { super(value); this.target = new.target; }
}
class ConstructorSpread extends ConstructorBase {
  constructor(...values) { super(...values); }
}
class ConstructorNull extends null {
  constructor() { super(); }
}
function ConstructorAlternateTarget() {}
function constructorFamily(parent, offset) {
  return class ConstructorFamily extends parent {
    constructor(value) { super(value); this.offset = offset; }
  };
}
class ConstructorSetter {
  constructor(value) { this.value = value; }
}

for (let pass = 0; pass < 3; pass++) {
  constructorEvents.length = 0;
  const base = new ConstructorBase(pass);
  const derived = new ConstructorDerived(pass);
  const grandchild = new ConstructorGrandchild(pass);
  const implicit = new ConstructorDefaultDerived(pass);
  constructorResults.push([base.value, base.array, base.object, derived.value, derived.after,
    grandchild.value, grandchild.after, grandchild.last, implicit.value, constructorEvents.slice()]);
  constructorEvents.length = 0;
  const replacement = new ConstructorReplacement(pass);
  constructorResults.push([replacement.saved, replacement.baseField, replacement.childField,
    replacement instanceof ConstructorReplacement, constructorEvents.slice()]);
  const captured = new ConstructorCapture(pass);
  constructorResults.push([captured.after, captured.read()]);
  const hoisted = new ConstructorHoisted(pass);
  constructorResults.push([hoisted.same, hoisted.value()]);
  const privateBase = new ConstructorPrivateBase();
  const privateDerived = new ConstructorPrivateDerived();
  constructorResults.push([privateBase.value, privateBase.read(), privateDerived.value, privateDerived.child]);
  const rest = new ConstructorRest(pass, 1, 2, 3);
  constructorResults.push([rest.first, rest.values]);
  constructorResults.push(new ConstructorUndefined(pass).value,
    constructorOutcome(() => new ConstructorPrimitive(pass)), new ConstructorObject(pass));
  constructorResults.push(new ConstructorReturnBase(pass).value,
    new ConstructorReturnBase({returned: pass}));
  const thrown = {name: 'sentinel'};
  constructorResults.push(constructorOutcome(() => new ConstructorThrow(thrown)));
  constructorResults.push(constructorOutcome(() => new ConstructorBeforeThis(pass)),
    constructorOutcome(() => new ConstructorDouble(pass)), constructorOutcome(() => new ConstructorNull()));
  constructorResults.push(new ConstructorNewTarget(pass).target === ConstructorNewTarget,
    new ConstructorSpread(pass).value);
  const reflected = Reflect.construct(ConstructorDerived, [pass], ConstructorAlternateTarget);
  constructorResults.push([Object.getPrototypeOf(reflected) === ConstructorAlternateTarget.prototype,
    reflected.value, reflected.after]);
  const proxied = new Proxy(ConstructorDerived, {});
  constructorResults.push(new proxied(pass).value);
  const bound = ConstructorDerived.bind(null, pass);
  constructorResults.push(new bound().value);
  constructorResults.push(constructorOutcome(() => ConstructorBase(pass)),
    constructorOutcome(() => ConstructorDerived(pass)));
  const FamilyA = constructorFamily(ConstructorBase, 10 + pass);
  const FamilyB = constructorFamily(ConstructorOtherBase, 20 + pass);
  const familyA = new FamilyA(pass), familyB = new FamilyB(pass);
  constructorResults.push([familyA.value, familyA.offset, familyB.other, familyB.offset]);
  Object.defineProperty(ConstructorSetter.prototype, 'value', {
    configurable: true,
    set(value) { this.received = value + 1; },
  });
  const setter = new ConstructorSetter(pass);
  Object.defineProperty(ConstructorSetter.prototype, 'value', {
    configurable: true, value: 3, writable: false,
  });
  constructorResults.push(setter.received, constructorOutcome(() => new ConstructorSetter(pass)));
  delete ConstructorSetter.prototype.value;
  constructorResults.push(new ConstructorSetter(pass).value);
  try { new ConstructorThrow(thrown); }
  catch (error) { constructorResults.push(error === thrown); }
}
