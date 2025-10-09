import { Input, InputGroup } from "@/Components/Catalyst/input";
import { Badge, BadgeButton } from "@/Components/Catalyst/badge";
import {
	Listbox,
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";
import {
	MagnifyingGlassIcon,
	ArchiveBoxIcon,
	ArrowPathIcon,
	CalendarIcon,
	XMarkIcon,
	EyeSlashIcon,
	BeakerIcon,
	TagIcon,
} from "@heroicons/react/16/solid";
import { ErrorMessage, Field, Label } from "@/Components/Catalyst/fieldset";
import { Button } from "@/Components/Catalyst/button";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";

export default function LaboratoryTestsFilters({
	data,
	setData,
	updateResults,
	errors,
	processing,
	showUpdateButton,
	filters,
	brands,
	categories,
	showFilters,
	setShowFilters,
}) {
	return (
		<>
			<Filters
				data={data}
				setData={setData}
				updateResults={updateResults}
				errors={errors}
				processing={processing}
				showUpdateButton={showUpdateButton}
				brands={brands}
				categories={categories}
				showFilters={showFilters}
				setShowFilters={setShowFilters}
			/>

			<SearchResultsMessage filters={filters} brands={brands} />
		</>
	);
}

function Filters({
	data,
	setData,
	updateResults,
	errors,
	processing,
	showUpdateButton,
	brands,
	categories,
	showFilters,
	setShowFilters,
}) {
	const appointmentRequiredFilterOptions = [
		{
			value: "",
			label: "Todos",
			Icon: ArchiveBoxIcon,
		},
		{
			value: "1",
			label: "Requerida",
			Icon: CalendarIcon,
		},
		{
			value: "0",
			label: "No requerida",
			Icon: XMarkIcon,
		},
	];

	return (
		<form className="space-y-8" onSubmit={updateResults}>
			<div className="md:max-w-md">
				<InputGroup>
					<MagnifyingGlassIcon />
					<Input
						placeholder="Buscar por nombre, GDA ID..."
						value={data.search}
						onChange={(e) => setData("search", e.target.value)}
					/>
				</InputGroup>
			</div>

			{showFilters && (
				<div>
					<div className="flex justify-end">
						<BadgeButton onClick={() => setShowFilters(false)}>
							<EyeSlashIcon className="size-4" />
							ocultar filtros
						</BadgeButton>
					</div>
					<div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
						<Field>
							<Label>Marca</Label>
							<Listbox
								value={data.brand}
								onChange={(value) => {
									setData("brand", value);
								}}
							>
								<ListboxOption value="" className="group">
									<ArchiveBoxIcon />
									<ListboxLabel>
										Todas las marcas
									</ListboxLabel>
								</ListboxOption>
								{Object.entries(brands).map(
									([value, brand]) => (
										<ListboxOption
											key={value}
											value={value}
											className="group"
										>
											<div className="flex w-full items-center justify-between gap-2">
												<ListboxLabel>
													{brand.name}
												</ListboxLabel>
												<LaboratoryBrandCard
													src={`/images/gda/${brand.imageSrc}`}
													size="small"
													className="h-6 w-auto"
													imgClassName="h-4 w-auto"
												/>
											</div>
										</ListboxOption>
									),
								)}
							</Listbox>
						</Field>

						<Field>
							<Label>Categoría</Label>
							<Listbox
								value={data.category}
								onChange={(value) => {
									setData("category", value);
								}}
							>
								<ListboxOption value="" className="group">
									<ArchiveBoxIcon />
									<ListboxLabel>
										Todas las categorías
									</ListboxLabel>
								</ListboxOption>
								{categories.map((category) => (
									<ListboxOption
										key={category.id}
										value={category.name}
										className="group"
									>
										<TagIcon />
										<ListboxLabel>
											{category.name}
										</ListboxLabel>
									</ListboxOption>
								))}
							</Listbox>
						</Field>

						<Field>
							<Label>Requiere cita</Label>
							<Listbox
								value={data.requires_appointment}
								onChange={(value) => {
									setData("requires_appointment", value);
								}}
							>
								{appointmentRequiredFilterOptions.map(
									(option) => (
										<ListboxOption
											key={option.value}
											value={option.value}
											className="group"
										>
											<option.Icon />
											<ListboxLabel>
												{option.label}
											</ListboxLabel>
										</ListboxOption>
									),
								)}
							</Listbox>
						</Field>
					</div>
				</div>
			)}

			{showUpdateButton && (
				<div className="flex justify-center">
					<Button
						disabled={processing}
						type="submit"
						className="max-md:w-full"
					>
						<ArrowPathIcon className="animate-pulse" />
						Actualizar resultados
					</Button>
				</div>
			)}
		</form>
	);
}

function SearchResultsMessage({ filters, brands }) {
	return (
		(filters.search ||
			filters.brand ||
			filters.category ||
			(filters.requires_appointment !== "" &&
				filters.requires_appointment !== null &&
				filters.requires_appointment !== undefined)) && (
			<div className="flex flex-wrap gap-2">
				{filters.search && (
					<Badge color="sky">
						<MagnifyingGlassIcon className="size-4" />
						{filters.search}
					</Badge>
				)}
				{filters.brand && (
					<Badge color="blue">
						<BeakerIcon className="size-4" />
						{brands[filters.brand]?.name || filters.brand}
					</Badge>
				)}
				{filters.category && (
					<Badge color="purple">
						<TagIcon className="size-4" />
						{filters.category}
					</Badge>
				)}
				{filters.requires_appointment !== "" &&
					filters.requires_appointment !== null &&
					filters.requires_appointment !== undefined && (
						<Badge
							color={
								filters.requires_appointment === "1" ||
								filters.requires_appointment === 1
									? "amber"
									: "zinc"
							}
						>
							{filters.requires_appointment === "1" ||
							filters.requires_appointment === 1 ? (
								<>
									<CalendarIcon className="size-4" />
									cita requerida
								</>
							) : (
								<>
									<XMarkIcon className="size-4" />
									cita no requerida
								</>
							)}
						</Badge>
					)}
			</div>
		)
	);
}
