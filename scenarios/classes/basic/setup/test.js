class Animal {
    constructor(name) {
        this.name = name;
    }
    speak() {
        return this.name + " makes a sound";
    }
}

class Dog extends Animal {
    speak() {
        return this.name + " barks";
    }
}

let a = new Animal("Cat");
console.log(a.speak());
let d = new Dog("Rex");
console.log(d.speak());
console.log(d.name);

class Counter {
    constructor() {
        this.count = 0;
    }
    increment() {
        this.count++;
        return this;
    }
    getCount() {
        return this.count;
    }
}

let c = new Counter();
c.increment().increment().increment();
console.log(c.getCount());
