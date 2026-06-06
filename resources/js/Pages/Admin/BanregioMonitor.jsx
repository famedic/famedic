import { useMemo, useState } from "react";
import { useForm } from "@inertiajs/react";
import clsx from "clsx";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Code } from "@/Components/Catalyst/text";
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
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import StatusBadge from "@/Components/StatusBadge";
import CustomerInfo from "@/Components/CustomerInfo";
import {
	CreditCardIcon,
	BanknotesIcon,
	ClockIcon,
	ServerStackIcon,
} from "@heroicons/react/16/solid";

const TABS = [
	{ key: "tokens", label: "Tokens" },
	{ key: "transactions", label: "Transacciones ADQ" },
	{ key: "attempts", label: "Intentos de pago" },
];

function statusBadgeColor(status) {
	if (!status) return "slate";
	if (["approved", "completed", "active"].includes(status)) return "famedic-lime";
	if (["declined", "error", "rejected", "timeout", "inactive"].includes(status)) {
		return "red";
	}
	return "amber";
}

export default function BanregioMonitor({
	tab,
	filters,
	summary,
	tokens,
	transactions,
	attempts,
	flowOptions = [],
	statusOptions = [],
}) {
	const { data, setData, get, processing } = useForm({
		tab: tab || "tokens",
		search: filters.search || "",
		status: filters.status || "",
		flow: filters.flow || "",
	});

	const [showFilters, setShowFilters] = useState(false);

	const showUpdateButton = useMemo(
		() =>
			(data.search || "") !== (filters.search || "") ||
			(data.status || "") !== (filters.status || "") ||
			(data.flow || "") !== (filters.flow || ""),
		[data, filters],
	);

	const filtersCount = useMemo(
		() => ["search", "status", "flow"].filter((key) => filters[key]).length,
		[filters],
	);

	const updateResults = (e) => {
		e?.preventDefault?.();
		if (!processing && showUpdateButton) {
			get(route("admin.banregio.index", { tab, ...data }), { preserveState: true });
		}
	};

	const switchTab = (nextTab) => {
		get(
			route("admin.banregio.index", {
				tab: nextTab,
				search: filters.search || undefined,
				status: filters.status || undefined,
				flow: filters.flow || undefined,
			}),
			{ preserveState: true, replace: true },
		);
	};

	const paginatedData = tab === "transactions" ? transactions : tab === "attempts" ? attempts : tokens;

	return (
		<AdminLayout title="Banregio / Hey Banco">
			<div className="space-y-6">
				<div className="space-y-2">
					<Heading>Banregio Colecto</Heading>
					<Text className="text-sm text-zinc-600 dark:text-zinc-400">
						Monitoreo de tokenización, cobros e intentos de pago con Hey Banco /
						Banregio.
					</Text>
				</div>

				<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
					<SummaryCard label="Tokens" value={summary.tokens_total} hint={`${summary.tokens_active} activos`} />
					<SummaryCard label="Transacciones ADQ" value={summary.transactions_total} />
					<SummaryCard label="Intentos de pago" value={summary.attempts_total} />
					<div className="rounded-xl border border-orange-200/80 bg-orange-50/60 p-4 dark:border-orange-900/40 dark:bg-orange-950/20">
						<Text className="text-xs font-medium uppercase tracking-wide text-orange-800 dark:text-orange-200">
							Entorno
						</Text>
						<div className="mt-2 flex flex-wrap items-center gap-2">
							<Badge color="orange">{summary.environment || "—"}</Badge>
							<Badge color="slate">Modo {summary.mode || "—"}</Badge>
							<Badge color={summary.enabled ? "famedic-lime" : "red"}>
								{summary.enabled ? "Habilitado" : "Deshabilitado"}
							</Badge>
						</div>
						{summary.adq_url && (
							<Text className="mt-2 break-all text-xs text-orange-900/80 dark:text-orange-100/80">
								<ServerStackIcon className="mr-1 inline size-3.5" />
								{summary.adq_url}
							</Text>
						)}
					</div>
				</div>

				<nav className="flex gap-2 overflow-x-auto rounded-xl border border-zinc-200 bg-white p-1 dark:border-zinc-700 dark:bg-zinc-900">
					{TABS.map((item) => (
						<button
							key={item.key}
							type="button"
							onClick={() => switchTab(item.key)}
							className={clsx(
								"shrink-0 rounded-lg px-4 py-2 text-sm font-medium transition",
								tab === item.key
									? "bg-famedic-dark text-white dark:bg-zinc-100 dark:text-zinc-900"
									: "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800",
							)}
						>
							{item.label}
						</button>
					))}
				</nav>

				<div className="flex flex-wrap items-center justify-between gap-4">
					<Subheading className="!mt-0">
						{TABS.find((item) => item.key === tab)?.label}
					</Subheading>
					<div className="flex flex-wrap items-center gap-3">
						<SearchInput
							value={data.search}
							onChange={(value) => setData("search", value)}
							placeholder="Buscar referencia, folio, tarjeta o cliente..."
						/>
						<Button outline type="button" onClick={() => setShowFilters((v) => !v)}>
							Filtros
							<FilterCountBadge count={filtersCount} />
						</Button>
						<Button disabled={processing || !showUpdateButton} onClick={updateResults}>
							Actualizar
						</Button>
					</div>
				</div>

				{showFilters && (
					<div className="grid gap-4 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm md:grid-cols-3 dark:border-zinc-700 dark:bg-zinc-900">
						<ListboxFilter
							label="Estatus"
							placeholder="Todos"
							value={data.status}
							onChange={(value) => setData("status", value)}
						>
							<ListboxOption value="">
								<ListboxLabel>Todos</ListboxLabel>
							</ListboxOption>
							{statusOptions.map((status) => (
								<ListboxOption key={status} value={status}>
									<ListboxLabel>{status}</ListboxLabel>
								</ListboxOption>
							))}
						</ListboxFilter>

						{tab === "transactions" && (
							<ListboxFilter
								label="Flujo"
								placeholder="Todos"
								value={data.flow}
								onChange={(value) => setData("flow", value)}
							>
								<ListboxOption value="">
									<ListboxLabel>Todos</ListboxLabel>
								</ListboxOption>
								{flowOptions.map((flow) => (
									<ListboxOption key={flow} value={flow}>
										<ListboxLabel>{flow}</ListboxLabel>
									</ListboxOption>
								))}
							</ListboxFilter>
						)}
					</div>
				)}

				{paginatedData && (
					<PaginatedTable paginatedData={paginatedData}>
						{tab === "tokens" && <TokensTable tokens={tokens} />}
						{tab === "transactions" && <TransactionsTable transactions={transactions} />}
						{tab === "attempts" && <AttemptsTable attempts={attempts} />}
					</PaginatedTable>
				)}
			</div>
		</AdminLayout>
	);
}

function SummaryCard({ label, value, hint }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<Text className="text-xs font-medium uppercase tracking-wide text-zinc-500">{label}</Text>
			<p className="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white">{value ?? 0}</p>
			{hint && <Text className="mt-1 text-xs text-zinc-500">{hint}</Text>}
		</div>
	);
}

function TokensTable({ tokens }) {
	return (
		<Table>
			<TableHead>
				<TableRow>
					<TableHeader>Alias / tarjeta</TableHeader>
					<TableHeader>Cliente</TableHeader>
					<TableHeader>Media ID</TableHeader>
					<TableHeader>Estatus</TableHeader>
					<TableHeader>Último uso</TableHeader>
					<TableHeader></TableHeader>
				</TableRow>
			</TableHead>
			<TableBody>
				{tokens.data.map((token) => (
					<TableRow key={token.id}>
						<TableCell>
							<div className="space-y-1">
								<div className="flex items-center gap-2">
									<CreditCardIcon className="size-4 text-zinc-400" />
									<span className="font-medium">{token.alias || "Sin alias"}</span>
								</div>
								<div className="text-xs text-zinc-500">
									{token.brand || "Tarjeta"} •••• {token.last4}
								</div>
							</div>
						</TableCell>
						<TableCell>
							{token.customer ? <CustomerInfo customer={token.customer} /> : "—"}
						</TableCell>
						<TableCell>
							<Code className="text-xs">{token.media_id || "—"}</Code>
						</TableCell>
						<TableCell>
							<StatusBadge
								isActive={token.status === "active" && !token.is_expired}
								activeText="Activo"
								inactiveText={token.is_expired ? "Vencido" : "Inactivo"}
								inactiveColor={token.is_expired ? "red" : "slate"}
							/>
						</TableCell>
						<TableCell className="text-xs text-zinc-500">
							{token.last_used_at || token.created_at || "—"}
						</TableCell>
						<TableCell>
							<Button
								href={route("admin.banregio.tokens.show", { paymentMethod: token.id })}
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
	);
}

function TransactionsTable({ transactions }) {
	return (
		<Table>
			<TableHead>
				<TableRow>
					<TableHeader>Referencia / folio</TableHeader>
					<TableHeader>Flujo</TableHeader>
					<TableHeader>Monto</TableHeader>
					<TableHeader>Estatus</TableHeader>
					<TableHeader>Respuesta Banregio</TableHeader>
					<TableHeader>Fecha</TableHeader>
					<TableHeader></TableHeader>
				</TableRow>
			</TableHead>
			<TableBody>
				{transactions.data.map((tx) => (
					<TableRow key={tx.id}>
						<TableCell>
							<div className="space-y-1 text-sm">
								<div>
									Ref: <Code>{tx.reference || "—"}</Code>
								</div>
								<div className="text-xs text-zinc-500">Folio: {tx.folio || "—"}</div>
							</div>
						</TableCell>
						<TableCell>
							<Badge color="slate">{tx.flow || "—"}</Badge>
						</TableCell>
						<TableCell>
							<span className="inline-flex items-center gap-1">
								<BanknotesIcon className="size-4 text-zinc-400" />
								{tx.amount} {tx.currency}
							</span>
						</TableCell>
						<TableCell>
							<Badge color={statusBadgeColor(tx.status)}>{tx.status}</Badge>
						</TableCell>
						<TableCell className="max-w-xs truncate text-xs text-zinc-600">
							{tx.bnrg_texto || tx.bnrg_codigo_rechazo || "—"}
						</TableCell>
						<TableCell className="text-xs text-zinc-500">{tx.created_at}</TableCell>
						<TableCell>
							<Button
								href={route("admin.banregio.transactions.show", {
									paymentTransaction: tx.id,
								})}
								outline
								size="sm"
							>
								Logs
							</Button>
						</TableCell>
					</TableRow>
				))}
			</TableBody>
		</Table>
	);
}

function AttemptsTable({ attempts }) {
	return (
		<Table>
			<TableHead>
				<TableRow>
					<TableHeader>Referencia</TableHeader>
					<TableHeader>Cliente</TableHeader>
					<TableHeader>Monto</TableHeader>
					<TableHeader>Estatus</TableHeader>
					<TableHeader>Procesador</TableHeader>
					<TableHeader>Fecha</TableHeader>
					<TableHeader></TableHeader>
				</TableRow>
			</TableHead>
			<TableBody>
				{attempts.data.map((attempt) => (
					<TableRow key={attempt.id}>
						<TableCell>
							<div className="space-y-1 text-sm">
								<Code>{attempt.reference || "—"}</Code>
								<div className="text-xs text-zinc-500">
									ID proc: {attempt.processor_transaction_id || "—"}
								</div>
							</div>
						</TableCell>
						<TableCell>
							{attempt.customer ? <CustomerInfo customer={attempt.customer} /> : "—"}
						</TableCell>
						<TableCell>${((attempt.amount_cents || 0) / 100).toFixed(2)}</TableCell>
						<TableCell>
							<Badge color={statusBadgeColor(attempt.status)}>{attempt.status}</Badge>
						</TableCell>
						<TableCell className="max-w-xs truncate text-xs">
							{attempt.processor_message || attempt.processor_code || "—"}
						</TableCell>
						<TableCell className="text-xs text-zinc-500">
							<span className="inline-flex items-center gap-1">
								<ClockIcon className="size-4" />
								{attempt.processed_at || attempt.created_at}
							</span>
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
	);
}
