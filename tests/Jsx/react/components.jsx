import { memo, forwardRef, Component, PureComponent, createElement } from 'react';
function Hello({ name, children }) { return <p>Hello {name}{children}</p>; }
const Memo = memo(function Inner({ x }) { return <i>{x}</i>; });
const Fwd = forwardRef(function F(props, ref) { return <b data-ref={typeof ref}>{props.label}</b>; });
class Cls extends Component {
  static defaultProps = { color: 'green' };
  constructor(props) { super(props); this.state = { n: props.start }; }
  render() { return <em>{this.props.color} {this.state.n}</em>; }
}
class Pure extends PureComponent { render() { return <s>{this.props.v}</s>; } }
Hello.displayName = 'Greeting';
export default function App() {
  return (
    <div>
      <Hello name="a">!</Hello>
      <Memo x={1} />
      <Fwd label="fwd" ref={{ current: null }} />
      <Cls start={5} />
      <Cls start={6} color="red" />
      <Pure v="p" />
      {createElement('section', { id: 's', key: 'k' }, 'one', 'two')}
      {createElement(Hello, null)}
    </div>
  );
}
