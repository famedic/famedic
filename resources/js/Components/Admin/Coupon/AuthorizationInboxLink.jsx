import { usePage } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";

export default function AuthorizationInboxLink({ outline = true, className = "" }) {
	const { couponAuthorizerNav = {} } = usePage().props;

	if (!couponAuthorizerNav?.is_authorizer || !couponAuthorizerNav?.inbox_url) {
		return null;
	}

	const count = couponAuthorizerNav.pending_actionable_count ?? 0;

	return (
		<Button href={couponAuthorizerNav.inbox_url} outline={outline} className={className}>
			Ver pendientes de autorización
			{count > 0 && (
				<Badge color="amber" className="ml-2">
					{count}
				</Badge>
			)}
		</Button>
	);
}
