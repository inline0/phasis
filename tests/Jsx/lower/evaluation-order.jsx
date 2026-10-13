import { log } from "recorder";
function A() {}
<A a={log("a")} {...{ s: log("spread") }} b={log("b")}>
  {log("c1")}
  <b x={log("inner")} />
  {log("c2")}
</A>;
