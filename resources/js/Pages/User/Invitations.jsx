import { useMemo, useState } from "react";
import SettingsLayout from "@/Layouts/SettingsLayout";
import {
	CheckCircleIcon,
	ChevronRightIcon,
	ClipboardDocumentIcon,
	GiftIcon,
	LinkIcon,
	PaperAirplaneIcon,
	ShareIcon,
	SparklesIcon,
	StarIcon,
	UserGroupIcon,
	UserPlusIcon,
} from "@heroicons/react/24/solid";
import { CheckIcon, ChatBubbleLeftRightIcon } from "@heroicons/react/20/solid";
import clsx from "clsx";

const tabs = [
	{ id: "invitations", label: "Mis invitaciones" },
	{ id: "benefits", label: "Beneficios" },
];

export default function Invitations({
	invitationUrl,
	invitationStats = {},
	invitations = [],
}) {
	const [copied, setCopied] = useState(false);
	const [selectedTab, setSelectedTab] = useState("invitations");

	const whatsappUrl = useMemo(() => {
		const text = encodeURIComponent(
			`Te invito a registrarte en Famedic. Usa mi enlace para acceder a beneficios especiales: ${invitationUrl}`,
		);

		return `https://wa.me/?text=${text}`;
	}, [invitationUrl]);

	const copyInvitationLink = async () => {
		await navigator.clipboard.writeText(invitationUrl);
		setCopied(true);
		window.setTimeout(() => setCopied(false), 1800);
	};

	const shareInvitationLink = async () => {
		if (navigator.share) {
			await navigator.share({
				title: "Invitacion Famedic",
				text: "Te invito a registrarte en Famedic.",
				url: invitationUrl,
			});
			return;
		}

		await copyInvitationLink();
	};

	const registeredInvitations = invitations.filter(
		(invitation) => invitation.benefit_granted,
	);

	return (
		<SettingsLayout title="Invita a tus amigos">
			<section className="relative overflow-hidden rounded-xl bg-[#f8f5ff] ring-1 ring-slate-200">
				<img
					alt=""
					src="/images/invitations/asset-compartir.png"
					className="absolute inset-0 hidden h-full w-full object-cover object-center lg:block"
				/>
				<div className="relative min-h-[18rem]">
					<div className="flex min-h-[18rem] w-full flex-col justify-center px-6 py-8 sm:px-8 lg:w-[59%] lg:py-9 xl:w-[57%]">
						<p className="text-sm font-bold uppercase text-violet-500">
							Invita a tus amigos
						</p>
						<h1 className="mt-3 max-w-2xl font-poppins text-4xl font-bold leading-tight text-famedic-darker lg:max-w-2xl xl:text-[2.75rem]">
							Comparte bienestar con quienes mas quieres
						</h1>
						<p className="mt-4 max-w-2xl text-base leading-7 text-slate-600">
							Invita a tus amigos a registrarse en Famedic. Cuando
							se unan con tu enlace, ambos podran recibir
							beneficios especiales.
						</p>

						<div className="mt-7 grid gap-4 sm:grid-cols-3">
							<Step
								icon={GiftIcon}
								label="Comparte tu enlace"
								color="green"
							/>
							<Step
								icon={UserPlusIcon}
								label="Tu amigo se registra"
								color="violet"
							/>
							<Step
								icon={StarIcon}
								label="Ambos reciben beneficios"
								color="amber"
							/>
						</div>
					</div>
				</div>
			</section>

			<section className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 sm:p-6">
				<div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
					<div>
						<h2 className="font-poppins text-2xl font-bold text-famedic-darker">
							Tu enlace de invitacion
						</h2>
						<p className="mt-1 text-sm text-slate-600">
							Comparte este enlace con tus amigos para que se
							registren en Famedic.
						</p>
					</div>
					<div className="inline-flex items-center gap-2 self-start rounded-full bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700">
						<LinkIcon className="size-5" />
						Enlace activo
					</div>
				</div>

				<div className="mt-5 grid gap-3 xl:grid-cols-[1fr_auto_auto_auto]">
					<input
						readOnly
						value={invitationUrl}
						className="h-12 min-w-0 rounded-lg border border-slate-300 bg-slate-50 px-4 text-sm text-slate-700 shadow-sm outline-none focus:border-famedic-light focus:ring-2 focus:ring-famedic-light/20"
					/>
					<a
						href={whatsappUrl}
						target="_blank"
						rel="noreferrer"
						className="inline-flex h-12 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-5 text-sm font-bold text-famedic-darker shadow-sm transition hover:bg-slate-50"
					>
						<ChatBubbleLeftRightIcon className="size-5 fill-emerald-500" />
						WhatsApp
					</a>
					<button
						type="button"
						onClick={copyInvitationLink}
						className="inline-flex h-12 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-5 text-sm font-bold text-famedic-darker shadow-sm transition hover:bg-slate-50"
					>
						{copied ? (
							<CheckIcon className="size-5 fill-emerald-600" />
						) : (
							<ClipboardDocumentIcon className="size-5 fill-slate-500" />
						)}
						{copied ? "Copiado" : "Copiar enlace"}
					</button>
					<button
						type="button"
						onClick={shareInvitationLink}
						className="inline-flex h-12 items-center justify-center gap-2 rounded-lg bg-famedic-dark px-5 text-sm font-bold text-white shadow-sm transition hover:bg-famedic-darker"
					>
						<ShareIcon className="size-5" />
						Compartir
					</button>
				</div>
				<p className="mt-2 text-right text-xs text-slate-500">
					Tu enlace siempre estara disponible.
				</p>
			</section>

			<section className="grid gap-4 lg:grid-cols-3">
				<MetricCard
					icon={UserGroupIcon}
					color="blue"
					value={invitationStats.invited ?? 0}
					label="Amigos invitados"
					description="Total de invitaciones registradas"
				/>
				<MetricCard
					icon={CheckCircleIcon}
					color="green"
					value={invitationStats.registered ?? 0}
					label="Se registraron"
					description="Amigos que completaron su registro"
				/>
				<MetricCard
					icon={GiftIcon}
					color="rose"
					value={invitationStats.benefits ?? 0}
					label="Beneficios obtenidos"
					description="Para ti y tus amigos"
				/>
			</section>

			<section className="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
				<div className="border-b border-slate-200 bg-slate-50 p-1">
					<div className="grid max-w-md grid-cols-2 gap-1">
						{tabs.map((tab) => (
							<button
								key={tab.id}
								type="button"
								onClick={() => setSelectedTab(tab.id)}
								className={clsx(
									"h-11 rounded-lg text-sm font-bold transition",
									selectedTab === tab.id
										? "bg-famedic-dark text-white shadow-sm"
										: "text-slate-600 hover:bg-white",
								)}
							>
								{tab.label}
							</button>
						))}
					</div>
				</div>

				{selectedTab === "invitations" ? (
					<InvitationsTable invitations={invitations} />
				) : (
					<BenefitsPanel invitations={registeredInvitations} />
				)}
			</section>
		</SettingsLayout>
	);
}

function Step({ icon: Icon, label, color }) {
	const colors = {
		green: "bg-emerald-100 text-emerald-600",
		violet: "bg-violet-100 text-violet-600",
		amber: "bg-amber-100 text-amber-600",
	};

	return (
		<div className="flex items-center gap-3">
			<div
				className={clsx(
					"grid size-12 shrink-0 place-items-center rounded-full",
					colors[color],
				)}
			>
				<Icon className="size-6" />
			</div>
			<p className="text-sm font-semibold text-famedic-darker">{label}</p>
		</div>
	);
}

function MetricCard({ icon: Icon, color, value, label, description }) {
	const colors = {
		blue: "bg-blue-50 text-blue-600",
		green: "bg-emerald-50 text-emerald-600",
		rose: "bg-rose-50 text-rose-600",
	};

	return (
		<div className="flex items-center gap-5 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
			<div
				className={clsx(
					"grid size-14 shrink-0 place-items-center rounded-full",
					colors[color],
				)}
			>
				<Icon className="size-7" />
			</div>
			<div className="min-w-0">
				<div className="flex items-baseline gap-3">
					<span className="text-3xl font-bold text-famedic-darker">
						{value}
					</span>
					<span className="font-semibold text-famedic-darker">
						{label}
					</span>
				</div>
				<p className="mt-1 text-sm text-slate-500">{description}</p>
			</div>
		</div>
	);
}

function InvitationsTable({ invitations }) {
	return (
		<div className="p-5">
			<h2 className="font-poppins text-xl font-bold text-famedic-darker">
				Invitaciones enviadas
			</h2>
			<p className="mt-1 text-sm text-slate-600">
				Aqui puedes ver el estado de tus invitaciones.
			</p>

			{invitations.length === 0 ? (
				<EmptyState />
			) : (
				<div className="mt-5 overflow-x-auto">
					<table className="min-w-full divide-y divide-slate-200 text-left text-sm">
						<thead>
							<tr className="text-xs font-bold text-slate-500">
								<th className="px-3 py-3">Nombre</th>
								<th className="px-3 py-3">Correo</th>
								<th className="px-3 py-3">
									Fecha de invitacion
								</th>
								<th className="px-3 py-3">Estado</th>
								<th className="px-3 py-3">Beneficio</th>
								<th className="w-8 px-3 py-3" />
							</tr>
						</thead>
						<tbody className="divide-y divide-slate-100">
							{invitations.map((invitation) => (
								<tr
									key={invitation.id}
									className="text-slate-700 transition hover:bg-slate-50"
								>
									<td className="px-3 py-3">
										<div className="flex items-center gap-3">
											<span className="grid size-8 place-items-center rounded-full bg-blue-50 text-xs font-bold text-blue-600">
												{invitation.initials}
											</span>
											<span className="font-medium text-slate-900">
												{invitation.name}
											</span>
										</div>
									</td>
									<td className="px-3 py-3 text-slate-500">
										{invitation.email}
									</td>
									<td className="px-3 py-3 text-slate-500">
										{invitation.invited_at}
									</td>
									<td className="px-3 py-3">
										<StatusBadge status={invitation.status}>
											{invitation.status_label}
										</StatusBadge>
									</td>
									<td className="px-3 py-3">
										{invitation.benefit_granted ? (
											<span className="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
												<GiftIcon className="size-4" />
												{invitation.benefit_label}
											</span>
										) : (
											<span className="text-slate-500">
												{invitation.benefit_label}
											</span>
										)}
									</td>
									<td className="px-3 py-3 text-slate-400">
										<ChevronRightIcon className="size-5" />
									</td>
								</tr>
							))}
						</tbody>
					</table>
				</div>
			)}
		</div>
	);
}

function BenefitsPanel({ invitations }) {
	return (
		<div className="grid gap-4 p-5 lg:grid-cols-[1fr_.8fr]">
			<div>
				<h2 className="font-poppins text-xl font-bold text-famedic-darker">
					Beneficios por invitacion
				</h2>
				<p className="mt-1 text-sm text-slate-600">
					Los beneficios se activan cuando tus amigos completan su
					registro con tu enlace.
				</p>
				<div className="mt-5 space-y-3">
					{invitations.length === 0 ? (
						<p className="rounded-lg bg-slate-50 p-4 text-sm text-slate-600">
							Aun no hay beneficios otorgados. Comparte tu enlace
							para empezar.
						</p>
					) : (
						invitations.map((invitation) => (
							<div
								key={invitation.id}
								className="flex items-center justify-between rounded-lg border border-slate-200 p-4"
							>
								<div>
									<p className="font-semibold text-famedic-darker">
										{invitation.name}
									</p>
									<p className="text-sm text-slate-500">
										Registro completado el{" "}
										{invitation.invited_at}
									</p>
								</div>
								<span className="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
									<GiftIcon className="size-4" />
									Otorgado
								</span>
							</div>
						))
					)}
				</div>
			</div>
			<div className="rounded-xl bg-gradient-to-br from-famedic-dark to-violet-700 p-6 text-white">
				<SparklesIcon className="size-8 text-famedic-lime" />
				<h3 className="mt-4 font-poppins text-2xl font-bold">
					Comparte salud, suma beneficios
				</h3>
				<p className="mt-3 text-sm leading-6 text-white/80">
					Tu enlace es unico y esta disponible en todo momento para
					que puedas invitar a familiares, amigos o pacientes
					frecuentes.
				</p>
			</div>
		</div>
	);
}

function StatusBadge({ status, children }) {
	const classes = {
		registered: "bg-emerald-50 text-emerald-700",
		pending: "bg-blue-50 text-blue-700",
		expired: "bg-rose-50 text-rose-700",
	};

	return (
		<span
			className={clsx(
				"inline-flex rounded-full px-3 py-1 text-xs font-semibold",
				classes[status] || classes.pending,
			)}
		>
			{children}
		</span>
	);
}

function EmptyState() {
	return (
		<div className="mt-5 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center">
			<PaperAirplaneIcon className="mx-auto size-10 text-slate-400" />
			<h3 className="mt-3 font-poppins text-lg font-bold text-famedic-darker">
				Aun no tienes invitados registrados
			</h3>
			<p className="mx-auto mt-2 max-w-md text-sm text-slate-600">
				Comparte tu enlace por WhatsApp o copialo para que tus amigos se
				registren en Famedic.
			</p>
		</div>
	);
}
