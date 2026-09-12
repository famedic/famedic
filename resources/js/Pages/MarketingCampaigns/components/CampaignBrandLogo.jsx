import { useState } from "react";
import { Text } from "@/Components/Catalyst/text";

export default function CampaignBrandLogo({ brand, showLogo, size = "md", framed = true }) {
	const [failed, setFailed] = useState(false);
	const logoUrl = brand?.logo_url;
	const sizes = {
		sm: "h-14 max-w-28",
		md: "h-16 max-w-36",
		lg: "h-24 max-w-44 sm:h-28 sm:max-w-52",
	};
	const dimension = sizes[size] || sizes.md;

	if (!showLogo || !brand) return null;

	if (!logoUrl || failed) {
		return (
			<div className={`inline-flex ${dimension} items-center rounded-xl bg-white px-5 ring-1 ring-white/80`}>
				<Text className="font-semibold">{brand.label || "Marca"}</Text>
			</div>
		);
	}

	return (
		<span className={`inline-flex items-center rounded-xl ${
			framed ? "bg-white px-3 py-2 ring-1 ring-white/80" : ""
		}`}>
			<img
				src={logoUrl}
				alt={brand.label ? `Logo ${brand.label}` : "Logo de marca"}
				className={`${dimension} w-auto object-contain`}
				onError={() => setFailed(true)}
			/>
		</span>
	);
}
