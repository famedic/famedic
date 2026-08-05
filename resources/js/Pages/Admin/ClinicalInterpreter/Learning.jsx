import { Link } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { ChevronRightIcon } from "@heroicons/react/16/solid";

function CorrectionsTable({ rows = [], empty }) {
	if (!rows.length) {
		return <p className="py-6 text-sm text-zinc-400">{empty}</p>;
	}

	return (
		<Table dense>
			<TableHead>
				<TableRow>
					<TableHeader>Detectado</TableHeader>
					<TableHeader>Confirmado</TableHeader>
					<TableHeader>Tipo</TableHeader>
					<TableHeader>Ocurrencias</TableHeader>
					<TableHeader>Última vez</TableHeader>
				</TableRow>
			</TableHead>
			<TableBody>
				{rows.map((row, index) => (
					<TableRow
						key={`${row.detected_text}-${row.confirmed_text}-${index}`}
					>
						<TableCell className="font-medium">{row.detected_text}</TableCell>
						<TableCell>{row.confirmed_text}</TableCell>
						<TableCell>
							<Badge color="zinc" className="!text-[10px]">
								{row.type}
							</Badge>
						</TableCell>
						<TableCell>{row.occurrences}</TableCell>
						<TableCell className="text-xs text-zinc-500">
							{row.last_seen_at
								? new Date(row.last_seen_at).toLocaleString("es-MX")
								: "—"}
						</TableCell>
					</TableRow>
				))}
			</TableBody>
		</Table>
	);
}

export default function Learning({
	meta,
	frequent_corrections = [],
	abbreviations = [],
	top_laboratory = [],
}) {
	return (
		<AdminLayout title="AI Learning">
			<div className="space-y-6 pb-8">
				<nav className="flex flex-wrap items-center gap-1.5 text-[11px] uppercase tracking-[0.12em]">
					<Link
						href={route("admin.clinical-interpreter.index")}
						className="font-medium text-zinc-400 hover:text-famedic-light"
					>
						AI Clinical Interpreter
					</Link>
					<ChevronRightIcon className="size-3 text-zinc-300" />
					<span className="font-semibold text-zinc-700 dark:text-zinc-200">
						AI Learning
					</span>
				</nav>

				<div className="space-y-1">
					<div className="flex flex-wrap items-center gap-2">
						<Heading>AI Learning</Heading>
						<Badge color="sky">Solo lectura</Badge>
					</div>
					<Text className="text-sm text-zinc-500">
						{meta?.note ||
							"v1.0 · Correcciones de estudios de laboratorio. No reentrena modelos ni llama a OpenAI."}
					</Text>
				</div>

				<section className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<h2 className="mb-3 text-sm font-semibold">Correcciones frecuentes</h2>
					<CorrectionsTable
						rows={frequent_corrections}
						empty="Aún no hay correcciones registradas."
					/>
				</section>

				<section className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<h2 className="mb-3 text-sm font-semibold">Abreviaturas detectadas</h2>
					<CorrectionsTable
						rows={abbreviations}
						empty="Sin abreviaturas cortas en el historial de aprendizaje."
					/>
				</section>

				<div className="grid grid-cols-1 gap-4">
					<section className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<h2 className="mb-3 text-sm font-semibold">
							Estudios más corregidos
						</h2>
						<CorrectionsTable
							rows={top_laboratory}
							empty="Sin correcciones de laboratorio."
						/>
					</section>
				</div>
			</div>
		</AdminLayout>
	);
}
