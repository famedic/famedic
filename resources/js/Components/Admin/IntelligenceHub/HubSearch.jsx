import { useMemo, useState } from "react";
import { Link } from "@inertiajs/react";
import { MagnifyingGlassIcon } from "@heroicons/react/16/solid";

export default function HubSearch({ items = [] }) {
	const [query, setQuery] = useState("");

	const results = useMemo(() => {
		const q = query.trim().toLowerCase();
		if (q.length < 2) {
			return [];
		}

		return items
			.filter((item) => {
				const haystack = [
					item.title,
					item.description,
					item.suite,
					item.type,
				]
					.filter(Boolean)
					.join(" ")
					.toLowerCase();
				return haystack.includes(q);
			})
			.slice(0, 8);
	}, [items, query]);

	return (
		<div className="relative">
			<div className="flex items-center gap-2 rounded-2xl border border-zinc-200 bg-white px-4 py-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<MagnifyingGlassIcon className="size-5 text-zinc-400" />
				<input
					type="search"
					value={query}
					onChange={(e) => setQuery(e.target.value)}
					placeholder="Buscar módulos, suites, dashboards, insights…"
					className="w-full border-0 bg-transparent text-sm text-zinc-800 outline-none placeholder:text-zinc-400 focus:ring-0 dark:text-zinc-100"
				/>
			</div>

			{results.length > 0 ? (
				<div className="absolute z-20 mt-2 w-full overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
					<ul className="max-h-80 overflow-y-auto py-2">
						{results.map((item) => (
							<li key={`${item.type}-${item.suite_slug}-${item.title}`}>
								{item.href ? (
									<Link
										href={item.href}
										className="block px-4 py-2.5 transition hover:bg-zinc-50 dark:hover:bg-zinc-800"
										onClick={() => setQuery("")}
									>
										<p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">
											{item.title}
										</p>
										<p className="text-xs text-zinc-500">
											{item.type === "suite" ? "Suite" : "Módulo"} ·{" "}
											{item.suite}
										</p>
									</Link>
								) : (
									<div className="px-4 py-2.5 opacity-60">
										<p className="text-sm font-medium">{item.title}</p>
										<p className="text-xs text-zinc-500">Próximamente</p>
									</div>
								)}
							</li>
						))}
					</ul>
				</div>
			) : null}
		</div>
	);
}
