interface Named { name: string }
class Base<T> {
  value: T;
  constructor(value: T) { this.value = value; }
  getValue(): T { return this.value; }
}
class User extends Base<string> implements Named {
  public name: string = 'Ada';
  private age: number = 41;
  protected optional?: boolean;
  readonly id: number = 1;
  declare missing: string;
  definite!: string;
  static label: string = 'User';
  constructor() { super('base'); }
  next<T extends number>(value: T): number { return value + this.age; }
}
const user: User = new User();
console.log(new Base<number>(42).value);
console.log(user.name, user.getValue(), user.next<number>(1), User.label);
console.log(Object.keys(user).sort().join(','));
const methods = { identity<T>(value: T): T { return value; } };
console.log(methods.identity<string>('method'));
