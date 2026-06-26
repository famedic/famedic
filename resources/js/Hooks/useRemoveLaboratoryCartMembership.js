import { useState } from "react";
import { removeLaboratoryCartMembership } from "@/lib/laboratoryCartMembership";

export function useRemoveLaboratoryCartMembership(laboratoryBrand) {
	const [isRemoving, setIsRemoving] = useState(false);
	const [error, setError] = useState(null);

	const removeMembership = () => {
		if (isRemoving) {
			return;
		}

		setError(null);
		setIsRemoving(true);

		removeLaboratoryCartMembership(laboratoryBrand, {
			onFinish: () => setIsRemoving(false),
			onError: (message) => {
				setError(message);
				setIsRemoving(false);
			},
		});
	};

	return {
		isRemoving,
		error,
		removeMembership,
		clearError: () => setError(null),
	};
}
