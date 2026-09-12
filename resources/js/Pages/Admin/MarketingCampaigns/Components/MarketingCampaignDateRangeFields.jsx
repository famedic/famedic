import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

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
		<div className="space-y-3">
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
					<div className="flex items-center justify-between gap-3">
						<Label>Fin</Label>
						<Button type="button" plain onClick={() => setData("ends_at", "")}>
							Sin expiración
						</Button>
					</div>
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
			{!data.ends_at && (
				<Text className="text-sm text-zinc-500">
					Sin fecha de fin: se tratará como campaña permanente mientras el estado permita publicarla.
				</Text>
			)}
		</div>
	);
}
