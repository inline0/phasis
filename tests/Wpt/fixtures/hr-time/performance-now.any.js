// Sourced from web-platform-tests/wpt: hr-time/
// Spec: https://w3c.github.io/hr-time/

test(() => {
  assert_equals(typeof performance, "object");
  assert_equals(typeof performance.now, "function");
  assert_equals(typeof performance.timeOrigin, "number");
}, "performance: shape");

test(() => {
  const t1 = performance.now();
  const t2 = performance.now();
  assert_true(typeof t1 === "number");
  assert_true(t1 >= 0);
  assert_true(t2 >= t1, "monotonic non-decreasing");
}, "performance.now: monotonic");

test(() => {
  assert_true(performance.timeOrigin > 0, "timeOrigin positive");
  assert_true(performance.timeOrigin < Date.now() + 1000,
    "timeOrigin within 1s of Date.now");
}, "performance.timeOrigin: reasonable value");
