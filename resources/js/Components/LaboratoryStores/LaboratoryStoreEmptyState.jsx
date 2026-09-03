import { router } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { MagnifyingGlassIcon, XMarkIcon } from "@heroicons/react/24/outline";

export default function LaboratoryStoreEmptyState({ brand }) {
	function clearFilters() {
		router.get(route("laboratory-stores.index"), brand ? { brand } : {}, {
			preserveScroll: true,
			preserveState: true,
			replace: true,
		});
	}

	return (
		<div className="rounded-lg border border-dashed border-zinc-300 bg-white px-4 py-12 text-center shadow-sm dark:border-slate-700 dark:bg-slate-900">
			<MagnifyingGlassIcon className="mx-auto size-10 text-zinc-400 dark:text-slate-500" />
			<h3 className="mt-4 font-poppins text-lg font-semibold text-zinc-950 dark:text-white">
				No encontramos sucursales con estos filtros.
			</h3>
			<p className="mx-auto mt-2 max-w-md text-sm text-zinc-600 dark:text-slate-300">
				Prueba con otro municipio, codigo postal o estudio disponible.
			</p>
			<div className="mt-5">
				<Button type="button" outline onClick={clearFilters}>
					<XMarkIcon data-slot="icon" />
					Limpiar filtros
				</Button>
			</div>
		</div>
	);
}
