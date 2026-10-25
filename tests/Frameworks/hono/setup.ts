import { main } from "../../../scripts/lib/cli.ts";
import { setupCommand } from "../setup.ts";
export const command = setupCommand("hono");
if (import.meta.main) main(command);
