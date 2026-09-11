import { useState } from "react";
import { Text } from "@/Components/Catalyst/text";

export default function CampaignBrandLogo({ brand, showLogo, size = "md" }) {
	const [failed, setFailed] = useState(false);
	const logoUrl = brand?.logo_url;
	const height = size === "sm" ? "h-10" : "h-12";

	if (!showLogo || !brand) return null;

	if (!logoUrl || failed) {
		return (
			<div className={`inline-flex ${height} items-center rounded-lg bg-zinc-100 px-4 dark:bg-zinc-800`}>
				<Text className="font-semibold">{brand.label || "Marca"}</Text>
			</div>
		);
	}

	return (
		<img
			src={logoUrl}
			alt={brand.label ? `Logo ${brand.label}` : "Logo de marca"}
			className={`${height} w-auto object-contain`}
			onError={() => setFailed(true)}
		/>
	);
}
