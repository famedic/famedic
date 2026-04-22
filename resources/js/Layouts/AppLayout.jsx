import { useState } from "react";
import EnvironmentBanner from "@/Components/EnvironmentBanner";

export default function AppLayout({ children }) {
	const [hasVisibleBanner, setHasVisibleBanner] = useState(false);

	return (
		<div className="min-h-screen">
			<EnvironmentBanner onVisibilityChange={setHasVisibleBanner} />
			<div className={hasVisibleBanner ? "pt-10" : ""}>{children}</div>
		</div>
	);
}
