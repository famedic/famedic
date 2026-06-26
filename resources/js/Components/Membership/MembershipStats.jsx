import Card from "@/Components/Card";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import {
	ClipboardDocumentIcon,
	CheckIcon,
	PhoneIcon,
	CalendarDaysIcon,
	IdentificationIcon,
} from "@heroicons/react/24/outline";
import { useState } from "react";
import clsx from "clsx";

function StatCard({ icon: Icon, label, children, action }) {
	return (
		<Card className="rounded-2xl p-4 shadow-sm ring-1 ring-slate-100 sm:p-5">
			<div className="flex items-start justify-between gap-3">
				<div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300">
					<Icon className="size-5" />
				</div>
				{action}
			</div>
			<div className="mt-4 space-y-1">
				<Text className="text-xs font-medium uppercase tracking-wide text-zinc-400">
					{label}
				</Text>
				{children}
			</div>
		</Card>
	);
}

export default function MembershipStats({ access, status, plan }) {
	const [copied, setCopied] = useState(false);

	const copyIdentifier = async () => {
		if (!access?.identifier) {
			return;
		}

		try {
			await navigator.clipboard.writeText(access.identifier);
			setCopied(true);
			window.setTimeout(() => setCopied(false), 2000);
		} catch {
			setCopied(false);
		}
	};

	const isActive = status?.status === "active";

	return (
		<div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
			<StatCard
				icon={IdentificationIcon}
				label="ID de membresía"
				action={
					access?.identifier ? (
						<Button
							plain
							type="button"
							onClick={copyIdentifier}
							className="!px-2 !py-1 !text-xs"
							aria-label="Copiar ID de membresía"
						>
							{copied ? (
								<CheckIcon className="size-4 text-emerald-500" />
							) : (
								<ClipboardDocumentIcon className="size-4" />
							)}
							{copied ? "Copiado" : "Copiar"}
						</Button>
					) : null
				}
			>
				<p className="font-poppins text-xl font-bold tracking-tight text-famedic-dark dark:text-white">
					{access?.identifier ?? "—"}
				</p>
			</StatCard>

			<StatCard
				icon={PhoneIcon}
				label="Línea médica"
				action={
					access?.telHref ? (
						<Button
							plain
							href={access.telHref}
							className="!px-2 !py-1 !text-xs"
						>
							<PhoneIcon className="size-4" />
							Llamar
						</Button>
					) : null
				}
			>
				<p className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
					{access?.formattedPhone ?? "—"}
				</p>
				{access?.lineLabel && (
					<Text className="text-xs text-zinc-500">{access.lineLabel}</Text>
				)}
			</StatCard>

			<StatCard icon={CheckIcon} label="Estado">
				<p
					className={clsx(
						"inline-flex items-center gap-2 font-poppins text-lg font-semibold",
						isActive ? "text-emerald-600" : "text-zinc-500",
					)}
				>
					<span
						className={clsx(
							"size-2.5 rounded-full",
							isActive ? "bg-emerald-500" : "bg-zinc-400",
						)}
					/>
					{status?.statusLabel ?? "—"}
				</p>
			</StatCard>

			<StatCard icon={CalendarDaysIcon} label="Próxima renovación">
				<p className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
					{plan?.renewalDate ?? status?.endDate ?? "—"}
				</p>
				<Text className="text-xs text-zinc-500">
					{plan?.paymentType ?? "—"}
				</Text>
			</StatCard>
		</div>
	);
}
