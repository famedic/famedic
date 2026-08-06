import { useState } from "react";
import { router, useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import MarketingCampaignForm from "./Components/MarketingCampaignForm";
import {
	toDatetimeLocalValue,
	fromDatetimeLocalValue,
} from "./Components/MarketingCampaignDateRangeFields";

function normalizeStatus(status) {
	if (status == null) return "draft";
	if (typeof status === "object") {
		return String(status.value ?? status.name ?? "draft");
	}
	return String(status);
}

function formatDateTime(value) {
	if (!value) return "—";
	try {
		return new Date(value).toLocaleString("es-MX");
	} catch {
		return String(value).slice(0, 16);
	}
}

export default function MarketingCampaignsEdit({
	campaign,
	statusOptions = {},
	capabilities = {},
}) {
	const [archiveOpen, setArchiveOpen] = useState(false);
	const [archiving, setArchiving] = useState(false);

	const { data, setData, put, processing, errors, transform } = useForm({
		name: campaign.name || "",
		description: campaign.description || "",
		status: normalizeStatus(campaign.status),
		starts_at: toDatetimeLocalValue(campaign.starts_at),
		ends_at: toDatetimeLocalValue(campaign.ends_at),
	});

	transform((form) => ({
		...form,
		starts_at: fromDatetimeLocalValue(form.starts_at),
		ends_at: fromDatetimeLocalValue(form.ends_at),
		description: form.description?.trim() ? form.description : null,
	}));

	const submit = (e) => {
		e.preventDefault();
		if (!processing) {
			put(route("admin.marketing-campaigns.update", campaign.id));
		}
	};

	const confirmArchive = () => {
		if (archiving) return;
		setArchiving(true);
		router.post(
			route("admin.marketing-campaigns.archive", campaign.id),
			{},
			{
				preserveScroll: true,
				onFinish: () => {
					setArchiving(false);
					setArchiveOpen(false);
				},
			},
		);
	};

	const status = normalizeStatus(campaign.status);
	const canArchive =
		capabilities.archive && status !== "archived";

	return (
		<AdminLayout title={`Editar campaña: ${campaign.name}`}>
			<div className="mx-auto max-w-3xl space-y-8">
				<div className="flex flex-wrap items-end justify-between gap-4">
					<div>
						<Heading>Editar campaña</Heading>
						<Text className="mt-2 text-zinc-600 dark:text-zinc-400">
							{campaign.name}
						</Text>
					</div>
					<div className="flex flex-wrap gap-2">
						<Button
							href={route(
								"admin.marketing-campaigns.show",
								campaign.id,
							)}
							outline
						>
							Ver
						</Button>
						{canArchive && (
							<Button
								type="button"
								color="red"
								onClick={() => setArchiveOpen(true)}
							>
								Archivar
							</Button>
						)}
					</div>
				</div>

				<dl className="grid gap-3 rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700 sm:grid-cols-2">
					<div>
						<dt className="text-zinc-500">Creada por</dt>
						<dd>
							{campaign.created_by_name ||
								campaign.created_by?.name ||
								"—"}
						</dd>
					</div>
					<div>
						<dt className="text-zinc-500">Actualizada por</dt>
						<dd>
							{campaign.updated_by_name ||
								campaign.updated_by?.name ||
								"—"}
						</dd>
					</div>
					<div>
						<dt className="text-zinc-500">Creada</dt>
						<dd>{formatDateTime(campaign.created_at)}</dd>
					</div>
					<div>
						<dt className="text-zinc-500">Actualizada</dt>
						<dd>{formatDateTime(campaign.updated_at)}</dd>
					</div>
				</dl>

				<MarketingCampaignForm
					data={data}
					setData={setData}
					errors={errors}
					statusOptions={statusOptions}
					processing={processing}
					onSubmit={submit}
					submitLabel="Guardar cambios"
				/>
			</div>

			<DeleteConfirmationModal
				isOpen={archiveOpen}
				close={() => setArchiveOpen(false)}
				title="Archivar campaña"
				description={`¿Archivar la campaña “${campaign.name}”? Dejará de estar disponible para nuevas acciones.`}
				processing={archiving}
				destroy={confirmArchive}
				confirmLabel="Archivar"
			/>
		</AdminLayout>
	);
}
