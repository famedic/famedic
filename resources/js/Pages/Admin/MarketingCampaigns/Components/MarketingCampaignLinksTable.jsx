import { useState } from "react";
import { router } from "@inertiajs/react";
import {
	ArrowTopRightOnSquareIcon,
	ClipboardDocumentIcon,
	DocumentDuplicateIcon,
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

export default function MarketingCampaignLinksTable({
	campaignId,
	links = [],
	canEdit = false,
	createHref = null,
}) {
	const [copiedId, setCopiedId] = useState(null);
	const [duplicatingId, setDuplicatingId] = useState(null);

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

	const handleCopy = async (link) => {
		if (!link.public_url) return;
		await copyText(link.public_url);
		setCopiedId(link.id);
		setTimeout(() => setCopiedId(null), 2000);
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
		<>
			<div className="hidden md:block">
				<Table dense className="[--gutter:theme(spacing.6)]">
					<TableHead>
						<TableRow>
							<TableHeader>Nombre</TableHeader>
							<TableHeader>URL</TableHeader>
							<TableHeader>Estado</TableHeader>
							<TableHeader>Qué se promociona</TableHeader>
							<TableHeader className="text-right">
								Acciones
							</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{links.map((link) => {
							const targetType = normalizeValue(link.target_type);
							return (
								<TableRow key={link.id}>
									<TableCell className="font-medium">
										{link.name}
									</TableCell>
									<TableCell>
										<Text
											className="font-mono text-sm"
											title={link.public_url}
										>
											/c/{link.slug}
										</Text>
									</TableCell>
									<TableCell>
										<MarketingCampaignStatusBadge
											status={link.status}
											label={link.status_label}
											kind="link"
										/>
									</TableCell>
									<TableCell>
										{link.target_type_label ||
											TARGET_TYPE_LABELS[targetType] ||
											targetType ||
											"—"}
									</TableCell>
									<TableCell className="text-right">
										<div className="flex flex-wrap justify-end gap-2">
											{link.public_url && (
												<>
													<Button
														type="button"
														outline
														onClick={() =>
															window.open(
																link.public_url,
																"_blank",
																"noopener,noreferrer",
															)
														}
													>
														<ArrowTopRightOnSquareIcon className="size-4" />
														Abrir
													</Button>
													<Button
														type="button"
														outline
														onClick={() =>
															handleCopy(link)
														}
													>
														<ClipboardDocumentIcon className="size-4" />
														{copiedId === link.id
															? "Copiado"
															: "Copiar"}
													</Button>
												</>
											)}
											{canEdit && (
												<>
													<Button
														href={route(
															"admin.marketing-campaigns.links.edit",
															{
																marketing_campaign:
																	campaignId,
																marketing_campaign_link:
																	link.id,
															},
														)}
														outline
													>
														Editar
													</Button>
													<Button
														type="button"
														outline
														disabled={
															duplicatingId ===
															link.id
														}
														onClick={() =>
															handleDuplicate(link)
														}
													>
														<DocumentDuplicateIcon className="size-4" />
														{duplicatingId ===
														link.id
															? "Duplicando…"
															: "Duplicar"}
													</Button>
												</>
											)}
										</div>
									</TableCell>
								</TableRow>
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
							<div>
								<Text className="font-semibold">
									{link.name}
								</Text>
								<Text className="mt-1 font-mono text-sm text-zinc-500">
									/c/{link.slug}
								</Text>
							</div>
							<MarketingCampaignStatusBadge
								status={link.status}
								label={link.status_label}
								kind="link"
							/>
						</div>
						<div className="mt-4 flex flex-wrap gap-2">
							{link.public_url && (
								<>
									<Button
										type="button"
										outline
										onClick={() =>
											window.open(
												link.public_url,
												"_blank",
												"noopener,noreferrer",
											)
										}
									>
										Abrir
									</Button>
									<Button
										type="button"
										outline
										onClick={() => handleCopy(link)}
									>
										{copiedId === link.id
											? "Copiado"
											: "Copiar"}
									</Button>
								</>
							)}
							{canEdit && (
								<>
									<Button
										href={route(
											"admin.marketing-campaigns.links.edit",
											{
												marketing_campaign: campaignId,
												marketing_campaign_link:
													link.id,
											},
										)}
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
										Duplicar
									</Button>
								</>
							)}
						</div>
					</div>
				))}
			</div>
		</>
	);
}
