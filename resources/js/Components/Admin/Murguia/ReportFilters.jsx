import { useForm } from "@inertiajs/react";
import { useMemo } from "react";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import { Text } from "@/Components/Catalyst/text";
import { PRESET_LABELS } from "@/Components/Admin/Murguia/ExportButtons";

export default function ReportFilters({ filters, presets }) {
	const { data, setData, get, processing, reset } = useForm({
		preset: filters.preset || "",
		search: filters.search || "",
		account_type: filters.account_type || "",
		local_status: filters.local_status || "",
		subscription_type: filters.subscription_type || "",
		murguia_sync: filters.murguia_sync || "",
		has_certificate_account: filters.has_certificate_account || "",
		has_family_dependents: filters.has_family_dependents || "",
		no_credito_empty: filters.no_credito_empty || "",
		no_credito_duplicate: filters.no_credito_duplicate || "",
		email_duplicate: filters.email_duplicate || "",
		created_from: filters.created_from || "",
		created_to: filters.created_to || "",
		expires_from: filters.expires_from || "",
		expires_to: filters.expires_to || "",
		sync_from: filters.sync_from || "",
		sync_to: filters.sync_to || "",
		payment_from: filters.payment_from || "",
		payment_to: filters.payment_to || "",
	});

	const hasChanges = useMemo(() => {
		return Object.keys(data).some(
			(key) => (data[key] || "") !== (filters[key] || ""),
		);
	}, [data, filters]);

	const apply = (e) => {
		e?.preventDefault?.();
		if (!processing) {
			get(route("admin.murguia-reports.index"), {
				replace: true,
				preserveState: true,
			});
		}
	};

	const applyPreset = (presetKey) => {
		if (processing) return;
		get(route("admin.murguia-reports.index", { preset: presetKey }), {
			replace: true,
		});
	};

	const clearFilters = () => {
		reset();
		get(route("admin.murguia-reports.index"), { replace: true });
	};

	return (
		<div className="space-y-4">
			<div className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
				<Text className="mb-3 text-sm font-medium text-zinc-700 dark:text-zinc-300">
					Reportes rápidos
				</Text>
				<div className="flex flex-wrap gap-2">
					{(presets || Object.keys(PRESET_LABELS)).map((key) => (
						<Button
							key={key}
							type="button"
							outline={filters.preset !== key}
							onClick={() => applyPreset(key)}
							className="text-xs"
						>
							{PRESET_LABELS[key] || key}
						</Button>
					))}
				</div>
			</div>

			<form
				onSubmit={apply}
				className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
			>
				<div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
					<Field>
						<Label>Búsqueda</Label>
						<Input
							value={data.search}
							onChange={(e) => setData("search", e.target.value)}
							placeholder="Nombre, email, teléfono, noCredito…"
						/>
					</Field>

					<Field>
						<Label>Tipo de cuenta</Label>
						<Select
							value={data.account_type}
							onChange={(e) => setData("account_type", e.target.value)}
						>
							<option value="">Todos</option>
							<option value="regular">Regular</option>
							<option value="odessa">Odessa</option>
							<option value="familiar">Familiar</option>
							<option value="certificate">Certificate</option>
						</Select>
					</Field>

					<Field>
						<Label>Tipo de suscripción</Label>
						<Select
							value={data.subscription_type}
							onChange={(e) => setData("subscription_type", e.target.value)}
						>
							<option value="">Todos</option>
							<option value="trial">Trial</option>
							<option value="regular">Regular</option>
							<option value="family_member">Miembro familiar</option>
							<option value="institutional">Institucional</option>
							<option value="none">Ninguna</option>
						</Select>
					</Field>

					<Field>
						<Label>Estado local</Label>
						<Select
							value={data.local_status}
							onChange={(e) => setData("local_status", e.target.value)}
						>
							<option value="">Todos</option>
							<option value="active">Activo</option>
							<option value="inactive">Inactivo</option>
							<option value="expired">Vencido</option>
							<option value="no_subscription">Sin suscripción</option>
						</Select>
					</Field>

					<Field>
						<Label>Estado sync Murguía</Label>
						<Select
							value={data.murguia_sync}
							onChange={(e) => setData("murguia_sync", e.target.value)}
						>
							<option value="">Todos</option>
							<option value="synced">Sincronizado</option>
							<option value="pending">Pendiente</option>
							<option value="error">Error</option>
							<option value="no_log">Sin log</option>
						</Select>
					</Field>

					<Field>
						<Label>Certificate account</Label>
						<Select
							value={data.has_certificate_account}
							onChange={(e) =>
								setData("has_certificate_account", e.target.value)
							}
						>
							<option value="">Todos</option>
							<option value="true">Con certificado</option>
							<option value="false">Sin certificado</option>
						</Select>
					</Field>

					<Field>
						<Label>Dependientes</Label>
						<Select
							value={data.has_family_dependents}
							onChange={(e) =>
								setData("has_family_dependents", e.target.value)
							}
						>
							<option value="">Todos</option>
							<option value="true">Con dependientes</option>
							<option value="false">Sin dependientes</option>
						</Select>
					</Field>

					<Field>
						<Label>Sin noCredito</Label>
						<Select
							value={data.no_credito_empty}
							onChange={(e) => setData("no_credito_empty", e.target.value)}
						>
							<option value="">Todos</option>
							<option value="true">Solo sin identificador</option>
						</Select>
					</Field>

					<Field>
						<Label>noCredito duplicado</Label>
						<Select
							value={data.no_credito_duplicate}
							onChange={(e) =>
								setData("no_credito_duplicate", e.target.value)
							}
						>
							<option value="">Todos</option>
							<option value="true">Solo duplicados</option>
						</Select>
					</Field>

					<Field>
						<Label>Email duplicado</Label>
						<Select
							value={data.email_duplicate}
							onChange={(e) => setData("email_duplicate", e.target.value)}
						>
							<option value="">Todos</option>
							<option value="true">Solo duplicados</option>
						</Select>
					</Field>

					<Field>
						<Label>Alta desde</Label>
						<Input
							type="date"
							value={data.created_from}
							onChange={(e) => setData("created_from", e.target.value)}
						/>
					</Field>

					<Field>
						<Label>Alta hasta</Label>
						<Input
							type="date"
							value={data.created_to}
							onChange={(e) => setData("created_to", e.target.value)}
						/>
					</Field>

					<Field>
						<Label>Expiración desde</Label>
						<Input
							type="date"
							value={data.expires_from}
							onChange={(e) => setData("expires_from", e.target.value)}
						/>
					</Field>

					<Field>
						<Label>Expiración hasta</Label>
						<Input
							type="date"
							value={data.expires_to}
							onChange={(e) => setData("expires_to", e.target.value)}
						/>
					</Field>

					<Field>
						<Label>Sync desde</Label>
						<Input
							type="date"
							value={data.sync_from}
							onChange={(e) => setData("sync_from", e.target.value)}
						/>
					</Field>

					<Field>
						<Label>Sync hasta</Label>
						<Input
							type="date"
							value={data.sync_to}
							onChange={(e) => setData("sync_to", e.target.value)}
						/>
					</Field>

					<Field>
						<Label>Pago desde</Label>
						<Input
							type="date"
							value={data.payment_from}
							onChange={(e) => setData("payment_from", e.target.value)}
						/>
					</Field>

					<Field>
						<Label>Pago hasta</Label>
						<Input
							type="date"
							value={data.payment_to}
							onChange={(e) => setData("payment_to", e.target.value)}
						/>
					</Field>
				</div>

				<div className="mt-4 flex flex-wrap gap-2">
					<Button type="submit" disabled={processing || !hasChanges}>
						Aplicar filtros
					</Button>
					<Button type="button" outline onClick={clearFilters} disabled={processing}>
						Limpiar
					</Button>
				</div>
			</form>
		</div>
	);
}
