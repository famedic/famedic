import { useState } from "react";
import { router } from "@inertiajs/react";
import {
	ArrowTopRightOnSquareIcon,
	ClipboardDocumentIcon,
	DocumentDuplicateIcon,
	EyeIcon,
	LinkIcon,
} from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
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

function channelMeta(link) {
	const params = link?.utm_parameters || {};
	const source = String(params.utm_source || "").toLowerCase();
	const medium = String(params.utm_medium || "").toLowerCase();

	if (source.includes("whatsapp") || medium.includes("whatsapp")) {
		return { icon: "WA", label: "WhatsApp", color: "green" };
	}
	if (source.includes("facebook") || source.includes("instagram") || medium.includes("social")) {
		return { icon: "f", label: "Meta / Social", color: "blue" };
	}
	if (source.includes("google") || medium.includes("cpc")) {
		return { icon: "G", label: "Google Ads", color: "sky" };
	}
	if (source.includes("email") || medium.includes("email")) {
		return { icon: "@", label: "Email", color: "violet" };
	}
	if (source.includes("qr") || medium.includes("offline")) {
		return { icon: "QR", label: "QR / Offline", color: "amber" };
	}

	return { icon: "UTM", label: link.channel_label || "Sin canal", color: "zinc" };
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

function ChannelBadge({ link }) {
	const meta = channelMeta(link);

	return (
		<Badge color={meta.color}>
			<span className="font-mono">{meta.icon}</span>
			{meta.label}
		</Badge>
	);
}

function ParametersPanel({ link, onCopyFull, onCopyBase }) {
	const params = link.utm_parameters || {};

	return (
		<div className="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/60">
			<div className="grid gap-4 md:grid-cols-2">
				<UrlLine label="URL base" value={link.base_url || link.public_url} />
				<UrlLine label="URL completa con UTMs" value={link.full_url || link.public_url} />
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
					message="Esta campaña todavía no tiene enlaces hijos para canales o UTMs."
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

	return (
		<div className="space-y-4">
			{links.map((link, index) => {
				const targetType = normalizeValue(link.target_type);
				const expanded = expandedId === link.id;

				return (
					<article
						key={link.id}
						className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
					>
						<div className="grid gap-4 lg:grid-cols-[8rem_minmax(0,1fr)]">
							<div className="relative h-28 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
								{link.hero_image ? (
									<img
										src={link.hero_image}
										alt={link.public_title || link.name}
										className="h-full w-full object-cover"
									/>
								) : (
									<div className="flex h-full items-center justify-center text-zinc-400">
										<LinkIcon className="size-7" />
									</div>
								)}
								<div className="absolute left-2 top-2">
									<Badge color="slate">Hijo #{index + 1}</Badge>
								</div>
							</div>

							<div className="min-w-0 space-y-4">
								<div className="flex flex-wrap items-start justify-between gap-3">
									<div className="min-w-0">
										<div className="flex flex-wrap items-center gap-2">
											<Text className="font-semibold text-zinc-950 dark:text-white">
												{link.name}
											</Text>
											<ChannelBadge link={link} />
											<MarketingCampaignStatusBadge
												status={link.status}
												label={link.status_label}
												kind="link"
											/>
										</div>
										<Text className="mt-1 text-sm text-zinc-500">
											{link.public_title || link.public_subtitle || "Landing derivada de la campaña"}
										</Text>
									</div>
									<Badge color="zinc">
										{link.target_type_label || TARGET_TYPE_LABELS[targetType] || targetType || "Destino"}
									</Badge>
								</div>

								<div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
									<UrlLine label="URL base" value={link.base_url || link.public_url} />
									<UrlLine label="URL con UTMs" value={link.full_url || link.public_url} />
								</div>

								<div className="flex flex-wrap items-center justify-between gap-3">
									<Text className="font-mono text-sm text-zinc-500">/c/{link.slug}</Text>
									<div className="flex flex-wrap justify-end gap-2">
										{link.full_url && (
											<Button type="button" outline onClick={() => handleCopyFull(link)}>
												<ClipboardDocumentIcon className="size-4" />
												{copiedId === link.id ? copiedMessage || "Copiado" : "Copiar URL"}
											</Button>
										)}
										{link.base_url && (
											<Button
												type="button"
												outline
												onClick={() => window.open(link.base_url, "_blank", "noopener,noreferrer")}
											>
												<ArrowTopRightOnSquareIcon className="size-4" />
												Abrir
											</Button>
										)}
										<Button
											type="button"
											outline
											aria-expanded={expanded}
											onClick={() => setExpandedId(expanded ? null : link.id)}
										>
											<EyeIcon className="size-4" />
											Parámetros
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
								</div>

								{expanded && (
									<ParametersPanel
										link={link}
										onCopyFull={() => handleCopyFull(link)}
										onCopyBase={() => handleCopyBase(link)}
									/>
								)}
							</div>
						</div>
					</article>
				);
			})}
		</div>
	);
}
