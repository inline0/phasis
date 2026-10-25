globalThis.moduleLoaded = true;
globalThis.changeInput?.();

export default function Capture({ data }) {
    data.value += 1;
    data.toJSON = () => { throw new Error('must not reserialize rendered props'); };
    return <p>{data.value}</p>;
}
