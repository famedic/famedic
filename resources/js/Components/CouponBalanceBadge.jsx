import React from "react";
import { GiftIcon } from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";

export default function CouponBalanceBadge({
	children = "Cupón aplicado",
	className = "",
}) {
	return (
		<Badge color="famedic-lime" className={className}>
			<GiftIcon className="size-4" />
			{children}
		</Badge>
	);
}
