const BRANDS = [
	{
		brand: "swisslab",
		name: "Swisslab",
		imageSrc: "GDA-SWISSLAB.png",
		states: ["Nuevo León"],
		className: "sm:rounded-none sm:rounded-tl-lg",
	},
	{
		brand: "olab",
		name: "Olab",
		imageSrc: "GDA-OLAB.png",
		states: ["Ciudad de México", "México"],
		className: "sm:rounded-none sm:max-lg:rounded-tr-lg",
	},
	{
		brand: "azteca",
		name: "Azteca",
		imageSrc: "GDA-AZTECA.png",
		states: ["Ciudad de México", "México"],
		className: "sm:rounded-none lg:rounded-tr-lg",
	},
	{
		brand: "jenner",
		name: "Jenner",
		imageSrc: "GDA-JENNER.png",
		states: ["Ciudad de México", "México"],
		className: "sm:rounded-none lg:rounded-bl-lg",
	},
	{
		brand: "liacsa",
		name: "Liacsa",
		imageSrc: "GDA-LIACSA.png",
		states: ["Chihuahua"],
		className: "sm:rounded-none sm:max-lg:rounded-bl-lg",
	},
];

export default function LaboratoryBrandSelection({ states = [] }) {
	const [state, setState] = useState(
		() => new URLSearchParams(window.location.search).get("state") || "",
	);
	const category =
		new URLSearchParams(window.location.search).get("category") || "";

	const stateBrandCount = useMemo(() => {
		return BRANDS.reduce((acc, { states }) => {
			states.forEach((s) => {
				acc[s] = (acc[s] || 0) + 1;
			});
			return acc;
		}, {});
	}, []);

	const brandsWithDisabled = useMemo(() => {
		return BRANDS.map((b) => ({
			...b,
			disabled: state !== "" && !b.states.includes(state),
		}));
	}, [state]);

	const sortedBrands = useMemo(() => {
		const enabled = brandsWithDisabled.filter((b) => !b.disabled);
		const disabledList = brandsWithDisabled.filter((b) => b.disabled);
		return [...enabled, ...disabledList];
	}, [brandsWithDisabled]);

	return (
		<FamedicLayout title="Selecciona tu laboratorio">
			<div>
				<GradientHeading>Laboratorios</GradientHeading>

				<Subheading>Selecciona tu marca</Subheading>

				<Text className="max-w-3xl">
					Por favor, selecciona tu marca de laboratorio preferida. Es
					importante que identifiques dónde te realizaras tus estudios
					ya que solo las tiendas de esa marca pueden realizar los
					análisis de laboratorio.
				</Text>
			</div>
			<div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
				<Field className="md:col-span-1">
					<Label>Filtrar por estado</Label>
					<Listbox
						placeholder="Estado"
						value={state}
						onChange={setState}
					>
						<ListboxOption value="">
							<ListboxLabel>Todos los estados</ListboxLabel>
						</ListboxOption>
						{states.map((state) => (
							<ListboxOption key={state} value={state}>
								<ListboxLabel>
									{state}
									{stateBrandCount[state]
										? ` (${stateBrandCount[state]})`
										: ""}
								</ListboxLabel>
							</ListboxOption>
						))}
					</Listbox>
				</Field>
			</div>
			<div className="grid gap-6 sm:mx-0 sm:grid-cols-2 lg:grid-cols-3">
				{sortedBrands.map((b) => (
					<LaboratoryBrand key={b.brand} {...b} category={category} />
				))}
			</div>
		</FamedicLayout>
	);
}

function LaboratoryBrand({
	brand,
	name,
	imageSrc,
	states,
	className,
	disabled,
	category = "",
}) {
	return (
		<Card
			hoverable
			href={route("laboratory-tests", {
				laboratory_brand: brand,
				...(category ? { category } : {}),
			})}
			className={clsx(
				className,
				"group relative p-6",
				disabled && "pointer-events-none opacity-40",
			)}
		>
			<div className="absolute left-1/2 top-[40%] z-0 hidden h-32 w-64 -translate-x-1/2 -translate-y-1/2 transform rounded-lg bg-slate-50 group-hover:opacity-90 dark:block"></div>
			<img
				alt={name}
				src={`/images/gda/${imageSrc}`}
				className="relative max-h-32 w-full object-contain"
			/>
			<div className="relative mt-4 flex flex-wrap justify-center gap-2">
				{states.map((state) => (
					<Badge key={state} color="famedic-lime">
						<MapPinIcon className="size-5" />

						{state}
					</Badge>
				))}
			</div>
		</Card>
	);
}

import FamedicLayout from "@/Layouts/FamedicLayout";
import { Badge } from "@/Components/Catalyst/badge";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { clsx } from "clsx";
import { Subheading } from "@/Components/Catalyst/heading";
import Card from "@/Components/Card";
import { MapPinIcon } from "@heroicons/react/16/solid";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import {
	Listbox,
	ListboxLabel,
	ListboxOption,
} from "@/Components/Catalyst/listbox";
import { useState, useMemo } from "react";
