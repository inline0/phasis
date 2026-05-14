import { HomePage, CTASection } from "onedocs";
import config from "../../onedocs.config";

export default function Home() {
  return (
    <HomePage config={config}>
      <CTASection
        title="Run JavaScript inside PHP."
        description="Install the Composer package and execute your first JS file from PHP in seconds."
        cta={{ label: "Read the Docs", href: "/docs" }}
      />
    </HomePage>
  );
}
