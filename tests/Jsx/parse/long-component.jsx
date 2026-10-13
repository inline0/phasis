export default function Page({ posts, user }) {
  return (
    <Layout title="Blog" user={user}>
      <h1 className="title">Posts &amp; news</h1>
      {posts.length === 0 ? (
        <p>No posts yet.</p>
      ) : (
        <ul className="posts">
          {posts.map((post) => (
            <li key={post.id}>
              <a href={`/posts/${post.slug}`}>{post.title}</a>
              <span> by {post.author}</span>
            </li>
          ))}
        </ul>
      )}
    </Layout>
  );
}