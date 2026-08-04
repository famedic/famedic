import CouponStatusBadge from "@/Components/Admin/Coupon/CouponStatusBadge";
import { couponValidityBadge } from "@/lib/couponAdminUi";

export default function CouponValidityBadge({ coupon }) {
	const meta = couponValidityBadge(coupon);
	return <CouponStatusBadge label={meta.label} color={meta.color} />;
}
