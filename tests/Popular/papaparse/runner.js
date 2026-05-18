// papaparse — fast CSV parser/unparser. UMD self-registers `Papa`
// globally.
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// 1. Simple parse with header
const csv1 = "id,name,active\n1,Alice,true\n2,Bob,false\n3,Carol,true";
const r1 = Papa.parse(csv1, { header: true, dynamicTyping: true });
log("parse.headers", r1.data);
log("parse.errors", r1.errors.length);

// 2. Without header
const csv2 = "1,2,3\n4,5,6\n7,8,9";
const r2 = Papa.parse(csv2, { dynamicTyping: true });
log("noheader", r2.data);

// 3. Quoted fields, embedded commas, escaped quotes
const csv3 = '"hello, world","quoted ""inside""","plain"\n"a","b","c"';
const r3 = Papa.parse(csv3);
log("quoted", r3.data);

// 4. Custom delimiter
const csv4 = "x;y;z\n1;2;3\n4;5;6";
const r4 = Papa.parse(csv4, { header: true, delimiter: ";" });
log("semicolon", r4.data);

// 5. Skip empty lines
const csv5 = "a,b\n\n1,2\n\n3,4\n";
const r5 = Papa.parse(csv5, { header: true, skipEmptyLines: true });
log("skipEmpty", r5.data);

// 6. Unparse (objects → CSV)
const data6 = [
  { id: 1, name: "Alice", roles: "admin,user" },
  { id: 2, name: 'B"ob', roles: "user" },
];
log("unparse", Papa.unparse(data6));

// 7. Unparse from rows (2D array)
log("unparse.rows", Papa.unparse([["a", "b", "c"], [1, 2, 3], [4, 5, 6]]));

// 8. Roundtrip
const original = [
  { name: "first", value: 'has "quotes" and, comma' },
  { name: "second", value: "plain" },
];
const csv = Papa.unparse(original);
const back = Papa.parse(csv, { header: true });
log("roundtrip", back.data);

// 9. Detect delimiter
const r9 = Papa.parse("a|b|c\n1|2|3", { header: true });
log("autodelim", r9.data);
log("autodelim.meta", { delim: r9.meta.delimiter, linebreak: r9.meta.linebreak });

console.log(out.join("\n"));
