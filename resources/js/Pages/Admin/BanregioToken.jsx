import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong, Code } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import CustomerInfo from "@/Components/CustomerInfo";
import JsonBlock from "@/Components/Admin/JsonBlock";
import StatusBadge from "@/Components/StatusBadge";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { ArrowLeftIcon, CreditCardIcon } from "@heroicons/react/16/solid";

export default function BanregioToken({ token, attempts, config }) {
	return (
		<AdminLayout title={`Token Banregio ${token.alias || token.id}`}>
			<div className="space-y-6">
				<div className="flex flex-wrap items-center justify-between gap-4">
					<div className="space-y-2">
						<Heading>Token {token.alias || `#${token.id}`}</Heading>
						<div className="flex flex-wrap items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
							<CreditCardIcon className="size-4" />
							<span>
								{token.brand || "Tarjeta"} •••• {token.last4}
							</span>
							<span>·</span>
							<span>{token.card_holder || "Sin titular"}</span>
						</div>
					</div>
					<Button outline href={route("admin.banregio.index", { tab: "tokens" })}>
						<ArrowLeftIcon className="size-4" />
						Volver
					</Button>
				</div>

				<div className="grid gap-4 md:grid-cols-2">
					<Panel title="Detalles del token">
						<InfoRow label="ID" value={token.id} />
						<InfoRow label="Alias" value={token.alias || "Sin alias"} />
						<InfoRow label="Proveedor" value={token.provider} />
						<InfoRow
							label="Estatus"
							value={
								<StatusBadge
									isActive={token.status === "active" && !token.is_expired}
									activeText="Activo"
									inactiveText={token.is_expired ? "Vencido" : "Inactivo"}
									inactiveColor={token.is_expired ? "red" : "slate"}
								/>
							}
						/>
						<InfoRow label="Expiración tarjeta" value={`${token.exp_month}/${token.exp_year}`} />
						<InfoRow label="Afiliación" value={token.affiliation_id} />
						<InfoRow label="Media ID" value={token.media_id} />
						<InfoRow label="Último uso" value={token.last_used_at || "—"} />
						<InfoRow label="Creado" value={token.created_at} />
					</Panel>

					<Panel title="Cliente">
						{token.customer ? <CustomerInfo customer={token.customer} /> : <Text>Sin cliente.</Text>}
						<div className="mt-4 space-y-1 text-sm">
							<Text>
								<Strong>Entorno:</Strong>{" "}
								<Badge color="orange">{config.environment}</Badge>
							</Text>
							<Text>
								<Strong>Modo ADQ:</Strong> {config.mode}
							</Text>
						</div>
					</Panel>
				</div>

				<Panel title="Identificadores">
					<Text>
						<Strong>Provider token:</Strong>{" "}
						<Code>{token.masked_provider_token || "—"}</Code>
					</Text>
					{token.created_from_transaction_id && (
						<div className="mt-3">
							<Button
								outline
								size="sm"
								href={route("admin.banregio.transactions.show", {
									paymentTransaction: token.created_from_transaction_id,
								})}
							>
								Ver transacción de tokenización
							</Button>
						</div>
					)}
				</Panel>

				<Panel title="Transacciones ADQ del token">
					{token.transactions?.length ? (
						<Table>
							<TableHead>
								<TableRow>
									<TableHeader>Referencia</TableHeader>
									<TableHeader>Flujo</TableHeader>
									<TableHeader>Monto</TableHeader>
									<TableHeader>Estatus</TableHeader>
									<TableHeader></TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{token.transactions.map((tx) => (
									<TableRow key={tx.id}>
										<TableCell>{tx.reference || "—"}</TableCell>
										<TableCell>{tx.flow}</TableCell>
										<TableCell>
											{tx.amount} {tx.currency}
										</TableCell>
										<TableCell>{tx.status}</TableCell>
										<TableCell>
											<Button
												outline
												size="sm"
												href={route("admin.banregio.transactions.show", {
													paymentTransaction: tx.id,
												})}
											>
												Logs
											</Button>
										</TableCell>
									</TableRow>
								))}
							</TableBody>
						</Table>
					) : (
						<Text>Sin transacciones registradas.</Text>
					)}
				</Panel>

				<Panel title="Intentos de pago con este token">
					{attempts?.length ? (
						<Table>
							<TableHead>
								<TableRow>
									<TableHeader>Referencia</TableHeader>
									<TableHeader>Monto</TableHeader>
									<TableHeader>Estatus</TableHeader>
									<TableHeader>Mensaje</TableHeader>
									<TableHeader></TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{attempts.map((attempt) => (
									<TableRow key={attempt.id}>
										<TableCell>{attempt.reference || "—"}</TableCell>
										<TableCell>${((attempt.amount_cents || 0) / 100).toFixed(2)}</TableCell>
										<TableCell>{attempt.status}</TableCell>
										<TableCell className="max-w-xs truncate text-xs">
											{attempt.processor_message || "—"}
										</TableCell>
										<TableCell>
											<Button
												outline
												size="sm"
												href={route("admin.payment-attempts.show", {
													payment_attempt: attempt.id,
												})}
											>
												Detalle
											</Button>
										</TableCell>
									</TableRow>
								))}
							</TableBody>
						</Table>
					) : (
						<Text>Sin intentos de pago.</Text>
					)}
				</Panel>

				{token.created_from_transaction && (
					<Panel title="Log de tokenización inicial">
						<JsonBlock title="Request ADQ" data={token.created_from_transaction.raw_request} />
						<div className="mt-4">
							<JsonBlock
								title="Headers de respuesta"
								data={token.created_from_transaction.raw_response_headers}
							/>
						</div>
					</Panel>
				)}
			</div>
		</AdminLayout>
	);
}

function Panel({ title, children }) {
	return (
		<section className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<Subheading>{title}</Subheading>
			{children}
		</section>
	);
}

function InfoRow({ label, value }) {
	return (
		<Text>
			<Strong>{label}:</Strong> {value ?? "—"}
		</Text>
	);
}
