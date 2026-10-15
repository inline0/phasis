import placeholder from '../../assets/avatar.svg';
import { initials } from '../../lib/text';

export default function Avatar({ name }) {
  return (
    <figure className="avatar">
      <img src={placeholder} alt="" width={32} height={32} />
      <figcaption>{initials(name)}</figcaption>
    </figure>
  );
}
