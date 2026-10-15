import cx from "classnames";
import dedupe from "classnames/dedupe";
console.log(cx("a", { b: true, c: false }, ["d"]), dedupe("x", "x", { y: true }));
