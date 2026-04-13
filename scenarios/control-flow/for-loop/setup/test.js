// Basic for
let sum = 0;
for (let i = 1; i <= 10; i++) {
    sum += i;
}
console.log(sum);

// For with break
let found = -1;
for (let i = 0; i < 100; i++) {
    if (i * i > 50) {
        found = i;
        break;
    }
}
console.log(found);

// For with continue
let evens = 0;
for (let i = 0; i < 10; i++) {
    if (i % 2 !== 0) continue;
    evens++;
}
console.log(evens);

// While
let n = 1;
while (n < 100) {
    n *= 2;
}
console.log(n);

// Do-while
let x = 0;
do {
    x++;
} while (x < 5);
console.log(x);
