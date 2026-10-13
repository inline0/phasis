import cx from 'classnames';                   // CommonJS package from node_modules
import { MessageCircle } from 'lucide-react';  // ES module package; only this icon's module loads
import Avatar from '@/components/Avatar';      // alias from jsconfig.json, no extension
import './PostCard.css';                       // an empty module on the server

export default function PostCard({ post }) {
  return (
    <article className={cx('post-card', { featured: post.featured })}>
      <Avatar name={post.author} />
      <h2>{post.title}</h2>
      <p>{post.excerpt}</p>
      <span><MessageCircle size={16} /> {post.commentCount} comments</span>
    </article>
  );
}
