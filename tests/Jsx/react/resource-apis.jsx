import { preload, preinit, preconnect, prefetchDNS, preloadModule, preinitModule } from 'react-dom';
export default function App() {
  prefetchDNS('https://dns.example');
  preconnect('https://cdn.example', { crossOrigin: 'anonymous' });
  preload('/font.woff2', { as: 'font', type: 'font/woff2' });
  preload('/img.png', { as: 'image', fetchPriority: 'high' });
  preload('/style.css', { as: 'style' });
  preload('/script.js', { as: 'script', integrity: 'sha256-x' });
  preloadModule('/mod.js');
  preinit('/styles.css', { as: 'style', precedence: 'medium' });
  preinit('/init.js', { as: 'script' });
  preinitModule('/init-mod.js');
  return <div>resources</div>;
}
