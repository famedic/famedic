import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong, Code } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import CustomerInfo from "@/Components/CustomerInfo";
import JsonBlock from "@/Components/Admin/JsonBlock";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { ArrowLeftIcon } from "@heroicons/react/16/solid";

export default function BanregioTransaction({ transaction, relatedAttempts, config }) {
	return (
		<AdminLayout title={`Transacción Banregio ${transaction.id}`}>
			<div className="space-y-6">
				<div className="flex flex-wrap items-center justify-between gap-4">
					<div className="space-y-2">
						<Heading>Transacción ADQ #{transaction.id}</Heading>
						<Text className="text-sm text-zinc-600 dark:text-zinc-300">
							Referencia <Code>{transaction.reference || "—"}</Code> · Folio{" "}
							{transaction.folio || "—"}
						</Text>
					</div>
					<Button
						outline
						href={route("admin.banregio.index", { tab: "transactions" })}
					>
						<ArrowLeftIcon className="size-4" />
						Volver
					</Button>
				</div>

				<div className="grid gap-4 md:grid-cols-2">
					<Panel title="Resumen">
						<InfoRow label="Flujo" value={<Badge color="slate">{transaction.flow}</Badge>} />
						<InfoRow label="Estatus" value={transaction.status} />
						<InfoRow label="Monto" value={`${transaction.amount} ${transaction.currency}`} />
						<InfoRow label="Modo" value={transaction.mode} />
						<InfoRow label="Autorización" value={transaction.auth_code} />
						<InfoRow label="Referencia previa" value={transaction.previous_reference} />
						<InfoRow label="Creado" value={transaction.created_at} />
					</Panel>

					<Panel title="Cliente y método">
						{transaction.customer ? (
							<CustomerInfo customer={transaction.customer} />
						) : (
							<Text>Sin cliente.</Text>
						)}
						{transaction.payment_method && (
							<div className="mt-4 space-y-1 text-sm">
								<Text>
									<Strong>Tarjeta:</Strong> {transaction.payment_method.brand} ••••{" "}
									{transaction.payment_method.last4}
								</Text>
								<Button
									outline
									size="sm"
									className="mt-2"
									href={route("admin.banregio.tokens.show", {
										paymentMethod: transaction.payment_method.id,
									})}
								>
									Ver token
								</Button>
							</div>
						)}
						<div className="mt-4 space-y-1 text-sm">
							<Text>
								<Strong>Entorno:</Strong>{" "}
								<Badge color="orange">{config.environment}</Badge>
							</Text>
							<Text>
								<Strong>Endpoint ADQ:</Strong> {config.adq_url}
							</Text>
						</div>
					</Panel>
				</div>

				<Panel title="Campos Banregio (BNRG)">
					<div className="grid gap-2 text-sm md:grid-cols-2">
						<InfoRow label="Código proc" value={transaction.bnrg_codigo_proc} />
						<InfoRow label="Código proc trans" value={transaction.bnrg_codigo_proc_trans} />
						<InfoRow label="Código rechazo" value={transaction.bnrg_codigo_rechazo} />
						<InfoRow label="Código emisor" value={transaction.bnrg_codigo_emisor} />
						<InfoRow label="Estado trans" value={transaction.bnrg_estado_trans} />
						<InfoRow label="Tipo trans" value={transaction.bnrg_tipo_trans} />
					</div>
					<Text className="mt-3">
						<Strong>Texto Banregio:</Strong> {transaction.bnrg_texto || "—"}
					</Text>
				</Panel>

				<Panel title="Comunicación con Banregio">
					<JsonBlock title="Request enviado (raw_request)" data={transaction.raw_request} />
					<div className="mt-4">
						<JsonBlock
							title="Headers de respuesta (raw_response_headers)"
							data={transaction.raw_response_headers}
						/>
					</div>
				</Panel>

				<Panel title="Intentos de pago relacionados">
					{relatedAttempts?.length ? (
						<Table>
							<TableHead>
								<TableRow>
									<TableHeader>ID</TableHeader>
									<TableHeader>Referencia</TableHeader>
									<TableHeader>Estatus</TableHeader>
									<TableHeader>Mensaje</TableHeader>
									<TableHeader></TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{relatedAttempts.map((attempt) => (
									<TableRow key={attempt.id}>
										<TableCell>{attempt.id}</TableCell>
										<TableCell>{attempt.reference || "—"}</TableCell>
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
						<Text>No hay intentos de pago vinculados por referencia.</Text>
					)}
				</Panel>
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
