import CouponStatusBadge from "@/Components/Admin/Coupon/CouponStatusBadge";
import { beneficiaryStatusMeta } from "@/lib/couponAdminUi";

export default function CouponBeneficiaryStatusBadge({ row }) {
	const meta = beneficiaryStatusMeta(row);
	return <CouponStatusBadge label={meta.label} color={meta.color} />;
}
