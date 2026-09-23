import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import ContactAutomations from "@/Components/Admin/ActiveCampaign/Contacts/ContactAutomations";
import ContactCustomFields from "@/Components/Admin/ActiveCampaign/Contacts/ContactCustomFields";
import ContactEngagement from "@/Components/Admin/ActiveCampaign/Contacts/ContactEngagement";
import ContactEvents from "@/Components/Admin/ActiveCampaign/Contacts/ContactEvents";
import ContactLeadScore from "@/Components/Admin/ActiveCampaign/Contacts/ContactLeadScore";
import ContactLists from "@/Components/Admin/ActiveCampaign/Contacts/ContactLists";
import ContactTags from "@/Components/Admin/ActiveCampaign/Contacts/ContactTags";
import { ArrowTopRightOnSquareIcon } from "@heroicons/react/16/solid";

function MirrorStatusBanner({ mirror }) {
	if (!mirror) {
		return null;
	}

	if (mirror.status === "ok") {
		return (
			<div className="rounded-lg border border-emerald-200 bg-emerald-50/80 px-4 py-3 dark:border-emerald-900/40 dark:bg-emerald-950/20">
				<div className="flex flex-wrap items-center justify-between gap-3">
					<div>
						<div className="flex items-center gap-2 text-sm font-medium text-emerald-800 dark:text-emerald-300">
							<span className="size-2 rounded-full bg-emerald-500" />
							ActiveCampaign sincronizado
						</div>
						{mirror.synced_at_human ? (
							<Text className="mt-1 text-xs text-emerald-700/80 dark:text-emerald-400/80">
								Última lectura: {mirror.synced_at_human}
								{mirror.from_cache ? " (caché)" : ""}
								{mirror.ac_contact_id ? ` · Contacto #${mirror.ac_contact_id}` : ""}
							</Text>
						) : null}
					</div>
					{mirror.lead_score?.total != null ? (
						<Badge color="emerald">Lead score: {mirror.lead_score.total}</Badge>
					) : null}
				</div>
			</div>
		);
	}

	return (
		<div className="rounded-lg border border-rose-200 bg-rose-50/80 px-4 py-3 dark:border-rose-900/40 dark:bg-rose-950/20">
			<div className="flex items-center gap-2 text-sm font-medium text-rose-800 dark:text-rose-300">
				<span className="size-2 rounded-full bg-rose-500" />
				{mirror.status === "missing"
					? "Sin datos de ActiveCampaign"
					: "Error consultando ActiveCampaign"}
			</div>
			{mirror.message ? (
				<Text className="mt-1 text-xs text-rose-700/80 dark:text-rose-400/80">
					{mirror.message}
				</Text>
			) : null}
		</div>
	);
}

function WebActivitiesCard({ customer }) {
	const activities = customer.active_campaign_web_activities ?? [];

	if (activities.length === 0) {
		return null;
	}

	return (
		<div className="space-y-3 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<Subheading>Actividad web (cache local)</Subheading>
			<ul className="space-y-2 text-sm">
				{activities.slice(0, 12).map((activity) => (
					<li
						key={activity.id}
						className="rounded-lg border border-zinc-100 p-3 dark:border-zinc-800"
					>
						<Text>{activity.label || activity.title || activity.path}</Text>
						<Text className="text-xs text-zinc-500">
							{activity.path} · {formatDateTime(activity.occurred_at)}
						</Text>
					</li>
				))}
			</ul>
		</div>
	);
}

function DispatchesCard({ dispatches = [] }) {
	if (dispatches.length === 0) {
		return null;
	}

	const statusColor = (status) => {
		if (status === "synced") return "famedic-lime";
		if (status === "failed") return "red";
		if (status === "pending" || status === "processing") return "amber";
		return "slate";
	};

	return (
		<div className="space-y-3 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<Subheading>Eventos enviados a ActiveCampaign</Subheading>
			<Text className="text-xs text-zinc-500">
				Cola local de sincronización saliente desde Famedic.
			</Text>
			<ul className="space-y-2">
				{dispatches.map((dispatch) => (
					<li
						key={dispatch.id}
						className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-100 px-3 py-2 text-sm dark:border-zinc-800"
					>
						<div className="min-w-0">
							<Text className="font-medium">{dispatch.event_type}</Text>
							{dispatch.last_error ? (
								<Text className="text-xs text-rose-600 dark:text-rose-400">
									{dispatch.last_error}
								</Text>
							) : (
								<Text className="text-xs text-zinc-500">
									{formatDateTime(dispatch.synced_at || dispatch.updated_at)}
								</Text>
							)}
						</div>
						<Badge color={statusColor(dispatch.status)}>{dispatch.status}</Badge>
					</li>
				))}
			</ul>
		</div>
	);
}

function formatDateTime(value) {
	if (!value) return "—";
	const date = new Date(value);
	if (Number.isNaN(date.getTime())) return String(value);

	return date.toLocaleString("es-MX", {
		dateStyle: "medium",
		timeStyle: "short",
	});
}

export default function CustomerActiveCampaignPanel({
	customer,
	mirror = null,
	dispatches = [],
	activeCampaignContactUrl = null,
	canViewActiveCampaignHub = false,
	compact = false,
}) {
	const ready = Boolean(mirror);

	if (compact) {
		return (
			<div className="space-y-3 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<div className="flex items-center justify-between gap-3">
					<Subheading>ActiveCampaign</Subheading>
					{canViewActiveCampaignHub && activeCampaignContactUrl ? (
						<Button outline size="sm" href={activeCampaignContactUrl}>
							Ver 360
							<ArrowTopRightOnSquareIcon />
						</Button>
					) : null}
				</div>
				<MirrorStatusBanner mirror={mirror} />
				{mirror?.status === "ok" ? (
					<div className="flex flex-wrap gap-2">
						<Badge color="sky">{mirror.tags?.length ?? 0} tags</Badge>
						<Badge color="purple">
							{mirror.automations?.length ?? 0} automatizaciones
						</Badge>
						<Badge color="emerald">
							Lead score: {mirror.lead_score?.total ?? "—"}
						</Badge>
					</div>
				) : null}
			</div>
		);
	}

	return (
		<div className="space-y-4">
			<div className="flex flex-wrap items-center justify-between gap-3">
				<div>
					<Subheading>ActiveCampaign</Subheading>
					<Text className="text-sm text-zinc-500">
						Datos en vivo desde el espejo CRM reutilizado del hub de Marketing
						Intelligence.
					</Text>
				</div>
				<div className="flex flex-wrap gap-2">
					{canViewActiveCampaignHub && activeCampaignContactUrl ? (
						<Button outline href={activeCampaignContactUrl}>
							Abrir vista 360
							<ArrowTopRightOnSquareIcon />
						</Button>
					) : null}
					{canViewActiveCampaignHub ? (
						<Button
							plain
							href={route("admin.activecampaign.contacts")}
						>
							Hub de contactos
						</Button>
					) : null}
				</div>
			</div>

			<MirrorStatusBanner mirror={mirror} />

			<div className="grid gap-4 xl:grid-cols-2">
				<ContactLeadScore ready={ready} mirror={mirror} />
				<ContactEngagement ready={ready} mirror={mirror} />
				<ContactTags ready={ready} mirror={mirror} />
				<ContactLists ready={ready} mirror={mirror} />
				<ContactCustomFields ready={ready} mirror={mirror} />
				<ContactAutomations ready={ready} mirror={mirror} />
			</div>

			<ContactEvents ready={ready} mirror={mirror} />
			<WebActivitiesCard customer={customer} />
			<DispatchesCard dispatches={dispatches} />
		</div>
	);
}
