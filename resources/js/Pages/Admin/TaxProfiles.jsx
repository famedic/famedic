import { useMemo } from "react";
import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Avatar } from "@/Components/Catalyst/avatar";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import SearchInput from "@/Components/Admin/SearchInput";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import CustomerInfo from "@/Components/CustomerInfo";
import { DocumentTextIcon } from "@heroicons/react/16/solid";

export default function TaxProfiles({ customers, filters }) {
	const { data, setData, get, processing } = useForm({
		search: filters.search || "",
	});

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.tax-profiles.index"), {
				preserveState: true,
			});
		}
	};

	const showUpdateButton = useMemo(
		() => (data.search || "") !== (filters.search || ""),
		[data, filters],
	);

	const filtersCount = useMemo(
		() => (filters.search ? 1 : 0),
		[filters],
	);

	return (
		<AdminLayout title="Perfiles fiscales">
			<div className="space-y-6">
				<div className="flex flex-wrap items-center gap-4 justify-between">
					<Heading>Perfiles fiscales por usuario</Heading>

					<div className="flex items-center gap-3">
						<SearchInput
							value={data.search}
							onChange={(value) => setData("search", value)}
							placeholder="Buscar por nombre, correo, razón social o RFC..."
						/>
						<Button
							className="max-md:w-full"
							disabled={processing || !showUpdateButton}
							onClick={updateResults}
						>
							Actualizar resultados
							<FilterCountBadge count={filtersCount} />
						</Button>
					</div>
				</div>

				<PaginatedTable paginatedData={customers}>
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>Usuario / cliente</TableHeader>
								<TableHeader>Perfiles fiscales</TableHeader>
								<TableHeader>Creado</TableHeader>
								<TableHeader></TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{customers.data.map((customer) => (
								<TableRow key={customer.id}>
									<TableCell>
										<CustomerInfo customer={customer} />
									</TableCell>
									<TableCell>
										<Badge color="famedic">
											<DocumentTextIcon className="size-4" />
											{customer.tax_profiles_count} perfil
											{customer.tax_profiles_count === 1 ? "" : "es"}
										</Badge>
									</TableCell>
									<TableCell>
										<Text className="text-xs text-zinc-500">
											{customer.formatted_created_at || customer.created_at}
										</Text>
									</TableCell>
									<TableCell>
										<Button
											href={route("admin.tax-profiles.show", {
												customer: customer.id,
											})}
											outline
											size="sm"
										>
											Ver perfiles
										</Button>
									</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				</PaginatedTable>
			</div>
		</AdminLayout>
	);
}

