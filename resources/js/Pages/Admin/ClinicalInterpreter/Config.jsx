import { useState } from "react";
import { Link } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Input } from "@/Components/Catalyst/input";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { ChevronRightIcon } from "@heroicons/react/16/solid";

export default function Config({ active, catalog = [], meta }) {
	const [selectedVersion, setSelectedVersion] = useState(active?.version);
	const current =
		catalog.find((p) => p.version === selectedVersion) || active || {};

	const statusColor =
		current.status === "production"
			? "emerald"
			: current.status === "experimental"
				? "amber"
				: "zinc";

	return (
		<AdminLayout title="Configuración IA">
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
						Configuración IA
					</span>
				</nav>

				<div className="space-y-1">
					<div className="flex flex-wrap items-center gap-2">
						<Heading>Configuración IA</Heading>
						<Badge color={statusColor}>
							{current.status === "production"
								? "Producción"
								: current.status === "experimental"
									? "Experimental"
									: current.status || "—"}
						</Badge>
						<Badge color="zinc">PromptProvider</Badge>
					</div>
					<Text className="text-sm text-zinc-500">
						{meta?.note || "Solo lectura desde el repositorio de prompts."}
					</Text>
				</div>

				<section className="max-w-3xl space-y-5 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					{catalog.length > 1 && (
						<Field>
							<Label>Versión</Label>
							<select
								className="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950"
								value={current.version || ""}
								onChange={(e) => setSelectedVersion(e.target.value)}
							>
								{catalog.map((p) => (
									<option key={p.version} value={p.version}>
										{p.version} · {p.status} · {p.label}
									</option>
								))}
							</select>
						</Field>
					)}

					<div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
						<Field>
							<Label>Modelo</Label>
							<Input value={current.model || ""} readOnly />
						</Field>
						<Field>
							<Label>Estado</Label>
							<Input
								value={
									current.status === "production"
										? "Producción"
										: current.status === "experimental"
											? "Experimental"
											: current.status || ""
								}
								readOnly
							/>
						</Field>
						<Field>
							<Label>Temperatura</Label>
							<Input value={current.temperature ?? ""} readOnly />
						</Field>
						<Field>
							<Label>Top P</Label>
							<Input value={current.top_p ?? ""} readOnly />
						</Field>
						<Field>
							<Label>Max Tokens</Label>
							<Input value={current.max_tokens ?? ""} readOnly />
						</Field>
						<Field>
							<Label>Versión</Label>
							<Input value={current.version || ""} readOnly />
						</Field>
					</div>

					<Field>
						<Label>Prompt del sistema</Label>
						<Textarea rows={10} value={current.system_prompt || ""} readOnly />
					</Field>
					<Field>
						<Label>Prompt del usuario</Label>
						<Textarea rows={4} value={current.user_prompt || ""} readOnly />
					</Field>
				</section>
			</div>
		</AdminLayout>
	);
}
