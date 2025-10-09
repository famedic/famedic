import { useMemo, useState } from "react";
import { useForm } from "@inertiajs/react";
import {
	PlusIcon,
	TagIcon,
	MagnifyingGlassIcon,
	CalendarIcon,
	XMarkIcon,
	CheckCircleIcon,
	XCircleIcon,
	ArchiveBoxIcon,
} from "@heroicons/react/16/solid";
import { FunnelIcon } from "@heroicons/react/24/outline";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text, Strong, Code } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Input, InputGroup } from "@/Components/Catalyst/input";
import { Field, Label } from "@/Components/Catalyst/fieldset";
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
import EmptyListCard from "@/Components/EmptyListCard";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import UpdateButton from "@/Components/Admin/UpdateButton";
import SearchResultsWithFilters from "@/Components/Admin/SearchResultsWithFilters";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import ExportDialog from "@/Components/ExportDialog";

export default function LaboratoryTests({
	laboratoryTests,
	filters,
	brands,
	categories,
}) {
	const { data, setData, get, errors, processing } = useForm({
		search: filters.search || "",
		brand: filters.brand || "",
		category: filters.category || "",
		requires_appointment: filters.requires_appointment || "",
	});

	const [showFilters, setShowFilters] = useState(false);

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.laboratory-tests.index"), {
				replace: true,
				preserveState: true,
			});
		}
	};

	const showUpdateButton = useMemo(
		() =>
			(data.search || "") !== (filters.search || "") ||
			(data.brand || "") !== (filters.brand || "") ||
			(data.category || "") !== (filters.category || "") ||
			(data.requires_appointment || "") !==
				(filters.requires_appointment || ""),
		[data, filters],
	);

	const filterBadges = useMemo(() => {
		const badges = [];

		if (filters.search) {
			badges.push(
				<Badge color="sky">
					<MagnifyingGlassIcon className="size-4" />
					{filters.search}
				</Badge>,
			);
		}

		if (filters.brand) {
			const brand = brands[filters.brand];
			if (brand) {
				badges.push(
					<LaboratoryBrandCard
						src={`/images/gda/${brand.imageSrc}`}
						className="w-12"
					/>,
				);
			}
		}

		if (filters.category) {
			const category = categories.find(
				(c) => c.id == filters.category,
			);
			badges.push(
				<Badge color="slate">
					<TagIcon className="size-4" />
					{category?.name}
				</Badge>,
			);
		}

		if (filters.requires_appointment === "required") {
			badges.push(
				<Badge color="famedic-lime">
					<CalendarIcon className="size-4" />
					requiere cita
				</Badge>,
			);
		}

		if (filters.requires_appointment === "not_required") {
			badges.push(
				<Badge color="zinc">
					<XMarkIcon className="size-4" />
					no requiere cita
				</Badge>,
			);
		}

		return badges;
	}, [filters, categories]);

	return (
		<AdminLayout title="Pruebas de Laboratorio">
			<div className="space-y-8">
				<div className="flex flex-wrap items-end justify-between gap-8">
					<Heading>Pruebas de Laboratorio</Heading>

					<Button href={route("admin.laboratory-tests.create")}>
						<PlusIcon />
						Agregar prueba
					</Button>
				</div>

				<form className="space-y-8" onSubmit={updateResults}>
					<div className="flex flex-col justify-between gap-8 md:flex-row md:items-center">
						<div className="flex-1 md:max-w-md">
							<InputGroup>
								<MagnifyingGlassIcon />
								<Input
									placeholder="Buscar pruebas..."
									value={data.search}
									onChange={(e) =>
										setData("search", e.target.value)
									}
								/>
							</InputGroup>
						</div>
						<div className="flex items-center justify-end gap-2">
							<Button
								outline
								className="w-full"
								onClick={() => setShowFilters(!showFilters)}
							>
								Filtros
								<FilterCountBadge count={filterBadges.length} />
							</Button>
						</div>
					</div>

					{showFilters && (
						<Filters
							data={data}
							setData={setData}
							brands={brands}
							categories={categories}
						/>
					)}

					{showUpdateButton && (
						<div className="flex justify-center">
							<UpdateButton
								type="submit"
								processing={processing}
							/>
						</div>
					)}
				</form>

				<LaboratoryTestsList
					laboratoryTests={laboratoryTests}
					filterBadges={filterBadges}
					filters={filters}
				/>
			</div>
		</AdminLayout>
	);
}

function Filters({ data, setData, brands, categories }) {
	return (
		<div className="grid gap-4 md:grid-cols-3">
			<ListboxFilter
				label="Marca"
				placeholder="Marca"
				value={data.brand}
				onChange={(value) => setData("brand", value)}
			>
				<ListboxOption value="">
					<ListboxLabel>Todas las marcas</ListboxLabel>
				</ListboxOption>
				{Object.entries(brands || {}).map(([key, brand]) => (
					<ListboxOption key={key} value={key}>
						<div className="flex w-full items-center justify-between gap-2">
							<ListboxLabel>{brand.name}</ListboxLabel>
							<LaboratoryBrandCard
								src={`/images/gda/${brand.imageSrc}`}
								className="w-12"
							/>
						</div>
					</ListboxOption>
				))}
			</ListboxFilter>
			<ListboxFilter
				label="Categoría"
				value={data.category}
				onChange={(value) => setData("category", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todas las categorías</ListboxLabel>
				</ListboxOption>
				{categories.map((category) => (
					<ListboxOption
						key={category.id}
						value={category.id.toString()}
						className="group"
					>
						<TagIcon />
						<ListboxLabel>{category.name}</ListboxLabel>
					</ListboxOption>
				))}
			</ListboxFilter>
			<ListboxFilter
				label="Requiere cita"
				value={data.requires_appointment}
				onChange={(value) => setData("requires_appointment", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todas</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="required" className="group">
					<CalendarIcon />
					<ListboxLabel>Requiere cita</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="not_required" className="group">
					<XMarkIcon />
					<ListboxLabel>No requiere cita</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
		</div>
	);
}

function LaboratoryTestsList({ laboratoryTests, filterBadges, filters }) {
	if (
		!laboratoryTests ||
		!laboratoryTests.data ||
		laboratoryTests.data.length === 0
	) {
		return <EmptyListCard />;
	}

	return (
		<>
			<SearchResultsWithFilters
				paginatedData={laboratoryTests}
				filterBadges={filterBadges}
			/>
			<ExportDialog
				canExport={true}
				filters={filters}
				filterBadges={filterBadges}
				exportUrl={route("admin.laboratory-tests.export")}
				title="Descargar pruebas de laboratorio"
			/>

			<PaginatedTable paginatedData={laboratoryTests}>
				<Table className="[--gutter:theme(spacing.6)]">
					<TableHead>
						<TableRow>
							<TableHeader>Prueba</TableHeader>
							<TableHeader>Marca</TableHeader>
							<TableHeader>Precio</TableHeader>
							<TableHeader className="text-right">
								Categoría y cita
							</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{laboratoryTests.data.map((laboratoryTest) => (
							<TableRow
								key={laboratoryTest.id}
								href={route(
									"admin.laboratory-tests.show",
									laboratoryTest.id,
								)}
								title={`Prueba #${laboratoryTest.id}`}
							>
								<TableCell>
									<div className="space-y-1">
										<Strong>{laboratoryTest.name}</Strong>
										<div>
											<Code className="text-xs">
												{laboratoryTest.gda_id}
											</Code>
										</div>
										{laboratoryTest.other_name && (
											<Text>
												{laboratoryTest.other_name}
											</Text>
										)}
									</div>
								</TableCell>

								<TableCell>
									<LaboratoryBrandCard
										src={`/images/gda/GDA-${laboratoryTest.brand.toUpperCase()}.png`}
										className="w-32 p-4"
									/>
								</TableCell>

								<TableCell>
									<div className="space-y-1">
										<Text>
											<Strong>
												{
													laboratoryTest.formatted_famedic_price
												}
											</Strong>
										</Text>
										<Text className="line-through">
											{
												laboratoryTest.formatted_public_price
											}
										</Text>
									</div>
								</TableCell>

								<TableCell className="text-right">
									<div className="space-y-1">
										<Text>
											{
												laboratoryTest
													.laboratory_test_category
													.name
											}
										</Text>
										<div>
											<Badge
												color={
													laboratoryTest.requires_appointment
														? "famedic-lime"
														: "slate"
												}
											>
												{laboratoryTest.requires_appointment ? (
													<CheckCircleIcon className="size-4" />
												) : (
													<XCircleIcon className="size-4" />
												)}
												{laboratoryTest.requires_appointment
													? "Sí"
													: "No"}
											</Badge>
										</div>
									</div>
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			</PaginatedTable>
		</>
	);
}
