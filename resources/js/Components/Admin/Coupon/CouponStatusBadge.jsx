import { Badge } from "@/Components/Catalyst/badge";

export default function CouponStatusBadge({ label, color = "zinc" }) {
	if (!label) return null;
	return <Badge color={color}>{label}</Badge>;
}
