import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";

export function toDatetimeLocalValue(iso) {
	if (!iso) return "";
	const d = new Date(iso);
	if (Number.isNaN(d.getTime())) {
		return String(iso).slice(0, 16);
	}
	const pad = (n) => String(n).padStart(2, "0");
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function fromDatetimeLocalValue(local) {
	if (!local) return null;
	return local;
}

export default function MarketingCampaignDateRangeFields({
	data,
	setData,
	errors = {},
}) {
	return (
		<div className="grid gap-4 sm:grid-cols-2">
			<Field>
				<Label>Inicio</Label>
				<Input
					type="datetime-local"
					value={data.starts_at || ""}
					onChange={(e) => setData("starts_at", e.target.value)}
				/>
				{errors.starts_at && (
					<ErrorMessage>{errors.starts_at}</ErrorMessage>
				)}
			</Field>
			<Field>
				<Label>Fin</Label>
				<Input
					type="datetime-local"
					value={data.ends_at || ""}
					onChange={(e) => setData("ends_at", e.target.value)}
				/>
				{errors.ends_at && (
					<ErrorMessage>{errors.ends_at}</ErrorMessage>
				)}
			</Field>
		</div>
	);
}
