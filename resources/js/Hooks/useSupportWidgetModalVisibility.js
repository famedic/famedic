import { useEffect } from "react";

import { setSupportWidgetModalOpen } from "@/lib/supportWidgets";

export function useSupportWidgetModalVisibility(open) {
	useEffect(() => {
		if (!open) return undefined;

		setSupportWidgetModalOpen(true);

		return () => setSupportWidgetModalOpen(false);
	}, [open]);
}
