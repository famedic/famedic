import { router } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { MagnifyingGlassIcon } from "@heroicons/react/16/solid";
import { Input } from "@/Components/Catalyst/input";
import { Button } from "@/Components/Catalyst/button";
import SectionHeader from "./SectionHeader";
import { provenanceForSection } from "./provenanceCatalog";

export default function GlobalSearch({ urls, filters = {}, results = null, updatedAt = null }) {
	const [q, setQ] = useState(filters.q || "");

	useEffect(() => {
		setQ(filters.q || "");
	}, [filters.q]);

	const search = (event) => {
		event?.preventDefault?.();
		router.get(
			urls.self,
			{
				...filters,
				q: q || undefined,
				preset: filters.preset || "7d",
				start_date: filters.start_date,
				end_date: filters.end_date,
			},
			{ preserveScroll: true, preserveState: true, replace: true },
		);
	};

	return (
		<section className="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<SectionHeader
				title="Centro de búsqueda"
				description="Email, nombre, Order ID, Dispatch ID, tag, lista, owner, automation."
				provenance={provenanceForSection("search")}
				updatedAt={updatedAt}
			/>
			<form onSubmit={search} className="flex flex-wrap items-end gap-3">
				<div className="min-w-[18rem] flex-1">
					<label className="mb-1 block text-[11px] font-medium uppercase tracking-wide text-zinc-500">
						Consulta
					</label>
					<div className="relative">
						<MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
						<Input
							className="pl-9"
							value={q}
							placeholder="Email, nombre, Order ID, Dispatch ID, tag, lista, owner, automation…"
							onChange={(e) => setQ(e.target.value)}
						/>
					</div>
				</div>
				<Button type="submit">Buscar</Button>
				{q ? (
					<Button
						outline
						type="button"
						onClick={() => {
							setQ("");
							router.get(
								urls.self,
								{
									preset: filters.preset || "7d",
									start_date: filters.start_date,
									end_date: filters.end_date,
								},
								{ preserveScroll: true, replace: true },
							);
						}}
					>
						Limpiar
					</Button>
				) : null}
			</form>

			{results?.length ? (
				<ul className="mt-4 divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
					{results.map((item, index) => (
						<li
							key={`${item.type}-${item.id}-${index}`}
							className="flex items-center justify-between gap-3 px-3 py-2 text-sm transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
						>
							<div className="min-w-0">
								<p className="truncate font-medium text-zinc-900 dark:text-zinc-50">
									{item.label}
								</p>
								<p className="truncate text-xs text-zinc-500">{item.meta}</p>
							</div>
							<span className="shrink-0 rounded bg-zinc-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
								{item.type}
							</span>
						</li>
					))}
				</ul>
			) : filters.q ? (
				<p className="mt-3 text-xs text-zinc-500">Sin resultados para «{filters.q}».</p>
			) : null}
		</section>
	);
}
