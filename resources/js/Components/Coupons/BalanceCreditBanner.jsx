import BalanceCreditCard from "@/Components/Coupons/BalanceCreditCard";

/**
 * @deprecated Usar BalanceCreditCard con variant="cart".
 */
export default function BalanceCreditBanner(props) {
	return <BalanceCreditCard {...props} variant="cart" />;
}
