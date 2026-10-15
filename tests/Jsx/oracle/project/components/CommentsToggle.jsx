import { useId, useState } from 'react';

export default function CommentsToggle({ count, excerpt }) {
  const [open, setOpen] = useState(false);
  const panelId = useId();
  return (
    <div>
      <button aria-controls={panelId} onClick={() => setOpen(!open)}>
        {count} comments
      </button>
      <section id={panelId} hidden={!open}>{excerpt}</section>
    </div>
  );
}
