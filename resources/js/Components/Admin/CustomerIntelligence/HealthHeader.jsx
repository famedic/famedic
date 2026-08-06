import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import {
	ArrowPathIcon,
	ArrowLeftIcon,
	PresentationChartLineIcon,
	UserGroupIcon,
	HeartIcon,
} from "@heroicons/react/16/solid";

export default function HealthHeader({
	customersIndexUrl,
	journeyUrl,
	cohortsUrl,
	dormantUrl,
	onRefresh,
	refreshing = false,
	generatedAt,
	sampleSize,
}) {
	return (
		<div className="flex flex-wrap items-start justify-between gap-4">
			<div className="space-y-2">
				<div className="flex flex-wrap gap-2">
					<Button href={customersIndexUrl} outline className="inline-flex items-center gap-2">
						<ArrowLeftIcon className="size-4" />
						Clientes
					</Button>
					<Button href={journeyUrl} outline className="inline-flex items-center gap-2">
						<PresentationChartLineIcon className="size-4" />
						Journey
					</Button>
					<Button href={cohortsUrl} outline className="inline-flex items-center gap-2">
						<HeartIcon className="size-4" />
						Cohorts
					</Button>
					<Button href={dormantUrl} outline className="inline-flex items-center gap-2">
						<UserGroupIcon className="size-4" />
						Dormidos
					</Button>
				</div>
				<div>
					<p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-famedic-light">
						Customer Intelligence Center
					</p>
					<Heading className="mt-1">Customer Health Score</Heading>
					<Text className="mt-1 max-w-2xl text-sm text-zinc-500 dark:text-zinc-400">
						Salud predictiva de cada cliente con scoring automático, personas y
						recomendaciones de acción para Marketing y Growth.
					</Text>
					{generatedAt ? (
						<Text className="mt-1 text-xs text-zinc-400">
							Actualizado {generatedAt}
							{sampleSize ? ` · muestra ${Number(sampleSize).toLocaleString()}` : ""}
						</Text>
					) : null}
				</div>
			</div>
			<Button outline onClick={onRefresh} disabled={refreshing}>
				<ArrowPathIcon className="size-4" />
				Actualizar
			</Button>
		</div>
	);
}
