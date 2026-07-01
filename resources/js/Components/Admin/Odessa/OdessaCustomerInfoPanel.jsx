import { router } from "@inertiajs/react";
import { useState } from "react";
import Card from "@/Components/Card";
import { Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
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
import OdessaLogo from "@/Components/OdessaLogo";

const ODESSA_ACCOUNT_TYPE = "App\\Models\\OdessaAfiliateAccount";

const SYNCED_FIELD_LABELS = {
	client_id: "Cliente ID",
	empresa: "Empresa",
	nombre: "Nombre",
	planta_id: "Planta ID",
	partner_identifier: "Socio ID",
};

function formatValue(value) {
	if (value === null || value === undefined || value === "") {
		return "—";
	}

	return String(value);
}

function CurrentValuesTable({ account }) {
	const rows = Object.entries(SYNCED_FIELD_LABELS).map(([key, label]) => ({
		key,
		label,
		value: account?.[key],
	}));

	return (
		<Table className="[--gutter:theme(spacing.4)]">
			<TableHead>
				<TableRow>
					<TableHeader>Campo</TableHeader>
					<TableHeader>Valor en Famedic</TableHeader>
				</TableRow>
			</TableHead>
			<TableBody>
				{rows.map((row) => (
					<TableRow key={row.key}>
						<TableCell>
							<Text>{row.label}</Text>
						</TableCell>
						<TableCell>
							<Text className="font-mono text-sm">
								{formatValue(row.value)}
							</Text>
						</TableCell>
					</TableRow>
				))}
			</TableBody>
		</Table>
	);
}

function PreviewDiffTable({ diff }) {
	if (!diff?.length) {
		return null;
	}

	return (
		<div className="space-y-2">
			<Text>
				<Strong>Comparación con Odessa</Strong>
			</Text>
			<Table className="[--gutter:theme(spacing.4)]">
				<TableHead>
					<TableRow>
						<TableHeader>Campo</TableHeader>
						<TableHeader>Valor actual</TableHeader>
						<TableHeader>Valor Odessa</TableHeader>
						<TableHeader>Estado</TableHeader>
					</TableRow>
				</TableHead>
				<TableBody>
					{diff.map((row) => (
						<TableRow key={row.attribute}>
							<TableCell>
								<Text>{row.label}</Text>
							</TableCell>
							<TableCell>
								<Text className="font-mono text-sm">
									{formatValue(row.current)}
								</Text>
							</TableCell>
							<TableCell>
								<Text className="font-mono text-sm">
									{formatValue(row.remote)}
								</Text>
							</TableCell>
							<TableCell>
								{row.status === "update" ? (
									<Badge color="amber">actualizar</Badge>
								) : (
									<Badge color="zinc">sin cambio</Badge>
								)}
							</TableCell>
						</TableRow>
					))}
				</TableBody>
			</Table>
		</div>
	);
}

function OdessaLinkValidation({ userData }) {
	if (!userData) {
		return null;
	}

	return (
		<div className="grid gap-3 sm:grid-cols-2">
			<div className="rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-900/50">
				<Text className="text-xs text-zinc-500">IdOdessa (Odessa)</Text>
				<p className="font-mono text-sm">{formatValue(userData.idOdessa)}</p>
			</div>
			<div className="rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-900/50">
				<Text className="text-xs text-zinc-500">IdExterno (Famedic)</Text>
				<p className="font-mono text-sm">{formatValue(userData.idExterno)}</p>
			</div>
		</div>
	);
}

export default function OdessaCustomerInfoPanel({
	customer,
	odessaSyncPreview,
	successMessage,
	errorMessage,
}) {
	const [previewing, setPreviewing] = useState(false);
	const [applying, setApplying] = useState(false);
	const [clearing, setClearing] = useState(false);

	if (customer.customerable_type !== ODESSA_ACCOUNT_TYPE) {
		return null;
	}

	const account = customer.customerable;

	const requestPreview = () => {
		setPreviewing(true);
		router.post(
			route("admin.customers.odessa-sync-preview", customer.id),
			{},
			{ onFinish: () => setPreviewing(false) },
		);
	};

	const applySync = () => {
		if (
			!confirm(
				"¿Confirmas actualizar la metadata Odessa de este cliente con los valores de la previsualización?",
			)
		) {
			return;
		}

		setApplying(true);
		router.post(
			route("admin.customers.odessa-sync", customer.id),
			{},
			{ onFinish: () => setApplying(false) },
		);
	};

	const clearPreview = () => {
		setClearing(true);
		router.delete(
			route("admin.customers.odessa-sync-clear", customer.id),
			{ onFinish: () => setClearing(false) },
		);
	};

	const hasActivePreview = Boolean(odessaSyncPreview);
	const previewHasError = Boolean(odessaSyncPreview?.error);
	const previewHasChanges = Boolean(odessaSyncPreview?.hasChanges);

	return (
		<div className="space-y-4">
			<div className="flex flex-wrap items-center justify-between gap-3">
				<div className="flex items-center gap-2">
					<OdessaLogo className="size-5" />
					<Subheading>Información Odessa</Subheading>
				</div>
				<Button onClick={requestPreview} disabled={previewing}>
					{previewing ? "Consultando…" : "Consultar datos Odessa"}
				</Button>
			</div>

			{successMessage && (
				<div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">
					{successMessage}
				</div>
			)}

			{errorMessage && (
				<div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
					{errorMessage}
				</div>
			)}

			<Card className="space-y-4 p-4">
				<div className="grid gap-3 sm:grid-cols-3">
					<div>
						<Text className="text-xs text-zinc-500">Identificador Odessa</Text>
						<p className="font-mono text-sm">
							{formatValue(account?.odessa_identifier)}
						</p>
					</div>
					<div>
						<Text className="text-xs text-zinc-500">Empresa vinculada</Text>
						<p className="text-sm">
							{formatValue(
								account?.odessa_afiliated_company?.name ??
									account?.odessa_afiliated_company_id,
							)}
						</p>
					</div>
					<div>
						<Text className="text-xs text-zinc-500">Cuenta Famedic (id)</Text>
						<p className="font-mono text-sm">{formatValue(account?.id)}</p>
					</div>
				</div>

				<CurrentValuesTable account={account} />
			</Card>

			{hasActivePreview && (
				<Card className="space-y-4 border-blue-200 p-4 ring-blue-100 dark:border-blue-900 dark:ring-blue-950">
					<div className="flex flex-wrap items-center justify-between gap-3">
						<Text>
							<Strong>Previsualización de sincronización</Strong>
						</Text>
						<Button outline onClick={clearPreview} disabled={clearing}>
							{clearing ? "Descartando…" : "Descartar previsualización"}
						</Button>
					</div>

					{previewHasError ? (
						<div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
							{odessaSyncPreview.error}
						</div>
					) : (
						<>
							<OdessaLinkValidation userData={odessaSyncPreview.userData} />
							<PreviewDiffTable diff={odessaSyncPreview.diff} />

							{previewHasChanges ? (
								<div className="flex flex-wrap gap-2">
									<Button onClick={applySync} disabled={applying}>
										{applying
											? "Guardando…"
											: "Aceptar actualización"}
									</Button>
								</div>
							) : (
								<div className="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900/50 dark:text-zinc-300">
									Los valores en Famedic ya coinciden con Odessa. No hay
									cambios pendientes.
								</div>
							)}
						</>
					)}
				</Card>
			)}
		</div>
	);
}
