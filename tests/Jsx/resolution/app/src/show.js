export function show(label, ...values) {
  console.log(label + ": " + values.map((v) => JSON.stringify(v)).join(" | "));
}
