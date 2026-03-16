import { useMemo, useState } from "react";
import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
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
import {
	BanknotesIcon,
	CreditCardIcon,
	ClockIcon,
} from "@heroicons/react/16/solid";

export default function PaymentAttempts({ attempts, filters, gateways, statuses }) {
	const { data, setData, get, processing } = useForm({
		search: filters.search || "",
		gateway: filters.gateway || "",
		status: filters.status || "",
	});

	const [showFilters, setShowFilters] = useState(false);

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.payment-attempts.index"), {
				preserveState: true,
			});
		}
	};

	const showUpdateButton = useMemo(
		() =>
			(data.search || "") !== (filters.search || "") ||
			(data.gateway || "") !== (filters.gateway || "") ||
			(data.status || "") !== (filters.status || ""),
		[data, filters],
	);

	const filterBadges = useMemo(() => {
		const badges = [];

		if (filters.search) {
			badges.push(
				<Badge color="sky">
					{filters.search}
				</Badge>,
			);
		}

		if (filters.gateway) {
			badges.push(
				<Badge color="slate">
					{filters.gateway}
				</Badge>,
			);
		}

		if (filters.status) {
			badges.push(
				<Badge color="famedic-lime">
					{filters.status}
				</Badge>,
			);
		}

		return badges;
	}, [filters]);

	const filtersCount = useMemo(
		() =>
			["search", "gateway", "status"].filter((key) => filters[key])
				.length,
		[filters],
	);

	return (
		<AdminLayout title="Intentos de pago">
			<div className="space-y-6">
				<div className="flex flex-wrap items-center gap-4 justify-between">
					<Heading>Intentos de pago</Heading>

					<div className="flex items-center gap-3">
						<SearchInput
							value={data.search}
							onChange={(value) => setData("search", value)}
							placeholder="Buscar por referencia, transacción o cliente..."
						/>
						<Button
							outline
							type="button"
							onClick={() => setShowFilters((v) => !v)}
						>
							Filtros
							<FilterCountBadge count={filtersCount} />
						</Button>
						<Button
							className="max-md:w-full"
							disabled={processing || !showUpdateButton}
							onClick={updateResults}
						>
							Actualizar resultados
						</Button>
					</div>
				</div>

				{filterBadges.length > 0 && (
					<div className="flex flex-wrap gap-2">
						{filterBadges.map((badge, index) => (
							<span key={index}>{badge}</span>
						))}
					</div>
				)}

				{showFilters && (
					<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 space-y-3">
						<div className="grid gap-4 md:grid-cols-3">
							<div className="space-y-1">
								<Text className="text-sm font-medium">
									Portal / gateway
								</Text>
								<select
									value={data.gateway}
									onChange={(e) => setData("gateway", e.target.value)}
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
								>
									<option value="">Todos</option>
									{gateways.map((g) => (
										<option key={g} value={g}>
											{g}
										</option>
									))}
								</select>
							</div>
							<div className="space-y-1">
								<Text className="text-sm font-medium">Estatus</Text>
								<select
									value={data.status}
									onChange={(e) => setData("status", e.target.value)}
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
								>
									<option value="">Todos</option>
									{statuses.map((s) => (
										<option key={s} value={s}>
											{s}
										</option>
									))}
								</select>
							</div>
						</div>
					</div>
				)}

				<PaginatedTable paginatedData={attempts}>
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>Cliente</TableHeader>
								<TableHeader>Monto</TableHeader>
								<TableHeader>Gateway</TableHeader>
								<TableHeader>Estatus</TableHeader>
								<TableHeader>Referencia</TableHeader>
								<TableHeader>Procesado</TableHeader>
								<TableHeader></TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{attempts.data.map((attempt) => (
								<TableRow key={attempt.id}>
									<TableCell>
										{attempt.customer && (
											<CustomerInfo customer={attempt.customer} />
										)}
									</TableCell>
									<TableCell>
										<div className="flex items-center gap-1 text-sm">
											<BanknotesIcon className="size-4 text-zinc-400" />
											<Strong>
												$
												{(attempt.amount_cents / 100).toFixed(
													2,
												)}
											</Strong>
										</div>
									</TableCell>
									<TableCell>
										<div className="flex items-center gap-1 text-xs text-zinc-600 dark:text-zinc-300">
											<CreditCardIcon className="size-4" />
											{attempt.gateway || "N/D"}
										</div>
									</TableCell>
									<TableCell>
										<Badge color={attempt.status === "approved" ? "famedic-lime" : attempt.status === "error" || attempt.status === "declined" ? "red" : "slate"}>
											{attempt.status || "N/D"}
										</Badge>
									</TableCell>
									<TableCell>
										<Text className="text-xs">
											{attempt.reference}
										</Text>
									</TableCell>
									<TableCell>
										<div className="flex items-center gap-1 text-xs text-zinc-500">
											<ClockIcon className="size-4" />
											<span>
												{attempt.processed_at || attempt.created_at}
											</span>
										</div>
									</TableCell>
									<TableCell>
										<Button
											href={route("admin.payment-attempts.show", {
												payment_attempt: attempt.id,
											})}
											outline
											size="sm"
										>
											Ver detalle
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

