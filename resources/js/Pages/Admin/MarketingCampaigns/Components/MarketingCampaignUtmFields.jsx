import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Text } from "@/Components/Catalyst/text";

const UTM_FIELDS = [
	{ key: "utm_source", label: "utm_source" },
	{ key: "utm_medium", label: "utm_medium" },
	{ key: "utm_campaign", label: "utm_campaign" },
	{ key: "utm_term", label: "utm_term" },
	{ key: "utm_content", label: "utm_content" },
];

export default function MarketingCampaignUtmFields({
	data,
	setData,
	errors = {},
}) {
	return (
		<div className="space-y-4">
			<Text className="text-sm text-zinc-600 dark:text-zinc-400">
				Parámetros UTM opcionales para atribución.
			</Text>
			<div className="grid gap-4 sm:grid-cols-2">
				{UTM_FIELDS.map(({ key, label }) => (
					<Field key={key}>
						<Label>{label}</Label>
						<Input
							value={data[key] || ""}
							onChange={(e) => setData(key, e.target.value)}
							placeholder={label}
						/>
						{errors[key] && (
							<ErrorMessage>{errors[key]}</ErrorMessage>
						)}
					</Field>
				))}
			</div>
		</div>
	);
}
