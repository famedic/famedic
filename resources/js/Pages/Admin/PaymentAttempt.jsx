import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong, Code } from "@/Components/Catalyst/text";
import CustomerInfo from "@/Components/CustomerInfo";
import { Badge } from "@/Components/Catalyst/badge";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import {
	BanknotesIcon,
	CreditCardIcon,
	ClockIcon,
	InformationCircleIcon,
} from "@heroicons/react/16/solid";

export default function PaymentAttemptPage({ attempt }) {
	return (
		<AdminLayout title={`Intento de pago ${attempt.id}`}>
			<div className="space-y-6">
				<div className="space-y-2">
					<Heading>Intento de pago #{attempt.id}</Heading>
					{attempt.customer && (
						<div className="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
							<Subheading>Cliente</Subheading>
							<CustomerInfo customer={attempt.customer} />
						</div>
					)}
				</div>

				<div className="grid gap-4 md:grid-cols-2">
					<div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<Subheading>Resumen del intento</Subheading>
						<div className="space-y-1 text-sm">
							<Text>
								<Strong>Monto:</Strong>{" "}
								<span className="inline-flex items-center gap-1">
									<BanknotesIcon className="size-4 text-zinc-400" />
									${(attempt.amount_cents / 100).toFixed(2)}
								</span>
							</Text>
							<Text>
								<Strong>Gateway:</Strong>{" "}
								<span className="inline-flex items-center gap-1">
									<CreditCardIcon className="size-4 text-zinc-400" />
									{attempt.gateway || "N/D"}
								</span>
							</Text>
							<Text>
								<Strong>Estatus:</Strong>{" "}
								<Badge
									color={
										attempt.status === "approved"
											? "famedic-lime"
											: attempt.status === "error" ||
											  attempt.status === "declined"
											? "red"
											: "slate"
									}
								>
									{attempt.status || "N/D"}
								</Badge>
							</Text>
							<Text>
								<Strong>Procesado:</Strong>{" "}
								<span className="inline-flex items-center gap-1">
									<ClockIcon className="size-4 text-zinc-400" />
									{attempt.processed_at || attempt.created_at}
								</span>
							</Text>
							<Text>
								<Strong>Reintentos:</Strong> {attempt.retry_count ?? 0}
							</Text>
						</div>
					</div>

					<div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<Subheading>Identificadores</Subheading>
						<div className="space-y-1 text-sm">
							<Text>
								<Strong>Referencia interna:</Strong>{" "}
								<Code>{attempt.reference || "—"}</Code>
							</Text>
							<Text>
								<Strong>ID token / método:</Strong>{" "}
								<Code>{attempt.token_id || "—"}</Code>
							</Text>
							<Text>
								<Strong>ID transacción procesador:</Strong>{" "}
								<Code>
									{attempt.processor_transaction_id || "—"}
								</Code>
							</Text>
						</div>
					</div>
				</div>

				<div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<Subheading>Respuesta del procesador</Subheading>
					<div className="space-y-2 text-sm">
						<Text>
							<Strong>Código:</Strong>{" "}
							{attempt.processor_code || "—"}
						</Text>
						<Text>
							<Strong>Mensaje:</Strong>{" "}
							{attempt.processor_message || "—"}
						</Text>
					</div>

					{attempt.raw_response && (
						<div className="space-y-2">
							<div className="flex items-center gap-1 text-xs text-zinc-500">
								<InformationCircleIcon className="size-4" />
								<Text className="text-xs">
									Payload completo retornado por el procesador:
								</Text>
							</div>
							<pre className="max-h-96 overflow-auto rounded-lg bg-zinc-900 p-4 text-xs text-zinc-100">
								{JSON.stringify(attempt.raw_response, null, 2)}
							</pre>
						</div>
					)}
				</div>
			</div>
		</AdminLayout>
	);
}

