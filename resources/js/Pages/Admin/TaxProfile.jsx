import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong, Code } from "@/Components/Catalyst/text";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import EmptyListCard from "@/Components/EmptyListCard";
import CustomerInfo from "@/Components/CustomerInfo";
import { Badge } from "@/Components/Catalyst/badge";
import {
	CheckCircleIcon,
	XCircleIcon,
	DocumentArrowDownIcon,
} from "@heroicons/react/16/solid";

export default function TaxProfilePage({ customer }) {
	const profiles = customer.tax_profiles || [];

	return (
		<AdminLayout title={`Perfiles fiscales - ${customer.user?.full_name || ""}`}>
			<div className="space-y-6">
				<div className="space-y-2">
					<Heading>Perfiles fiscales</Heading>
					<CustomerInfo customer={customer} />
				</div>

				<div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<Subheading>Resumen</Subheading>
					<Text className="text-sm">
						Este usuario tiene{" "}
						<Strong>{profiles.length}</Strong> perfil
						{profiles.length === 1 ? "" : "es"} fiscal
					</Text>
				</div>

				<div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<Subheading>Detalle de perfiles fiscales</Subheading>
					{profiles.length === 0 ? (
						<EmptyListCard />
					) : (
						<Table>
							<TableHead>
								<TableRow>
									<TableHeader>Razón social</TableHeader>
									<TableHeader>RFC</TableHeader>
									<TableHeader>Régimen fiscal</TableHeader>
									<TableHeader>Uso CFDI</TableHeader>
									<TableHeader>Código postal</TableHeader>
									<TableHeader>Estatus SAT</TableHeader>
									<TableHeader>Verificación</TableHeader>
									<TableHeader>Constancia</TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{profiles.map((profile) => (
									<TableRow key={profile.id}>
										<TableCell>
											<Text className="text-sm font-medium">
												{profile.razon_social || profile.name}
											</Text>
										</TableCell>
										<TableCell>
											<Code>{profile.rfc}</Code>
										</TableCell>
										<TableCell>
											<Text className="text-xs">
												{profile.formatted_tax_regime ||
													profile.tax_regime}
											</Text>
										</TableCell>
										<TableCell>
											<Text className="text-xs">
												{profile.formatted_cfdi_use || profile.cfdi_use}
											</Text>
										</TableCell>
										<TableCell>
											<Text className="text-sm">
												{profile.zipcode ||
													profile.codigo_postal_original}
											</Text>
										</TableCell>
										<TableCell>
											<Text className="text-sm">
												{profile.estatus_sat || "—"}
											</Text>
										</TableCell>
										<TableCell>
											<Badge
												color={
													profile.verificado_automaticamente
														? "famedic-lime"
														: "slate"
												}
											>
												{profile.verificado_automaticamente ? (
													<CheckCircleIcon className="size-4" />
												) : (
													<XCircleIcon className="size-4" />
												)}
												{profile.verificado_automaticamente
													? "Verificado"
													: "No verificado"}
											</Badge>
										</TableCell>
										<TableCell>
											{profile.fiscal_certificate ? (
												<a
													href={profile.certificate_url}
													target="_blank"
													rel="noreferrer"
													className="inline-flex items-center gap-1 text-xs text-sky-600 hover:underline"
												>
													<DocumentArrowDownIcon className="size-4" />
													Descargar
												</a>
											) : (
												<Text className="text-xs text-zinc-400">
													Sin archivo
												</Text>
											)}
										</TableCell>
									</TableRow>
								))}
							</TableBody>
						</Table>
					)}
				</div>
			</div>
		</AdminLayout>
	);
}

