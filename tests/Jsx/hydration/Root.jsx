import React, { Component, createContext, useContext, useId, useState } from 'react';
import { format } from './format.js';

const Data = createContext(null);

function Counter({ count, increment }) {
    const data = useContext(Data);
    const id = useId();
    return <button id={id} aria-describedby={`${id}-value`} onClick={increment}>{data.label} {format(count)}</button>;
}

function Value({ count }) {
    const data = useContext(Data);
    return <output data-value="true">{data.value}:{count}</output>;
}

export default function Root({ data }) {
    const [count, setCount] = useState(0);
    // Deliberate impurity proves that the payload is the pre-render capture.
    if (data.mutate) data.value += 1;
    return <Data value={data}>
        <section>
            <Counter count={count} increment={() => setCount(count + 1)} />
            <Value count={count} />
        </section>
    </Data>;
}

export class Defaults extends Component {
    static defaultProps = { label: 'fallback' };
    render() {
        return <p>{this.props.label}:{Object.keys(this.props).sort().join(',')}</p>;
    }
}

export function Shapes({ data }) {
    return <pre>{JSON.stringify(data)}:{Object.is(data.zero, -0) ? 'negative-zero' : 'positive-zero'}:{data.decimal}</pre>;
}

export function Document() {
    return <html><head><title>Document</title></head><body>Content</body></html>;
}

export function Broken() {
    throw new Error('render failed deliberately');
}
