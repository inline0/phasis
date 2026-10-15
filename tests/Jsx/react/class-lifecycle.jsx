import { Component, createContext } from 'react';
const Theme = createContext('light');
class WillMount extends Component {
  state = { a: 1 };
  UNSAFE_componentWillMount() {
    this.setState({ a: 2 });
    this.setState((s) => ({ b: s.a + 10 }));
    this.setState(null);
  }
  render() { return <p>{this.state.a}/{this.state.b}</p>; }
}
class Derived extends Component {
  static getDerivedStateFromProps(props, state) { return { d: props.v * 2 }; }
  state = { s: 'x' };
  render() { return <p>{this.state.s}{this.state.d}</p>; }
}
class Ctx extends Component {
  static contextType = Theme;
  render() { return <p>{this.context}</p>; }
}
class Replace extends Component {
  componentWillMount() { this.state = { r: 'replaced' }; }
  render() { return <p>{this.state.r}</p>; }
}
export default function App() {
  return (
    <div>
      <WillMount />
      <Derived v={4} />
      <Theme value="dark"><Ctx /></Theme>
      <Ctx />
      <Replace />
    </div>
  );
}
