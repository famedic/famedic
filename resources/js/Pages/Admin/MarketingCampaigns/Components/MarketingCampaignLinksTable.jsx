import { Fragment, useState } from "react";
import { router } from "@inertiajs/react";
import {
	ArrowTopRightOnSquareIcon,
	ClipboardDocumentIcon,
	DocumentDuplicateIcon,
	EyeIcon,
} from "@heroicons/react/16/solid";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import EmptyListCard from "@/Components/EmptyListCard";
import MarketingCampaignStatusBadge from "./MarketingCampaignStatusBadge";

const TARGET_TYPE_LABELS = {
	brand: "Marca",
	category: "Categoría",
	product: "Producto",
	collection: "Colección",
};

const UTM_FIELDS = [
	"utm_source",
	"utm_medium",
	"utm_campaign",
	"utm_term",
	"utm_content",
];

function normalizeValue(value) {
	if (value == null) return "";
	if (typeof value === "object") {
		return String(value.value ?? value.name ?? "");
	}
	return String(value);
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

function UrlLine({ label, value }) {
	if (!value) return null;

	return (
		<div className="min-w-0">
			<Text className="text-xs font-medium uppercase text-zinc-500">{label}</Text>
			<Text className="mt-1 truncate font-mono text-sm text-zinc-700 dark:text-zinc-300" title={value}>
				{value}
			</Text>
		</div>
	);
}

function ParametersPanel({ link, onCopyFull, onCopyBase }) {
	const params = link.utm_parameters || {};

	return (
		<div className="mt-3 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/60">
			<div className="grid gap-3 md:grid-cols-2">
				<UrlLine label="URL de landing" value={link.base_url || link.public_url} />
				<UrlLine label="URL completa generada" value={link.full_url || link.public_url} />
				{UTM_FIELDS.map((field) => (
					<div key={field}>
						<Text className="text-xs font-medium uppercase text-zinc-500">{field}</Text>
						<Text className="mt-1 font-mono text-sm text-zinc-700 dark:text-zinc-300">
							{params[field] ?? "—"}
						</Text>
					</div>
				))}
			</div>
			<div className="mt-4 flex flex-wrap gap-2">
				<Button type="button" outline onClick={onCopyFull}>
					<ClipboardDocumentIcon className="size-4" />
					Copiar URL completa
				</Button>
				<Button type="button" outline onClick={onCopyBase}>
					Copiar URL base
				</Button>
				{link.base_url && (
					<Button
						type="button"
						outline
						onClick={() => window.open(link.base_url, "_blank", "noopener,noreferrer")}
					>
						<ArrowTopRightOnSquareIcon className="size-4" />
						Abrir en nueva pestaña
					</Button>
				)}
			</div>
		</div>
	);
}

export default function MarketingCampaignLinksTable({
	campaignId,
	links = [],
	canEdit = false,
	createHref = null,
}) {
	const [copiedId, setCopiedId] = useState(null);
	const [copiedMessage, setCopiedMessage] = useState("");
	const [duplicatingId, setDuplicatingId] = useState(null);
	const [expandedId, setExpandedId] = useState(null);

	if (!links.length) {
		return (
			<div className="space-y-4">
				<EmptyListCard
					heading="Sin enlaces públicos"
					message="Esta campaña todavía no tiene un enlace público."
				/>
				{createHref && (
					<div className="flex justify-center">
						<Button href={createHref} color="lime">
							Crear primer enlace
						</Button>
					</div>
				)}
			</div>
		);
	}

	const flashCopied = (link, message) => {
		setCopiedId(link.id);
		setCopiedMessage(message);
		setTimeout(() => {
			setCopiedId(null);
			setCopiedMessage("");
		}, 2000);
	};

	const handleCopyFull = async (link) => {
		const url = link.full_url || link.public_url;
		if (!url) return;
		await copyText(url);
		flashCopied(link, "URL completa copiada");
	};

	const handleCopyBase = async (link) => {
		const url = link.base_url || link.public_url;
		if (!url) return;
		await copyText(url);
		flashCopied(link, "URL base copiada");
	};

	const handleDuplicate = (link) => {
		if (duplicatingId) return;
		setDuplicatingId(link.id);
		router.post(
			route("admin.marketing-campaigns.links.duplicate", {
				marketing_campaign: campaignId,
				marketing_campaign_link: link.id,
			}),
			{},
			{
				preserveScroll: true,
				onFinish: () => setDuplicatingId(null),
			},
		);
	};

	const renderActions = (link) => (
		<div className="flex flex-wrap justify-end gap-2">
			{link.full_url && (
				<Button type="button" outline onClick={() => handleCopyFull(link)}>
					<ClipboardDocumentIcon className="size-4" />
					{copiedId === link.id ? copiedMessage || "Copiado" : "Copiar URL completa"}
				</Button>
			)}
			{link.base_url && (
				<Button
					type="button"
					outline
					onClick={() => window.open(link.base_url, "_blank", "noopener,noreferrer")}
				>
					<ArrowTopRightOnSquareIcon className="size-4" />
					Abrir landing
				</Button>
			)}
			<Button
				type="button"
				outline
				aria-expanded={expandedId === link.id}
				onClick={() => setExpandedId(expandedId === link.id ? null : link.id)}
			>
				<EyeIcon className="size-4" />
				Ver parámetros
			</Button>
			{canEdit && (
				<>
					<Button
						href={route("admin.marketing-campaigns.links.edit", {
							marketing_campaign: campaignId,
							marketing_campaign_link: link.id,
						})}
						outline
					>
						Editar
					</Button>
					<Button
						type="button"
						outline
						disabled={duplicatingId === link.id}
						onClick={() => handleDuplicate(link)}
					>
						<DocumentDuplicateIcon className="size-4" />
						{duplicatingId === link.id ? "Duplicando..." : "Duplicar"}
					</Button>
				</>
			)}
		</div>
	);

	return (
		<>
			<div className="hidden md:block">
				<Table dense className="[--gutter:theme(spacing.6)]">
					<TableHead>
						<TableRow>
							<TableHeader>Nombre interno</TableHeader>
							<TableHeader>Slug y canal</TableHeader>
							<TableHeader>Estado</TableHeader>
							<TableHeader>URL base</TableHeader>
							<TableHeader>URL completa con UTMs</TableHeader>
							<TableHeader className="text-right">Acciones</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{links.map((link) => {
							const targetType = normalizeValue(link.target_type);
							return (
								<Fragment key={link.id}>
									<TableRow>
										<TableCell className="font-medium">
											<div>{link.name}</div>
											<div className="mt-1 text-xs text-zinc-500">
												{link.target_type_label || TARGET_TYPE_LABELS[targetType] || targetType || "—"}
											</div>
										</TableCell>
										<TableCell>
											<Text className="font-mono text-sm">/c/{link.slug}</Text>
											<Text className="mt-1 text-xs text-zinc-500">
												{link.channel_label || "Sin preset"}
											</Text>
										</TableCell>
										<TableCell>
											<MarketingCampaignStatusBadge
												status={link.status}
												label={link.status_label}
												kind="link"
											/>
										</TableCell>
										<TableCell className="max-w-[16rem]">
											<UrlLine label="" value={link.base_url || link.public_url} />
										</TableCell>
										<TableCell className="max-w-[22rem]">
											<UrlLine label="" value={link.full_url || link.public_url} />
											<button
												type="button"
												className="mt-1 text-xs font-semibold text-famedic-dark underline-offset-4 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime"
												onClick={() => setExpandedId(expandedId === link.id ? null : link.id)}
											>
												Ver URL completa
											</button>
										</TableCell>
										<TableCell className="text-right">
											{renderActions(link)}
										</TableCell>
									</TableRow>
									{expandedId === link.id && (
										<TableRow>
											<TableCell colSpan={6}>
												<ParametersPanel
													link={link}
													onCopyFull={() => handleCopyFull(link)}
													onCopyBase={() => handleCopyBase(link)}
												/>
											</TableCell>
										</TableRow>
									)}
								</Fragment>
							);
						})}
					</TableBody>
				</Table>
			</div>

			<div className="space-y-3 md:hidden">
				{links.map((link) => (
					<div
						key={link.id}
						className="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
					>
						<div className="flex items-start justify-between gap-3">
							<div className="min-w-0">
								<Text className="font-semibold">{link.name}</Text>
								<Text className="mt-1 font-mono text-sm text-zinc-500">/c/{link.slug}</Text>
								<Text className="mt-1 text-xs text-zinc-500">{link.channel_label || "Sin preset"}</Text>
							</div>
							<MarketingCampaignStatusBadge
								status={link.status}
								label={link.status_label}
								kind="link"
							/>
						</div>
						<div className="mt-4 space-y-3">
							<UrlLine label="URL base" value={link.base_url || link.public_url} />
							<UrlLine label="URL completa con UTMs" value={link.full_url || link.public_url} />
							{renderActions(link)}
							{expandedId === link.id && (
								<ParametersPanel
									link={link}
									onCopyFull={() => handleCopyFull(link)}
									onCopyBase={() => handleCopyBase(link)}
								/>
							)}
						</div>
					</div>
				))}
			</div>
		</>
	);
}
