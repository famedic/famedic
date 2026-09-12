import { router, useForm } from "@inertiajs/react";
import {
	ArrowDownTrayIcon,
	FunnelIcon,
} from "@heroicons/react/16/solid";
import { Button } from "@/Components/Catalyst/button";
import { Input } from "@/Components/Catalyst/input";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";

function formatDateTime(value) {
	if (!value) return "-";
	try {
		return new Date(value).toLocaleString("es-MX");
	} catch {
		return String(value).slice(0, 16);
	}
}

function formatMoney(cents) {
	return new Intl.NumberFormat("es-MX", {
		style: "currency",
		currency: "MXN",
		maximumFractionDigits: 0,
	}).format(Number(cents ?? 0) / 100);
}

function cleanParams(params) {
	return Object.fromEntries(
		Object.entries(params).filter(([, value]) => value !== "" && value !== null && value !== undefined),
	);
}

function exportHref(campaignId, filters) {
	const params = new URLSearchParams(cleanParams({
		attributed_from: filters.from,
		attributed_to: filters.to,
		attributed_link_id: filters.link_id,
		attributed_utm_source: filters.utm_source,
		attributed_utm_medium: filters.utm_medium,
		attributed_status: filters.status,
	}));
	const query = params.toString();
	const base = route("admin.marketing-campaigns.attributed-users.export", campaignId);

	return query ? `${base}?${query}` : base;
}

function LinkLabel({ link }) {
	if (!link?.name && !link?.slug) return <span>-</span>;

	return (
		<span>
			<span className="block font-medium text-zinc-900 dark:text-white">
				{link.name || "Sin nombre"}
			</span>
			{link.slug && (
				<span className="block text-xs text-zinc-500">/c/{link.slug}</span>
			)}
		</span>
	);
}

function Pagination({ meta = {}, links = {} }) {
	if (!meta.total) {
		return null;
	}

	return (
		<div className="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 pt-4 text-sm dark:border-white/10">
			<Text className="text-sm text-zinc-500">
				Mostrando {meta.from ?? 0}-{meta.to ?? 0} de {meta.total}
			</Text>
			<div className="flex gap-2">
				<Button href={links.prev || undefined} outline disabled={!links.prev}>
					Anterior
				</Button>
				<Button href={links.next || undefined} outline disabled={!links.next}>
					Siguiente
				</Button>
			</div>
		</div>
	);
}

export default function MarketingCampaignAttributedUsersTable({
	campaignId,
	links = [],
	attributedUsers = {},
}) {
	const initialFilters = attributedUsers.filters ?? {};
	const { data, setData, processing, errors } = useForm({
		from: initialFilters.from ?? "",
		to: initialFilters.to ?? "",
		link_id: initialFilters.link_id ?? "",
		utm_source: initialFilters.utm_source ?? "",
		utm_medium: initialFilters.utm_medium ?? "",
		status: initialFilters.status ?? "",
		per_page: initialFilters.per_page ?? 25,
	});

	const submit = (event) => {
		event.preventDefault();
		router.get(
			route("admin.marketing-campaigns.show", campaignId),
			cleanParams({
				tab: "attributed-users",
				attributed_from: data.from,
				attributed_to: data.to,
				attributed_link_id: data.link_id,
				attributed_utm_source: data.utm_source,
				attributed_utm_medium: data.utm_medium,
				attributed_status: data.status,
				attributed_per_page: data.per_page,
			}),
			{ preserveScroll: true, preserveState: true },
		);
	};

	const reset = () => {
		router.get(
			route("admin.marketing-campaigns.show", campaignId),
			{ tab: "attributed-users" },
			{ preserveScroll: true },
		);
	};

	const rows = attributedUsers.data ?? [];
	const canViewPii = Boolean(attributedUsers.can_view_pii);

	return (
		<div className="space-y-5">
			<form onSubmit={submit} className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-white/10 dark:bg-zinc-900">
				<div className="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
					<Field>
						<Label>Desde</Label>
						<Input
							type="date"
							value={data.from}
							onChange={(event) => setData("from", event.target.value)}
						/>
						{errors.attributed_from && <ErrorMessage>{errors.attributed_from}</ErrorMessage>}
					</Field>
					<Field>
						<Label>Hasta</Label>
						<Input
							type="date"
							value={data.to}
							onChange={(event) => setData("to", event.target.value)}
						/>
						{errors.attributed_to && <ErrorMessage>{errors.attributed_to}</ErrorMessage>}
					</Field>
					<Field>
						<Label>Enlace</Label>
						<select
							value={data.link_id}
							onChange={(event) => setData("link_id", event.target.value)}
							className="block min-h-11 w-full rounded-lg border border-zinc-950/10 bg-white px-3 py-2 text-base text-zinc-950 shadow-sm sm:min-h-9 sm:text-sm dark:border-slate-800 dark:bg-slate-900 dark:text-white"
						>
							<option value="">Todos</option>
							{links.map((link) => (
								<option key={link.id} value={String(link.id)}>
									{link.name}
								</option>
							))}
						</select>
						{errors.attributed_link_id && <ErrorMessage>{errors.attributed_link_id}</ErrorMessage>}
					</Field>
					<Field>
						<Label>Fuente</Label>
						<Input
							value={data.utm_source}
							onChange={(event) => setData("utm_source", event.target.value)}
							placeholder="google"
						/>
					</Field>
					<Field>
						<Label>Medio</Label>
						<Input
							value={data.utm_medium}
							onChange={(event) => setData("utm_medium", event.target.value)}
							placeholder="cpc"
						/>
					</Field>
					<Field>
						<Label>Estado</Label>
						<select
							value={data.status}
							onChange={(event) => setData("status", event.target.value)}
							className="block min-h-11 w-full rounded-lg border border-zinc-950/10 bg-white px-3 py-2 text-base text-zinc-950 shadow-sm sm:min-h-9 sm:text-sm dark:border-slate-800 dark:bg-slate-900 dark:text-white"
						>
							<option value="">Todos</option>
							<option value="registered">Registrados</option>
							<option value="buyer">Compradores</option>
							<option value="registered_without_purchase">Registrados sin compra</option>
						</select>
					</Field>
				</div>
				<div className="mt-4 flex flex-wrap items-center justify-between gap-3">
					<div className="flex flex-wrap gap-2">
						<Button type="submit" outline disabled={processing}>
							<FunnelIcon className="size-4" />
							Aplicar filtros
						</Button>
						<Button type="button" plain onClick={reset} disabled={processing}>
							Limpiar
						</Button>
					</div>
					<Button href={exportHref(campaignId, data)} outline>
						<ArrowDownTrayIcon className="size-4" />
						Exportar CSV
					</Button>
				</div>
			</form>

			<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-900">
				<div className="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 p-4 dark:border-white/10">
					<div>
						<Text className="font-semibold text-zinc-900 dark:text-white">
							Usuarios atribuidos
						</Text>
						<Text className="mt-1 text-sm text-zinc-500">
							Solo usuarios identificados. First-touch y last-touch se mantienen separados.
						</Text>
					</div>
					<Badge color={canViewPii ? "lime" : "zinc"}>
						{canViewPii ? "PII visible por permiso" : "PII oculta"}
					</Badge>
				</div>

				{rows.length === 0 ? (
					<div className="p-6">
						<Text className="text-sm text-zinc-500">
							No hay usuarios identificados con los filtros actuales.
						</Text>
					</div>
				) : (
					<div className="overflow-x-auto">
						<table className="min-w-full divide-y divide-zinc-200 text-left text-sm dark:divide-white/10">
							<thead>
								<tr className="text-xs uppercase text-zinc-500">
									<th className="px-4 py-3 font-medium">Usuario</th>
									<th className="px-4 py-3 font-medium">Registro</th>
									<th className="px-4 py-3 font-medium">First-touch</th>
									<th className="px-4 py-3 font-medium">Last-touch</th>
									<th className="px-4 py-3 text-right font-medium">Conversiones</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-zinc-100 dark:divide-white/5">
								{rows.map((row) => (
									<tr key={row.attribution.reference}>
										<td className="px-4 py-4 align-top">
											<div className="font-medium text-zinc-900 dark:text-white">
												{row.user.label}
											</div>
											{canViewPii && row.user.email && (
												<div className="text-xs text-zinc-500">{row.user.email}</div>
											)}
											<div className="mt-2 text-xs text-zinc-400">
												{row.attribution.reference}
											</div>
										</td>
										<td className="px-4 py-4 align-top">
											<div>{formatDateTime(row.attribution.identified_at)}</div>
											<div className="mt-2"><LinkLabel link={row.registration.link} /></div>
											<div className="mt-2 text-xs text-zinc-500">
												{row.registration.utm_source || "Sin fuente"} / {row.registration.utm_medium || "Sin medio"}
											</div>
										</td>
										<td className="px-4 py-4 align-top">
											<LinkLabel link={row.first_touch.link} />
											<div className="mt-2 text-xs text-zinc-500">
												{formatDateTime(row.attribution.first_touched_at)}
											</div>
											<div className="mt-1 text-xs text-zinc-500">
												{row.first_touch.conversions} conv. · {formatMoney(row.first_touch.revenue_cents)}
											</div>
										</td>
										<td className="px-4 py-4 align-top">
											<LinkLabel link={row.last_touch.link} />
											<div className="mt-2 text-xs text-zinc-500">
												{formatDateTime(row.attribution.last_touched_at)}
											</div>
											<div className="mt-1 text-xs text-zinc-500">
												{row.last_touch.conversions} conv. · {formatMoney(row.last_touch.revenue_cents)}
											</div>
										</td>
										<td className="px-4 py-4 text-right align-top">
											<Badge color={row.conversions.is_buyer ? "lime" : "zinc"}>
												{row.conversions.is_buyer ? "Comprador" : "Registrado"}
											</Badge>
											<div className="mt-2 font-semibold text-zinc-900 dark:text-white">
												{row.conversions.count}
											</div>
											<div className="text-xs text-zinc-500">
												{formatMoney(row.conversions.revenue_cents)}
											</div>
										</td>
									</tr>
								))}
							</tbody>
						</table>
					</div>
				)}

				<div className="p-4">
					<Pagination meta={attributedUsers.meta} links={attributedUsers.links} />
				</div>
			</div>
		</div>
	);
}
