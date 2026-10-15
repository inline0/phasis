function Row() {}
const rows = [1, 2, 3];
const table = <table><tbody>{rows.map((r) => <Row key={r} n={r}>{r % 2 ? <i>odd</i> : <b>even</b>}</Row>)}</tbody></table>;
const fn = () => <span>{`v${rows[0]}`}</span>;
fn();
[<a key="1" />, <b key="2" />];
({ k: <em /> });
