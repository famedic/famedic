import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text, Strong, Code } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import CustomerInfo from "@/Components/CustomerInfo";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import StatusBadge from "@/Components/StatusBadge";
import {
	ArrowLeftIcon,
	CreditCardIcon,
	GlobeAltIcon,
} from "@heroicons/react/16/solid";

export default function EfevooToken({ token }) {
	return (
		<AdminLayout title={`Token ${token.alias || token.id}`}>
			<div className="space-y-6">
				<div className="flex items-center justify-between gap-4">
					<div className="space-y-2">
						<Heading>
							Token {token.alias || `#${token.id}`}
						</Heading>
						<div className="flex flex-wrap items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
							<CreditCardIcon className="size-4" />
							<span>
								{token.card_brand || "Tarjeta"} ••••{" "}
								{token.card_last_four}
							</span>
							<span>·</span>
							<span>{token.card_holder}</span>
						</div>
					</div>
					<Button
						outline
						href={route("admin.efevoo-tokens.index")}
						className="max-md:w-full"
					>
						<ArrowLeftIcon className="size-4" />
						Volver a lista
					</Button>
				</div>

				<div className="grid gap-4 md:grid-cols-2">
					<div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<Heading level={3} className="text-base">
							Detalles del token
						</Heading>
						<div className="space-y-1 text-sm">
							<Text>
								<Strong>ID:</Strong> {token.id}
							</Text>
							<Text>
								<Strong>Alias:</Strong>{" "}
								{token.alias || "Sin alias"}
							</Text>
							<Text>
								<Strong>Entorno:</Strong>{" "}
								<Badge
									color={
										token.environment === "production"
											? "emerald"
											: "slate"
									}
								>
									<GlobeAltIcon className="size-4" />
									{token.formatted_environment}
								</Badge>
							</Text>
							<Text>
								<Strong>Estatus:</Strong>{" "}
								<StatusBadge
									isActive={token.is_active && !token.is_expired}
									activeText="Activo"
									inactiveText={
										token.is_expired ? "Vencido" : "Inactivo"
									}
									inactiveColor={
										token.is_expired ? "red" : "slate"
									}
								/>
							</Text>
							<Text>
								<Strong>Expiración:</Strong>{" "}
								{token.expires_at ? (
									token.formatted_expiration ||
									token.expires_at
								) : (
									"Sin expiración"
								)}
							</Text>
							<Text>
								<Strong>Creado:</Strong>{" "}
								{token.created_at || "—"}
							</Text>
							<Text>
								<Strong>Actualizado:</Strong>{" "}
								{token.updated_at || "—"}
							</Text>
						</div>
					</div>

					<div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<Heading level={3} className="text-base">
							Cliente
						</Heading>
						{token.customer ? (
							<CustomerInfo customer={token.customer} />
						) : (
							<Text>Sin cliente asociado.</Text>
						)}
					</div>
				</div>

				<div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<Heading level={3} className="text-base">
						Últimas transacciones
					</Heading>

					{token.transactions && token.transactions.length > 0 ? (
						<Table>
							<TableHead>
								<TableRow>
									<TableHeader>Referencia</TableHeader>
									<TableHeader>Monto</TableHeader>
									<TableHeader>Estatus</TableHeader>
									<TableHeader>Tipo</TableHeader>
									<TableHeader>Fecha</TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{token.transactions.map((tx) => (
									<TableRow key={tx.id}>
										<TableCell>{tx.reference}</TableCell>
										<TableCell>
											{tx.amount} {tx.currency}
										</TableCell>
										<TableCell>{tx.status}</TableCell>
										<TableCell>{tx.transaction_type}</TableCell>
										<TableCell>
											{tx.processed_at || tx.created_at}
										</TableCell>
									</TableRow>
								))}
							</TableBody>
						</Table>
					) : (
						<Text>No hay transacciones registradas para este token.</Text>
					)}
				</div>

				<div className="space-y-2 rounded-xl border border-zinc-200 bg-white p-4 text-sm shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<Heading level={3} className="text-base">
						Identificadores
					</Heading>
					<Text>
						<Strong>Client token:</Strong>{" "}
						<Code>{token.client_token}</Code>
					</Text>
					<Text>
						<Strong>Card token:</Strong>{" "}
						<Code>{token.card_token}</Code>
					</Text>
				</div>
			</div>
		</AdminLayout>
	);
}

