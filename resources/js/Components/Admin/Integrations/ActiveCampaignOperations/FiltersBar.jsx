import { router } from "@inertiajs/react";
import { useMemo, useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import { Input } from "@/Components/Catalyst/input";
import clsx from "clsx";
import SectionHeader from "./SectionHeader";
import { provenanceForSection } from "./provenanceCatalog";

export default function FiltersBar({ filters = {}, urls, updatedAt = null }) {
	const [preset, setPreset] = useState(filters.preset || "7d");
	const [startDate, setStartDate] = useState(filters.start_date || "");
	const [endDate, setEndDate] = useState(filters.end_date || "");
	const [laboratory, setLaboratory] = useState(filters.laboratory || "");
	const [branch, setBranch] = useState(filters.branch || "");
	const [purchaseType, setPurchaseType] = useState(filters.purchase_type || "");
	const [membership, setMembership] = useState(filters.membership || "");
	const [owner, setOwner] = useState(filters.owner || "");

	const presets = filters.presets || [
		{ key: "today", label: "Hoy" },
		{ key: "7d", label: "7 días" },
		{ key: "30d", label: "30 días" },
		{ key: "90d", label: "90 días" },
		{ key: "custom", label: "Personalizado" },
	];

	const query = useMemo(
		() => ({
			preset,
			start_date: startDate || undefined,
			end_date: endDate || undefined,
			laboratory: laboratory || undefined,
			branch: branch || undefined,
			purchase_type: purchaseType || undefined,
			membership: membership || undefined,
			owner: owner || undefined,
			q: filters.q || undefined,
		}),
		[
			preset,
			startDate,
			endDate,
			laboratory,
			branch,
			purchaseType,
			membership,
			owner,
			filters.q,
		],
	);

	const apply = (overrides = {}) => {
		router.get(urls.self, { ...query, ...overrides }, {
			preserveScroll: true,
			preserveState: true,
			replace: true,
		});
	};

	return (
		<section className="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<SectionHeader
				title="Filtros"
				description="Periodo y dimensiones operativas · aplican a todos los dashboards."
				provenance={provenanceForSection("filters")}
				updatedAt={updatedAt}
				action={<Button onClick={() => apply()}>Aplicar</Button>}
			/>

			<div className="mb-3 flex flex-wrap gap-2">
				{presets.map((item) => (
					<button
						key={item.key}
						type="button"
						onClick={() => {
							setPreset(item.key);
							apply({ preset: item.key });
						}}
						className={clsx(
							"rounded-lg border px-3 py-1.5 text-xs font-medium transition",
							preset === item.key
								? "border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900"
								: "border-zinc-200 bg-zinc-50 text-zinc-700 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-300",
						)}
					>
						{item.label}
					</button>
				))}
			</div>

			<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
				{preset === "custom" ? (
					<>
						<div>
							<label className="mb-1 block text-[11px] uppercase tracking-wide text-zinc-500">
								Desde
							</label>
							<Input
								type="date"
								value={startDate}
								onChange={(e) => setStartDate(e.target.value)}
							/>
						</div>
						<div>
							<label className="mb-1 block text-[11px] uppercase tracking-wide text-zinc-500">
								Hasta
							</label>
							<Input
								type="date"
								value={endDate}
								onChange={(e) => setEndDate(e.target.value)}
							/>
						</div>
					</>
				) : null}
				<div>
					<label className="mb-1 block text-[11px] uppercase tracking-wide text-zinc-500">
						Laboratorio
					</label>
					<Input
						value={laboratory}
						placeholder="brand / lab"
						onChange={(e) => setLaboratory(e.target.value)}
					/>
				</div>
				<div>
					<label className="mb-1 block text-[11px] uppercase tracking-wide text-zinc-500">
						Sucursal
					</label>
					<Input
						value={branch}
						placeholder="sucursal"
						onChange={(e) => setBranch(e.target.value)}
					/>
				</div>
				<div>
					<label className="mb-1 block text-[11px] uppercase tracking-wide text-zinc-500">
						Tipo de compra
					</label>
					<Input
						value={purchaseType}
						placeholder="lab / pharmacy"
						onChange={(e) => setPurchaseType(e.target.value)}
					/>
				</div>
				<div>
					<label className="mb-1 block text-[11px] uppercase tracking-wide text-zinc-500">
						Membership
					</label>
					<Input
						value={membership}
						placeholder="tipo / estado"
						onChange={(e) => setMembership(e.target.value)}
					/>
				</div>
				<div>
					<label className="mb-1 block text-[11px] uppercase tracking-wide text-zinc-500">
						Owner
					</label>
					<Input
						value={owner}
						placeholder="email / nombre"
						onChange={(e) => setOwner(e.target.value)}
					/>
				</div>
			</div>
		</section>
	);
}
