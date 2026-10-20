import { Link } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text, Code } from "@/Components/Catalyst/text";
import {
	DescriptionList,
	DescriptionTerm,
	DescriptionDetails,
} from "@/Components/Catalyst/description-list";

export default function LaboratoryGdaFailureLog({ log }) {
	return (
		<AdminLayout title={`Error GDA #${log.id}`}>
			<div className="space-y-8">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div>
						<Heading>Error GDA #{log.id}</Heading>
						<Text className="mt-1">{log.created_at}</Text>
					</div>
					<Button
						href={route("admin.laboratory-gda-failure-logs.index")}
						outline
					>
						Volver al listado
					</Button>
				</div>

				<div>
					<Subheading>Resumen</Subheading>
					<DescriptionList className="mt-4">
						<DescriptionTerm>Operación</DescriptionTerm>
						<DescriptionDetails>
							<Badge>{log.operation_label}</Badge>
						</DescriptionDetails>

						<DescriptionTerm>Mensaje</DescriptionTerm>
						<DescriptionDetails>{log.message}</DescriptionDetails>

						<DescriptionTerm>Razón interna</DescriptionTerm>
						<DescriptionDetails>
							<Code>{log.failure_reason}</Code>
						</DescriptionDetails>

						{log.gda_description && (
							<>
								<DescriptionTerm>Detalle GDA</DescriptionTerm>
								<DescriptionDetails>{log.gda_description}</DescriptionDetails>
							</>
						)}

						{log.gda_mensaje && (
							<>
								<DescriptionTerm>Mensaje GDA</DescriptionTerm>
								<DescriptionDetails>{log.gda_mensaje}</DescriptionDetails>
							</>
						)}

						{log.gda_code_http && (
							<>
								<DescriptionTerm>codeHttp GDA</DescriptionTerm>
								<DescriptionDetails>{log.gda_code_http}</DescriptionDetails>
							</>
						)}

						{log.http_status && (
							<>
								<DescriptionTerm>HTTP externo</DescriptionTerm>
								<DescriptionDetails>{log.http_status}</DescriptionDetails>
							</>
						)}

						{log.requisition_value && (
							<>
								<DescriptionTerm>Requisición enviada</DescriptionTerm>
								<DescriptionDetails>{log.requisition_value}</DescriptionDetails>
							</>
						)}

						{log.brand && (
							<>
								<DescriptionTerm>Marca</DescriptionTerm>
								<DescriptionDetails>{log.brand}</DescriptionDetails>
							</>
						)}

						{log.administrator_name && (
							<>
								<DescriptionTerm>Administrador</DescriptionTerm>
								<DescriptionDetails>{log.administrator_name}</DescriptionDetails>
							</>
						)}
					</DescriptionList>
				</div>

				<div>
					<Subheading>Pedido y cliente</Subheading>
					<DescriptionList className="mt-4">
						{log.laboratory_purchase_id && (
							<>
								<DescriptionTerm>Pedido</DescriptionTerm>
								<DescriptionDetails>
									<Link
										href={route(
											"admin.laboratory-purchases.show",
											log.laboratory_purchase_id,
										)}
										className="font-medium underline"
									>
										#{log.laboratory_purchase_id}
									</Link>
								</DescriptionDetails>
							</>
						)}

						{log.source_laboratory_purchase_id && (
							<>
								<DescriptionTerm>Pedido origen</DescriptionTerm>
								<DescriptionDetails>
									<Link
										href={route(
											"admin.laboratory-purchases.show",
											log.source_laboratory_purchase_id,
										)}
										className="font-medium underline"
									>
										#{log.source_laboratory_purchase_id}
									</Link>
								</DescriptionDetails>
							</>
						)}

						{log.customer && (
							<>
								<DescriptionTerm>Cliente</DescriptionTerm>
								<DescriptionDetails>
									{log.customer.full_name} · {log.customer.email}
								</DescriptionDetails>
							</>
						)}
					</DescriptionList>
				</div>

				{(log.purchase_items ?? []).length > 0 && (
					<div>
						<Subheading>Estudios</Subheading>
						<ul className="mt-4 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
							{log.purchase_items.map((item) => (
								<li
									key={`${item.gda_id}-${item.name}`}
									className="flex items-center justify-between gap-4 px-4 py-2 text-sm"
								>
									<span>
										{item.name}{" "}
										<span className="text-zinc-500">({item.gda_id})</span>
									</span>
									<span>{item.formatted_price ?? "—"}</span>
								</li>
							))}
						</ul>
					</div>
				)}

				{log.response_summary && (
					<div>
						<Subheading>Resumen de respuesta GDA</Subheading>
						<pre className="mt-4 overflow-x-auto rounded-lg bg-zinc-950 p-4 text-xs text-zinc-100">
							{JSON.stringify(log.response_summary, null, 2)}
						</pre>
					</div>
				)}

				{log.context && (
					<div>
						<Subheading>Contexto técnico</Subheading>
						<pre className="mt-4 overflow-x-auto rounded-lg bg-zinc-950 p-4 text-xs text-zinc-100">
							{JSON.stringify(log.context, null, 2)}
						</pre>
					</div>
				)}
			</div>
		</AdminLayout>
	);
}
