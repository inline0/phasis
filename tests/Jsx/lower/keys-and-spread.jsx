const props = { a: 1, key: "p" }, k = "k";
<div key="x" {...props} />;
<div {...props} key="x" />;
<div {...props} key="x">child{k}</div>;
<div a="1" key={k} b={2} {...props} c />;
<div {...props} />;
<div {...props} {...{ b: 2 }} />;
