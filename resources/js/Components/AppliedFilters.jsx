import React from "react";

export default function AppliedFilters({ filterBadges = [] }) {
	if (!filterBadges || filterBadges.length === 0) {
		return null;
	}

	return (
		<div className="flex flex-wrap gap-2">
			{filterBadges.map((filter, index) => (
				<React.Fragment key={index}>{filter}</React.Fragment>
			))}
		</div>
	);
}
