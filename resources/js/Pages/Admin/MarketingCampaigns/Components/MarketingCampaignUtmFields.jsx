import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Text } from "@/Components/Catalyst/text";

const UTM_FIELDS = [
	{
		key: "utm_source",
		label: "utm_source",
		friendlyLabel: "Fuente",
		hint: "utm_source",
	},
	{
		key: "utm_medium",
		label: "utm_medium",
		friendlyLabel: "Medio",
		hint: "utm_medium",
	},
	{
		key: "utm_campaign",
		label: "utm_campaign",
		friendlyLabel: "Campaña UTM",
		hint: "utm_campaign",
	},
	{
		key: "utm_term",
		label: "utm_term",
		friendlyLabel: "Término",
		hint: "utm_term",
	},
	{
		key: "utm_content",
		label: "utm_content",
		friendlyLabel: "Variante o anuncio",
		hint: "utm_content",
	},
];

export default function MarketingCampaignUtmFields({
	data,
	setData,
	errors = {},
	friendlyLabels = false,
}) {
	return (
		<div className="space-y-4">
			<Text className="text-sm text-zinc-600 dark:text-zinc-400">
				{friendlyLabels
					? "Parámetros opcionales para medir el canal de difusión."
					: "Parámetros UTM opcionales para atribución."}
			</Text>
			<div className="grid gap-4 sm:grid-cols-2">
				{UTM_FIELDS.map(({ key, label, friendlyLabel, hint }) => (
					<Field key={key}>
						<Label>{friendlyLabels ? friendlyLabel : label}</Label>
						<Input
							value={data[key] || ""}
							onChange={(e) => setData(key, e.target.value)}
							placeholder={friendlyLabels ? friendlyLabel : label}
						/>
						{friendlyLabels && (
							<Text className="mt-1 text-xs text-zinc-500">
								{hint}
							</Text>
						)}
						{errors[key] && (
							<ErrorMessage>{errors[key]}</ErrorMessage>
						)}
					</Field>
				))}
			</div>
		</div>
	);
}
