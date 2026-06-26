import Card from "@/Components/Card";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import {
	Dialog,
	DialogActions,
	DialogBody,
	DialogDescription,
	DialogTitle,
} from "@/Components/Catalyst/dialog";
import {
	HeartIcon,
	ClockIcon,
	UsersIcon,
	VideoCameraIcon,
	CheckIcon,
} from "@heroicons/react/24/outline";
import { SparklesIcon } from "@heroicons/react/24/solid";
import { useState } from "react";

const ICON_MAP = {
	heart: HeartIcon,
	clock: ClockIcon,
	brain: SparklesIcon,
	nutrition: SparklesIcon,
	family: UsersIcon,
	video: VideoCameraIcon,
};

const PREVIEW_COUNT = 6;

function BenefitsGrid({ benefits, compact = false }) {
	return (
		<div
			className={
				compact
					? "grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
					: "grid gap-3 sm:grid-cols-2"
			}
		>
			{benefits.map((benefit) => {
				const Icon = ICON_MAP[benefit.icon] ?? CheckIcon;

				return (
					<Card
						key={benefit.title}
						className="rounded-2xl p-4 shadow-sm ring-1 ring-slate-100"
					>
						<div className="flex items-start gap-3">
							<div className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
								<Icon className="size-4" />
							</div>
							<div className="min-w-0">
								<p className="text-sm font-medium text-zinc-800 dark:text-slate-100">
									{benefit.title}
								</p>
								<Text className="mt-0.5 text-xs text-zinc-500">
									{benefit.description}
								</Text>
							</div>
						</div>
					</Card>
				);
			})}
		</div>
	);
}

export default function MembershipBenefits({ benefits = [], compact = false }) {
	const [showAll, setShowAll] = useState(false);
	const previewBenefits = benefits.slice(0, PREVIEW_COUNT);
	const hasMore = benefits.length > PREVIEW_COUNT;

	if (compact) {
		return (
			<>
				<BenefitsGrid benefits={previewBenefits} compact />

				{hasMore && (
					<div className="mt-4 flex justify-center">
						<Button outline type="button" onClick={() => setShowAll(true)}>
							Ver todos los beneficios
						</Button>
					</div>
				)}

				<BenefitsModal
					open={showAll}
					onClose={setShowAll}
					benefits={benefits}
				/>
			</>
		);
	}

	return (
		<section className="space-y-4">
			<div>
				<h3 className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
					Beneficios principales
				</h3>
				<Text className="text-sm text-zinc-500">
					Lo esencial de tu membresía médica.
				</Text>
			</div>
			<BenefitsGrid benefits={previewBenefits} compact />
			{hasMore && (
				<div className="flex justify-center">
					<Button outline type="button" onClick={() => setShowAll(true)}>
						Ver todos los beneficios
					</Button>
				</div>
			)}
			<BenefitsModal
				open={showAll}
				onClose={setShowAll}
				benefits={benefits}
			/>
		</section>
	);
}

export function BenefitsModal({ open, onClose, benefits = [] }) {
	return (
		<Dialog open={open} onClose={onClose} size="3xl">
			<DialogTitle>Todos los beneficios</DialogTitle>
			<DialogDescription>
				Consulta el detalle completo de tu cobertura.
			</DialogDescription>
			<DialogBody>
				<div className="grid gap-3 sm:grid-cols-2">
					{benefits.map((benefit) => {
						const Icon = ICON_MAP[benefit.icon] ?? CheckIcon;

						return (
							<Card
								key={benefit.title}
								className="rounded-2xl p-4 ring-1 ring-slate-100"
							>
								<div className="flex items-start gap-3">
									<div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
										<Icon className="size-5" />
									</div>
									<div>
										<p className="font-medium text-zinc-800 dark:text-slate-100">
											{benefit.title}
										</p>
										<Text className="mt-1 text-sm text-zinc-500">
											{benefit.description}
										</Text>
									</div>
								</div>
							</Card>
						);
					})}
				</div>
			</DialogBody>
			<DialogActions>
				<Button type="button" onClick={() => onClose(false)}>
					Cerrar
				</Button>
			</DialogActions>
		</Dialog>
	);
}
