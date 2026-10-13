export default async function Comments({ postId }) {
  const res = await fetch(`https://api.example.com/posts/${postId}/comments`);
  const comments = await res.json();
  return <ul>{comments.map((c) => <li key={c.id}>{c.body}</li>)}</ul>;
}
