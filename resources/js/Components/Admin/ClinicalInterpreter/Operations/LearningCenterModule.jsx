import { Badge } from "@/Components/Catalyst/badge";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";

function ListCard({ title, rows, empty, render }) {
	return (
		<section className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
			<h3 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
				{title}
			</h3>
			{!rows?.length ? (
				<p className="mt-3 text-sm text-zinc-400">{empty}</p>
			) : (
				<ul className="mt-3 space-y-2">{rows.map(render)}</ul>
			)}
		</section>
	);
}

export default function LearningCenterModule({ data }) {
	return (
		<div className="space-y-5">
			<p className="text-sm text-zinc-500">
				{data?.note ||
					"Solo consume AiLearningService. No entrena modelos."}
			</p>

			<div className="grid gap-4 lg:grid-cols-2">
				<section className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 lg:col-span-2">
					<h3 className="text-sm font-semibold">Top estudios corregidos</h3>
					{!(data?.top_corrected || []).length ? (
						<p className="mt-3 text-sm text-zinc-400">
							Sin correcciones frecuentes todavía.
						</p>
					) : (
						<Table dense className="mt-2">
							<TableHead>
								<TableRow>
									<TableHeader>Detectado</TableHeader>
									<TableHeader>Confirmado</TableHeader>
									<TableHeader>Ocurrencias</TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{data.top_corrected.map((row, i) => (
									<TableRow key={`${row.detected}-${i}`}>
										<TableCell className="font-medium">{row.detected}</TableCell>
										<TableCell>{row.confirmed}</TableCell>
										<TableCell>{row.occurrences}</TableCell>
									</TableRow>
								))}
							</TableBody>
						</Table>
					)}
				</section>

				<ListCard
					title="Sinónimos nuevos"
					rows={data?.new_synonyms}
					empty="Sin variantes distintas detectado → confirmado."
					render={(row, i) => (
						<li
							key={`${row.variant}-${i}`}
							className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-950"
						>
							<span>
								{row.variant}{" "}
								<span className="text-zinc-400">→</span> {row.canonical}
							</span>
							<Badge color="zinc">{row.occurrences}</Badge>
						</li>
					)}
				/>

				<ListCard
					title="Variantes nuevas"
					rows={data?.new_variants}
					empty="Sin abreviaturas / variantes cortas."
					render={(row, i) => (
						<li
							key={`${row.text}-${i}`}
							className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-950"
						>
							<span>
								{row.text} <span className="text-zinc-400">→</span> {row.maps_to}
							</span>
							<Badge color="zinc">{row.occurrences}</Badge>
						</li>
					)}
				/>

				<section className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
					<h3 className="text-sm font-semibold">Laboratorios nuevos</h3>
					<p className="mt-3 text-sm text-zinc-400">
						{(data?.new_laboratories || []).length
							? null
							: "Sin señal de laboratorios nuevos en learning (v1 labs-only)."}
					</p>
				</section>

				<section className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
					<h3 className="text-sm font-semibold">Ranking de aprendizaje</h3>
					{!(data?.ranking || []).length ? (
						<p className="mt-3 text-sm text-zinc-400">Sin ranking todavía.</p>
					) : (
						<ol className="mt-3 space-y-2">
							{data.ranking.map((row) => (
								<li
									key={row.rank}
									className="flex items-start gap-3 text-sm"
								>
									<span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-900 text-[10px] font-bold text-white dark:bg-zinc-100 dark:text-zinc-900">
										{row.rank}
									</span>
									<div className="min-w-0">
										<p className="font-medium text-zinc-800 dark:text-zinc-100">
											{row.label}
										</p>
										<p className="text-xs text-zinc-400">
											Score · {row.score}
										</p>
									</div>
								</li>
							))}
						</ol>
					)}
				</section>
			</div>
		</div>
	);
}
