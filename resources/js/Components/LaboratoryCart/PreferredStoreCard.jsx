import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { MapPinIcon } from "@heroicons/react/20/solid";

export default function PreferredStoreCard({
	selectedStore = null,
	onChoose,
	onChange,
	loading = false,
}) {
	const hasSelection = Boolean(selectedStore);

	if (loading && !hasSelection) {
		return (
			<Card className="border-zinc-200 bg-zinc-50/60 p-4 dark:border-slate-800 dark:bg-slate-900/40">
				<div className="h-20 animate-pulse rounded-lg bg-zinc-200 dark:bg-slate-800" />
			</Card>
		);
	}

	if (!hasSelection) {
		return (
			<Card className="border-zinc-200 bg-zinc-50/60 p-4 dark:border-slate-800 dark:bg-slate-900/40">
				<div className="flex items-start gap-3">
					<MapPinIcon className="mt-0.5 size-5 shrink-0 text-zinc-500 dark:text-slate-400" />
					<div className="min-w-0 flex-1">
						<Subheading
							id="compatible-stores-heading"
							className="text-base text-zinc-900 dark:text-white"
						>
							Sucursal de preferencia
						</Subheading>
						<Text className="mt-2 text-sm text-zinc-700 dark:text-slate-300">
							Aún no has seleccionado una.
						</Text>
						<Text className="mt-1 text-sm text-zinc-600 dark:text-slate-400">
							Es opcional. Puedes elegirla ahora o continuar sin
							seleccionarla.
						</Text>
						<Button
							type="button"
							outline
							className="mt-4 !py-2.5"
							onClick={onChoose}
						>
							Elegir sucursal
						</Button>
					</div>
				</div>
			</Card>
		);
	}

	return (
		<Card className="border-emerald-200 bg-emerald-50/60 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/20">
			<div className="flex items-start justify-between gap-3">
				<div className="min-w-0 flex-1">
					<div className="flex flex-wrap items-center gap-2">
						<Text className="text-sm font-medium text-emerald-900 dark:text-emerald-200">
							📍 Sucursal de preferencia
						</Text>
						<Badge color="green">Seleccionada</Badge>
					</div>
					<Subheading className="mt-2 text-base">
						{selectedStore.name}
					</Subheading>
					{selectedStore.address && (
						<Text className="mt-1 text-sm text-zinc-700 dark:text-slate-300">
							{selectedStore.address}
						</Text>
					)}
					<Text className="mt-3 text-sm text-zinc-600 dark:text-slate-400">
						Sucursal seleccionada como preferencia. La cita y horario
						se confirman contigo después.
					</Text>
				</div>
				<Button
					type="button"
					plain
					className="shrink-0 self-start !py-2 text-sm"
					onClick={onChange}
				>
					Cambiar
				</Button>
			</div>
		</Card>
	);
}
