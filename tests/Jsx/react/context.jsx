import { createContext, useContext, use } from 'react';
const Theme = createContext('light');
const User = createContext(null);
Theme.displayName = 'ThemeCtx';
function Show() { const t = useContext(Theme); const u = use(User); return <p>{t}:{u ? u.name : 'anon'}</p>; }
export default function App() {
  return (
    <div>
      <Show />
      <Theme value="dark">
        <Show />
        <Theme.Provider value="nested"><Show /></Theme.Provider>
        <Theme.Consumer>{(v) => <b>{v}</b>}</Theme.Consumer>
        <User value={{ name: 'Dennis' }}><Show /></User>
      </Theme>
      <Show />
      {Theme}
    </div>
  );
}
