import { Link, router } from "@inertiajs/react";
import { useState } from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";

const subTypeLabel = {
	trial: "Trial",
	regular: "Regular",
	institutional: "Institucional Odessa",
	family_member: "Miembro familiar",
};

export default function MurguiaMonitorShow({ customer, syncLogs, murguiaCheck }) {
	const [checking, setChecking] = useState(false);

	const postCheck = () => {
		setChecking(true);
		router.post(
			route("admin.murguia-monitor.check-status", customer.id),
			{},
			{ onFinish: () => setChecking(false) },
		);
	};

	const displayCheck = murguiaCheck;

	return (
		<AdminLayout title={`Murguía — ${customer.name}`}>
			<div className="space-y-6 text-zinc-900 dark:text-zinc-100">
				<div className="flex flex-wrap items-center justify-between gap-4">
					<div>
						<Link
							href={route("admin.murguia-monitor.index")}
							className="text-sm text-blue-600 hover:underline dark:text-blue-400"
						>
							← Volver al monitor
						</Link>
						<Heading className="mt-2">{customer.name}</Heading>
						<Text className="text-zinc-600 dark:text-zinc-400">{customer.email}</Text>
					</div>
					<Button onClick={postCheck} disabled={checking}>
						{checking ? "Consultando…" : "Consultar estatus Murguía"}
					</Button>
				</div>

				<div className="grid gap-4 sm:grid-cols-2">
					<div className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-xs text-zinc-500">noCredito</Text>
						<p className="font-mono text-lg text-zinc-900 dark:text-zinc-100">
							{customer.medical_attention_identifier || "—"}
						</p>
					</div>
					<div className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-xs text-zinc-500">Estado local</Text>
						<Badge color={customer.local_status === "active" ? "lime" : "zinc"}>
							{customer.local_status === "active" ? "Activo" : "Inactivo"}
						</Badge>
					</div>
					<div className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-xs text-zinc-500">Tipo cuenta</Text>
						<p className="capitalize text-zinc-900 dark:text-zinc-100">{customer.account_type}</p>
					</div>
					<div className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-xs text-zinc-500">Vigencia</Text>
						<p>
							{customer.expires_at
								? new Date(customer.expires_at).toLocaleString("es-MX")
								: "—"}
						</p>
					</div>
				</div>

				{displayCheck && (
					<div className="rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950/30">
						<Text className="font-semibold text-blue-900 dark:text-blue-200">
							Respuesta Murguía (HTTP {displayCheck.http})
						</Text>
						<pre className="mt-2 max-h-64 overflow-auto rounded bg-white/80 p-3 text-xs text-zinc-800 dark:bg-zinc-900 dark:text-zinc-200">
							{JSON.stringify(displayCheck.body, null, 2)}
						</pre>
					</div>
				)}

				<div>
					<Heading level={3} className="mb-2 text-lg">
						Suscripciones
					</Heading>
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>ID</TableHeader>
								<TableHeader>Tipo</TableHeader>
								<TableHeader>Inicio</TableHeader>
								<TableHeader>Fin</TableHeader>
								<TableHeader>Sync Murguía</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{customer.subscriptions.length === 0 ? (
								<TableRow>
									<TableCell colSpan={5}>
										<Text className="text-zinc-500">Sin suscripciones.</Text>
									</TableCell>
								</TableRow>
							) : (
								customer.subscriptions.map((s) => (
									<TableRow key={s.id}>
										<TableCell>{s.id}</TableCell>
										<TableCell>
											{subTypeLabel[s.type] || s.type}
										</TableCell>
										<TableCell>{s.start_date}</TableCell>
										<TableCell>{s.end_date}</TableCell>
										<TableCell>
											{s.synced_with_murguia_at
												? new Date(s.synced_with_murguia_at).toLocaleString("es-MX")
												: "—"}
										</TableCell>
									</TableRow>
								))
							)}
						</TableBody>
					</Table>
				</div>

				<div>
					<Heading level={3} className="mb-2 text-lg">
						Últimos logs (este cliente)
					</Heading>
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>Fecha</TableHeader>
								<TableHeader>Origen</TableHeader>
								<TableHeader>Admin</TableHeader>
								<TableHeader>Acción</TableHeader>
								<TableHeader>Estado</TableHeader>
								<TableHeader>Mensaje</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{syncLogs.length === 0 ? (
								<TableRow>
									<TableCell colSpan={6}>
										<Text className="text-zinc-500">Sin registros.</Text>
									</TableCell>
								</TableRow>
							) : (
								syncLogs.map((log) => (
									<TableRow key={log.id}>
										<TableCell>
											{new Date(log.created_at).toLocaleString("es-MX")}
										</TableCell>
										<TableCell>
											{log.entry_type === "single" ? "Individual" : "Masivo"}
										</TableCell>
										<TableCell className="max-w-[140px] truncate text-sm">
											{log.admin_email || "—"}
										</TableCell>
										<TableCell>{log.action}</TableCell>
										<TableCell>{log.status}</TableCell>
										<TableCell className="max-w-md truncate">{log.message}</TableCell>
									</TableRow>
								))
							)}
						</TableBody>
					</Table>
				</div>
			</div>
		</AdminLayout>
	);
}
