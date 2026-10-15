import { chunk, sortBy, uniqBy } from "lodash-es";
console.log(JSON.stringify(chunk([1, 2, 3, 4, 5], 2)), JSON.stringify(sortBy([{ a: 2 }, { a: 1 }], "a")), uniqBy([1, 1.5, 2], Math.floor).length);
