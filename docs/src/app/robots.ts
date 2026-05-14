import { generateRobots } from "onedocs/seo";

const baseUrl = "https://phasis.dev";

export default function robots() {
  return generateRobots({ baseUrl });
}
