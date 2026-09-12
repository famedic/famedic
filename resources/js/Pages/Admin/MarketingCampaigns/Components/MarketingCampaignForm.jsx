import { Button } from "@/Components/Catalyst/button";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import {
	Listbox,
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import MarketingCampaignDateRangeFields from "./MarketingCampaignDateRangeFields";

function optionEntries(options) {
	if (!options) return [];
	if (Array.isArray(options)) {
		return options.map((option) =>
			typeof option === "string"
				? [option, option]
				: [option.value, option.label ?? option.value],
		);
	}
	return Object.entries(options);
}

export default function MarketingCampaignForm({
	data,
	setData,
	errors = {},
	statusOptions = {},
	processing = false,
	onSubmit,
	submitLabel = "Guardar",
}) {
	return (
		<form onSubmit={onSubmit} className="space-y-6">
			<Field>
				<Label>Nombre</Label>
				<Input
					autoFocus
					value={data.name || ""}
					onChange={(e) => setData("name", e.target.value)}
					placeholder="Nombre de la campaña"
				/>
				{errors.name && <ErrorMessage>{errors.name}</ErrorMessage>}
			</Field>

			<Field>
				<Label>Descripción</Label>
				<Textarea
					rows={3}
					value={data.description || ""}
					onChange={(e) => setData("description", e.target.value)}
					placeholder="Descripción interna (opcional)"
				/>
				{errors.description && (
					<ErrorMessage>{errors.description}</ErrorMessage>
				)}
			</Field>

			<Field>
				<Label>Estado</Label>
				<Listbox
					value={data.status || ""}
					onChange={(value) => setData("status", value)}
					placeholder="Seleccionar estado"
				>
					{optionEntries(statusOptions).map(([value, label]) => (
						<ListboxOption key={value} value={value}>
							<ListboxLabel>{label}</ListboxLabel>
						</ListboxOption>
					))}
				</Listbox>
				{errors.status && <ErrorMessage>{errors.status}</ErrorMessage>}
			</Field>

			<MarketingCampaignDateRangeFields
				data={data}
				setData={setData}
				errors={errors}
			/>

			<div className="flex justify-end">
				<Button type="submit" color="lime" disabled={processing}>
					{processing && <ArrowPathIcon className="animate-spin" />}
					{submitLabel}
				</Button>
			</div>
		</form>
	);
}
