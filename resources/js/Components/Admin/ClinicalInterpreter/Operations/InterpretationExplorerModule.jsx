import { router } from "@inertiajs/react";
import { useState } from "react";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Input } from "@/Components/Catalyst/input";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";

function statusTone(status) {
	if (status === "completed") return "emerald";
	if (status === "checkout_started" || status === "cart_prepared") return "sky";
	if (status === "cancelled") return "red";
	return "zinc";
}

export default function InterpretationExplorerModule({ data }) {
	const filters = data?.filters || {};
	const [form, setForm] = useState({
		q: filters.q || "",
		status: filters.status || "",
		operator: filters.operator || "",
		laboratory: filters.laboratory || "",
		model: filters.model || "",
		prompt: filters.prompt || "",
		confidence: filters.confidence || "",
		from: filters.from || "",
		to: filters.to || "",
	});

	const apply = (e) => {
		e?.preventDefault?.();
		router.get(
			route("admin.clinical-interpreter.operations"),
			{ module: "explorer", ...form },
			{ preserveState: true, replace: true },
		);
	};

	const clear = () => {
		const empty = {
			q: "",
			status: "",
			operator: "",
			laboratory: "",
			model: "",
			prompt: "",
			confidence: "",
			from: "",
			to: "",
		};
		setForm(empty);
		router.get(
			route("admin.clinical-interpreter.operations"),
			{ module: "explorer" },
			{ preserveState: true, replace: true },
		);
	};

	return (
		<div className="space-y-5">
			<form
				onSubmit={apply}
				className="grid gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 sm:grid-cols-2 lg:grid-cols-3"
			>
				<Field>
					<Label>Buscar</Label>
					<Input
						value={form.q}
						onChange={(e) => setForm({ ...form, q: e.target.value })}
						placeholder="Paciente, UUID, operador…"
					/>
				</Field>
				<Field>
					<Label>Estado</Label>
					<select
						className="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950"
						value={form.status}
						onChange={(e) => setForm({ ...form, status: e.target.value })}
					>
						<option value="">Todos</option>
						{(data?.status_options || []).map((opt) => (
							<option key={opt.value} value={opt.value}>
								{opt.label}
							</option>
						))}
					</select>
				</Field>
				<Field>
					<Label>Operador</Label>
					<Input
						value={form.operator}
						onChange={(e) => setForm({ ...form, operator: e.target.value })}
						placeholder="Nombre o correo"
					/>
				</Field>
				<Field>
					<Label>Laboratorio</Label>
					<Input
						value={form.laboratory}
						onChange={(e) => setForm({ ...form, laboratory: e.target.value })}
					/>
				</Field>
				<Field>
					<Label>Modelo</Label>
					<Input
						value={form.model}
						onChange={(e) => setForm({ ...form, model: e.target.value })}
						placeholder="gpt-4o"
					/>
				</Field>
				<Field>
					<Label>Prompt</Label>
					<Input
						value={form.prompt}
						onChange={(e) => setForm({ ...form, prompt: e.target.value })}
					/>
				</Field>
				<Field>
					<Label>Confianza</Label>
					<select
						className="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950"
						value={form.confidence}
						onChange={(e) => setForm({ ...form, confidence: e.target.value })}
					>
						<option value="">Todas</option>
						<option value="high">Alta (≥95)</option>
						<option value="medium">Media (80–94)</option>
						<option value="low">Baja (&lt;80)</option>
					</select>
				</Field>
				<Field>
					<Label>Desde</Label>
					<Input
						type="date"
						value={form.from}
						onChange={(e) => setForm({ ...form, from: e.target.value })}
					/>
				</Field>
				<Field>
					<Label>Hasta</Label>
					<Input
						type="date"
						value={form.to}
						onChange={(e) => setForm({ ...form, to: e.target.value })}
					/>
				</Field>
				<div className="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-3">
					<Button type="submit" className="!text-sm">
						Filtrar
					</Button>
					<Button type="button" outline className="!text-sm" onClick={clear}>
						Limpiar
					</Button>
					<p className="text-xs text-zinc-400">{data?.total ?? 0} resultados</p>
				</div>
			</form>

			<section className="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
				<Table dense>
					<TableHead>
						<TableRow>
							<TableHeader>Orden</TableHeader>
							<TableHeader>Paciente</TableHeader>
							<TableHeader>Operador</TableHeader>
							<TableHeader>Estado</TableHeader>
							<TableHeader>Confianza</TableHeader>
							<TableHeader>Modelo</TableHeader>
							<TableHeader>Prompt</TableHeader>
							<TableHeader></TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{(data?.rows || []).length === 0 ? (
							<TableRow>
								<TableCell colSpan={8} className="text-zinc-400">
									No hay Laboratory Orders con estos filtros.
								</TableCell>
							</TableRow>
						) : (
							data.rows.map((row) => (
								<TableRow key={row.uuid}>
									<TableCell className="font-medium">#{row.id}</TableCell>
									<TableCell>{row.patient_name || "—"}</TableCell>
									<TableCell className="text-xs">
										{row.operator_name || "—"}
									</TableCell>
									<TableCell>
										<Badge
											color={statusTone(row.status)}
											className="!text-[10px]"
										>
											{row.status_label || row.status}
										</Badge>
									</TableCell>
									<TableCell className="tabular-nums">
										{row.confidence != null
											? `${Math.round(
													Number(row.confidence) <= 1
														? Number(row.confidence) * 100
														: Number(row.confidence),
												)}%`
											: "—"}
									</TableCell>
									<TableCell className="text-xs">{row.model || "—"}</TableCell>
									<TableCell className="text-xs">
										{[row.prompt_key, row.prompt_version]
											.filter(Boolean)
											.join(" · ") || "—"}
									</TableCell>
									<TableCell>
										<Button
											plain
											href={row.show_url}
											className="!text-xs"
										>
											Abrir
										</Button>
									</TableCell>
								</TableRow>
							))
						)}
					</TableBody>
				</Table>
			</section>
		</div>
	);
}
