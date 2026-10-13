// The SSR bench page: header, nav, 100 post cards with interpolated text,
// a context consumer, about 1,300 elements. It imports a CommonJS package
// (classnames) and lucide-react icons through the barrel, so module
// loading is part of the measurement. Run with: php bench/ssr.php
import { createContext, useContext } from 'react';
import cx from 'classnames';
import { MessageCircle, BadgeCheck } from 'lucide-react';

const Theme = createContext({ accent: 'blue', compact: false });

function Header({ site }) {
  return (
    <header className="site-header">
      <a className="logo" href="/">{site.name}</a>
      <p className="tagline">{site.tagline}</p>
    </header>
  );
}

function Nav({ links }) {
  return (
    <nav aria-label="Main">
      <ul>{links.map((link) => <li key={link.href}><a href={link.href}>{link.label}</a></li>)}</ul>
    </nav>
  );
}

function Tag({ name }) {
  const theme = useContext(Theme);
  return <li className={cx('tag', `tag-${theme.accent}`)}>#{name}</li>;
}

function PostCard({ post }) {
  return (
    <article className={cx('post-card', { featured: post.featured, verified: post.verified })} id={`post-${post.id}`}>
      <header>
        <h2><a href={`/posts/${post.slug}`}>{post.title}</a></h2>
        <p className="meta">By {post.author} on <time dateTime={post.date}>{post.date}</time></p>
      </header>
      <p className="excerpt">{post.excerpt}</p>
      <footer>
        <span className="comments"><MessageCircle size={16} /> {post.comments} comments</span>
        {post.verified ? <span className="badge"><BadgeCheck size={14} /> Verified</span> : null}
        <ul className="tags">{post.tags.map((tag) => <Tag key={tag} name={tag} />)}</ul>
      </footer>
    </article>
  );
}

export default function Page({ site, links, posts }) {
  return (
    <Theme value={{ accent: 'green', compact: true }}>
      <div className="page">
        <Header site={site} />
        <Nav links={links} />
        <main>
          <h1>Latest posts</h1>
          {posts.map((post) => <PostCard key={post.id} post={post} />)}
        </main>
        <footer className="site-footer"><p>© {site.year} {site.name}. All rights reserved.</p></footer>
      </div>
    </Theme>
  );
}
