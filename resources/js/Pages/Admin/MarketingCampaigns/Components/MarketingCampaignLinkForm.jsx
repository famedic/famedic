import { useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Text } from "@/Components/Catalyst/text";
import {
	Listbox,
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import MarketingCampaignDateRangeFields from "./MarketingCampaignDateRangeFields";
import MarketingCampaignTargetFields from "./MarketingCampaignTargetFields";
import MarketingCampaignUtmFields from "./MarketingCampaignUtmFields";

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

export function slugify(value) {
	return String(value || "")
		.normalize("NFD")
		.replace(/[\u0300-\u036f]/g, "")
		.toLowerCase()
		.trim()
		.replace(/[^a-z0-9]+/g, "-")
		.replace(/^-+|-+$/g, "")
		.slice(0, 180);
}

function formatDateTime(value) {
	if (!value) return "—";
	try {
		return new Date(value).toLocaleString("es-MX");
	} catch {
		return String(value).slice(0, 16);
	}
}

export default function MarketingCampaignLinkForm({
	data,
	setData,
	errors = {},
	statusOptions = {},
	brands = {},
	categories = [],
	collections = [],
	productSearchUrl,
	aliases = [],
	isEdit = false,
	processing = false,
	onSubmit,
	submitLabel = "Guardar enlace",
}) {
	const [slugTouched, setSlugTouched] = useState(
		() => Boolean(data.slug && String(data.slug).trim()),
	);

	const handleNameChange = (value) => {
		const next = {
			...data,
			name: value,
		};
		if (!slugTouched) {
			next.slug = slugify(value);
		}
		setData(next);
	};

	const handleSlugChange = (value) => {
		setSlugTouched(true);
		setData("slug", value);
	};

	return (
		<form onSubmit={onSubmit} className="space-y-8">
			<div className="space-y-4">
				<Field>
					<Label>Nombre</Label>
					<Input
						autoFocus
						value={data.name || ""}
						onChange={(e) => handleNameChange(e.target.value)}
						placeholder="Nombre del enlace"
					/>
					{errors.name && <ErrorMessage>{errors.name}</ErrorMessage>}
				</Field>

				<Field>
					<Label>Slug</Label>
					<Input
						value={data.slug || ""}
						onChange={(e) => handleSlugChange(e.target.value)}
						placeholder="mi-enlace-campana"
					/>
					<Text className="mt-1 font-mono text-sm text-zinc-600 dark:text-zinc-400">
						/c/{data.slug || "…"}
					</Text>
					<Text className="mt-1 text-sm text-amber-700 dark:text-amber-400">
						La ruta pública aún no está habilitada.
					</Text>
					{isEdit && (
						<Text className="mt-1 text-sm text-zinc-500">
							Si cambias el slug, el valor anterior se conserva
							como alias.
						</Text>
					)}
					{errors.slug && <ErrorMessage>{errors.slug}</ErrorMessage>}
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
					{errors.status && (
						<ErrorMessage>{errors.status}</ErrorMessage>
					)}
				</Field>

				<MarketingCampaignDateRangeFields
					data={data}
					setData={setData}
					errors={errors}
				/>
			</div>

			<div className="space-y-3">
				<Text className="font-medium">Destino</Text>
				<MarketingCampaignTargetFields
					data={data}
					setData={setData}
					errors={errors}
					brands={brands}
					categories={categories}
					collections={collections}
					productSearchUrl={productSearchUrl}
				/>
			</div>

			<div className="space-y-3">
				<Text className="font-medium">UTM</Text>
				<MarketingCampaignUtmFields
					data={data}
					setData={setData}
					errors={errors}
				/>
			</div>

			{isEdit && aliases?.length > 0 && (
				<div className="space-y-3">
					<Text className="font-medium">Aliases históricos</Text>
					<Table dense>
						<TableHead>
							<TableRow>
								<TableHeader>Slug</TableHeader>
								<TableHeader>Creado</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{aliases.map((alias) => (
								<TableRow key={alias.id ?? alias.slug}>
									<TableCell className="font-mono">
										{alias.slug}
									</TableCell>
									<TableCell>
										{formatDateTime(alias.created_at)}
									</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				</div>
			)}

			<div className="flex justify-end">
				<Button type="submit" color="lime" disabled={processing}>
					{processing && <ArrowPathIcon className="animate-spin" />}
					{submitLabel}
				</Button>
			</div>
		</form>
	);
}
