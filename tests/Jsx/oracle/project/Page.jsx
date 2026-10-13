import { Suspense } from 'react';
import { siteName, mode } from 'site-config';
import PostCard from './components/PostCard';
import CommentsToggle from '@/components/CommentsToggle';
import Comments from './components/Comments.jsx';

export default function Page({ post }) {
  return (
    <main data-mode={mode}>
      <header>{siteName}</header>
      <PostCard post={post} />
      <div id="comments">
        <CommentsToggle count={post.commentCount} excerpt={post.excerpt} />
      </div>
      <Suspense fallback={<p>Loading comments</p>}>
        <Comments postId={post.id} />
      </Suspense>
    </main>
  );
}
