import { useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { Input } from "@/Components/Catalyst/input";
import { Text } from "@/Components/Catalyst/text";
import SectionHeader from "./SectionHeader";
import { provenanceForSection } from "./provenanceCatalog";

export default function DiagnosticsCard({ diagnostics, urls, updatedAt = null }) {
	const [email, setEmail] = useState("");
	const [processing, setProcessing] = useState(false);
	const flashMessage = usePage().props.flashMessage || null;
	const lastResult =
		flashMessage?.diagnostic || diagnostics?.last_result || null;

	const run = (action) => {
		setProcessing(true);
		router.post(
			urls.diagnostic,
			{ action, email },
			{
				preserveScroll: true,
				onFinish: () => setProcessing(false),
			},
		);
	};

	return (
		<section className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-5 dark:border-zinc-700 dark:bg-zinc-950/40">
			<SectionHeader
				title="Diagnostics"
				description="Acciones administrativas que reutilizan Mirror / Service existentes."
				provenance={provenanceForSection("diagnostics")}
				updatedAt={updatedAt}
			/>

			<div className="mb-4 max-w-md">
				<label className="mb-1 block text-[11px] font-medium uppercase tracking-wide text-zinc-500">
					Email (para acciones que lo requieren)
				</label>
				<Input
					type="email"
					value={email}
					onChange={(e) => setEmail(e.target.value)}
					placeholder="cliente@ejemplo.com"
				/>
			</div>

			<div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
				{(diagnostics?.actions || []).map((action) => (
					<button
						key={action.key}
						type="button"
						disabled={processing}
						onClick={() => run(action.key)}
						className="rounded-xl border border-zinc-200 bg-white p-3 text-left transition hover:border-zinc-300 hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600 dark:hover:bg-zinc-800"
					>
						<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							{action.label}
						</p>
						<p className="mt-1 text-[11px] leading-snug text-zinc-500">
							{action.description}
							{action.needs === "email" ? " · requiere email" : ""}
						</p>
					</button>
				))}
			</div>

			{lastResult ? (
				<div className="mt-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
					<p className="text-xs font-semibold uppercase tracking-wide text-zinc-500">
						Último resultado · {lastResult.action}
					</p>
					<p
						className={`mt-1 text-sm font-medium ${
							lastResult.ok
								? "text-emerald-700 dark:text-emerald-400"
								: "text-rose-700 dark:text-rose-400"
						}`}
					>
						{lastResult.message}
					</p>
					{lastResult.data && Object.keys(lastResult.data).length > 0 ? (
						<pre className="mt-3 max-h-64 overflow-auto rounded-lg bg-zinc-950 p-3 text-[11px] text-zinc-100">
							{JSON.stringify(lastResult.data, null, 2)}
						</pre>
					) : null}
					{lastResult.at_human ? (
						<Text className="mt-2 text-[11px] text-zinc-400">
							{lastResult.at_human}
						</Text>
					) : null}
				</div>
			) : null}

			<div className="mt-3">
				<Button outline disabled={processing} onClick={() => run("test_api")}>
					Ejecutar Test API
				</Button>
			</div>
		</section>
	);
}
