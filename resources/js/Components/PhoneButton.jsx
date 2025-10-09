import { PhoneIcon } from "@heroicons/react/16/solid";
import { Button } from "@/Components/Catalyst/button";
import { Anchor } from "@/Components/Catalyst/text";
import Flag from "react-flagpack";

export default function PhoneButton({ phone, fullPhone, countryCode, className = "" }) {
	if (!phone && !fullPhone) return null;

	const displayPhone = phone || fullPhone;
	const callPhone = fullPhone || phone;

	return (
		<Anchor href={`tel:${callPhone}`}>
			<Button outline className={className}>
				<PhoneIcon />
				{countryCode && (
					<Flag
						className="shrink-0"
						code={countryCode}
						size="s"
					/>
				)}
				{displayPhone}
			</Button>
		</Anchor>
	);
}