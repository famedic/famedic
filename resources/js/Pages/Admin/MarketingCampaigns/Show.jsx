import { useState } from "react";
import { router } from "@inertiajs/react";
import {
	PlusIcon,
	PencilSquareIcon,
	ArchiveBoxIcon,
	ArrowTopRightOnSquareIcon,
	ClipboardDocumentIcon,
} from "@heroicons/react/16/solid";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import Card from "@/Components/Card";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import MarketingCampaignStatusBadge from "./Components/MarketingCampaignStatusBadge";
import MarketingCampaignLinksTable from "./Components/MarketingCampaignLinksTable";
import MarketingCampaignCollectionsTable from "./Components/MarketingCampaignCollectionsTable";
import MarketingCampaignChecklist from "./Components/MarketingCampaignChecklist";

function normalizeStatus(status) {
	if (status == null) return "";
	if (typeof status === "object") {
		return String(status.value ?? status.name ?? "");
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

async function copyText(text) {
	if (navigator.clipboard?.writeText) {
		await navigator.clipboard.writeText(text);
		return;
	}
	const input = document.createElement("textarea");
	input.value = text;
	document.body.appendChild(input);
	input.select();
	document.execCommand("copy");
	document.body.removeChild(input);
}

export default function MarketingCampaignsShow({
	campaign,
	links = [],
	collections = [],
	checklist = [],
	summary = {},
	capabilities = {},
}) {
	const [archiveOpen, setArchiveOpen] = useState(false);
	const [archiving, setArchiving] = useState(false);
	const [copiedPrimary, setCopiedPrimary] = useState(false);

	const status = normalizeStatus(campaign.status);
	const isArchived =
		Boolean(campaign.is_archived) || status === "archived";

	const canEdit =
		!isArchived &&
		Boolean(capabilities.canEdit ?? capabilities.edit);
	const canArchive =
		!isArchived &&
		Boolean(
			capabilities.canArchive ??
				capabilities.archive ??
				capabilities.edit,
		);
	const canCreateLink =
		!isArchived &&
		Boolean(
			capabilities.canCreateLink ??
				capabilities.create ??
				capabilities.edit,
		);
	const canCreateCollection =
		!isArchived &&
		Boolean(
			capabilities.canCreateCollection ??
				capabilities.create ??
				capabilities.edit,
		);

	const campaignLinks = links.length ? links : campaign.links || [];
	const campaignCollections = collections.length
		? collections
		: campaign.collections || [];
	const primaryLink = summary.primary_link || campaignLinks[0] || null;
	const createLinkHref = canCreateLink
		? route("admin.marketing-campaigns.links.create", campaign.id)
		: null;

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

	const copyPrimaryLink = async () => {
		if (!primaryLink?.public_url) return;
		await copyText(primaryLink.public_url);
		setCopiedPrimary(true);
		setTimeout(() => setCopiedPrimary(false), 2000);
	};

	return (
		<AdminLayout title={campaign.name}>
			<div className="space-y-10">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div className="space-y-3">
						<Heading>{campaign.name}</Heading>
						{campaign.description && (
							<Text className="max-w-2xl text-zinc-600 dark:text-zinc-400">
								{campaign.description}
							</Text>
						)}
						<div className="flex flex-wrap items-center gap-3">
							<MarketingCampaignStatusBadge
								status={campaign.status}
								label={campaign.status_label}
							/>
							{isArchived && (
								<Badge color="zinc">
									Campaña archivada · Solo lectura
								</Badge>
							)}
							<Text className="text-sm text-zinc-500">
								Vigencia: {formatDateTime(campaign.starts_at)} —{" "}
								{formatDateTime(campaign.ends_at)}
							</Text>
						</div>
					</div>
					<div className="flex flex-wrap gap-2">
						<Button
							href={route("admin.marketing-campaigns.index")}
							outline
						>
							Volver
						</Button>
						{primaryLink?.public_url && (
							<>
								<Button
									type="button"
									outline
									onClick={() =>
										window.open(
											primaryLink.public_url,
											"_blank",
											"noopener,noreferrer",
										)
									}
								>
									<ArrowTopRightOnSquareIcon className="size-4" />
									Abrir landing
								</Button>
								<Button type="button" outline onClick={copyPrimaryLink}>
									<ClipboardDocumentIcon className="size-4" />
									{copiedPrimary ? "Copiado" : "Copiar enlace"}
								</Button>
							</>
						)}
						{canEdit && (
							<Button
								href={route(
									"admin.marketing-campaigns.edit",
									campaign.id,
								)}
								outline
							>
								<PencilSquareIcon className="size-4" />
								Editar campaña
							</Button>
						)}
						{canCreateLink && (
							<Button href={createLinkHref} color="lime">
								<PlusIcon className="size-4" />
								Nuevo enlace
							</Button>
						)}
						{canArchive && (
							<Button
								type="button"
								color="red"
								onClick={() => setArchiveOpen(true)}
							>
								<ArchiveBoxIcon className="size-4" />
								Archivar
							</Button>
						)}
					</div>
				</div>

				<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
					<Card className="p-4">
						<Text className="text-sm text-zinc-500">Enlaces</Text>
						<Text className="mt-1 text-2xl font-semibold">
							{summary.links_count ?? campaign.links_count ?? 0}
						</Text>
					</Card>
					<Card className="p-4">
						<Text className="text-sm text-zinc-500">Colecciones</Text>
						<Text className="mt-1 text-2xl font-semibold">
							{summary.collections_count ??
								campaign.collections_count ??
								0}
						</Text>
					</Card>
					<Card className="p-4">
						<Text className="text-sm text-zinc-500">
							Productos configurados
						</Text>
						<Text className="mt-1 text-2xl font-semibold">
							{summary.configured_products_count ?? 0}
						</Text>
					</Card>
					<Card className="p-4">
						<Text className="text-sm text-zinc-500">Completitud</Text>
						<Text className="mt-1 text-2xl font-semibold">
							{summary.completeness_percent ?? 0}%
						</Text>
					</Card>
				</div>

				<MarketingCampaignChecklist items={checklist} />

				<section className="space-y-4">
					<div className="flex flex-wrap items-end justify-between gap-3">
						<Subheading>Enlaces</Subheading>
						{canCreateLink && (
							<Button href={createLinkHref} color="lime">
								<PlusIcon />
								Nuevo enlace
							</Button>
						)}
					</div>
					<MarketingCampaignLinksTable
						campaignId={campaign.id}
						links={campaignLinks}
						canEdit={canEdit}
						createHref={createLinkHref}
					/>
				</section>

				<section className="space-y-4">
					<div className="flex flex-wrap items-end justify-between gap-3">
						<div>
							<Subheading>Colecciones</Subheading>
							<Text className="mt-1 text-sm text-zinc-500">
								Solo necesitas una colección cuando reutilizarás
								un grupo de estudios.
							</Text>
						</div>
						{canCreateCollection && (
							<Button
								href={route(
									"admin.marketing-campaigns.collections.create",
									campaign.id,
								)}
								color="lime"
							>
								<PlusIcon />
								Crear colección
							</Button>
						)}
					</div>
					<MarketingCampaignCollectionsTable
						campaignId={campaign.id}
						collections={campaignCollections}
						canEdit={canEdit}
						createHref={
							canCreateCollection
								? route(
										"admin.marketing-campaigns.collections.create",
										campaign.id,
									)
								: null
						}
					/>
				</section>
			</div>

			<DeleteConfirmationModal
				isOpen={archiveOpen}
				close={() => setArchiveOpen(false)}
				title="Archivar campaña"
				description={`¿Archivar la campaña “${campaign.name}”? Después de archivarla, la campaña quedará disponible únicamente para consulta.`}
				processing={archiving}
				destroy={confirmArchive}
				confirmLabel="Archivar"
			/>
		</AdminLayout>
	);
}
