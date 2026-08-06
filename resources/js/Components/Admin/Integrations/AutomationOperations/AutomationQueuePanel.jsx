import { useState } from "react";
import axios from "axios";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

const STATUS_COLOR = {
	pending: "amber",
	running: "sky",
	retrying: "orange",
	completed: "lime",
	failed: "rose",
	dead_letter: "rose",
	open: "rose",
	requeued: "sky",
	discarded: "zinc",
};

function formatTime(iso) {
	if (!iso) return "—";
	try {
		return new Intl.DateTimeFormat("es-MX", {
			dateStyle: "short",
			timeStyle: "medium",
		}).format(new Date(iso));
	} catch {
		return iso;
	}
}

export default function AutomationQueuePanel({
	queue,
	queueActionUrl,
	onChanged,
}) {
	const [busy, setBusy] = useState(null);
	const counts = queue?.counts || {};
	const runs = queue?.runs || [];
	const deadLetters = queue?.dead_letters || [];

	const act = async (action, ids) => {
		const key = `${action}-${ids.run_id || ids.dead_letter_id}`;
		setBusy(key);
		try {
			await axios.post(queueActionUrl, { action, ...ids });
			onChanged?.();
		} catch (error) {
			window.alert(
				error?.response?.data?.message ||
					error.message ||
					"No se pudo completar la acción",
			);
		} finally {
			setBusy(null);
		}
	};

	if (queue?.ready === false) {
		return (
			<div className="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
				<Text className="text-sm text-zinc-500">
					{queue?.meta?.message ||
						"Migraciones de cola pendientes (automation_runs)."}
				</Text>
			</div>
		);
	}

	return (
		<div className="space-y-6">
			<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
				{[
					["Pending", counts.pending],
					["Running", counts.running],
					["Retrying", counts.retrying],
					["Completed", counts.completed],
					["Dead Letter", counts.dead_letter_open ?? counts.dead_letter],
				].map(([label, value]) => (
					<div
						key={label}
						className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
					>
						<p className="text-[11px] uppercase tracking-wide text-zinc-500">
							{label}
						</p>
						<p className="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
							{value ?? 0}
						</p>
					</div>
				))}
			</div>

			{queue?.meta ? (
				<p className="text-xs text-zinc-500">
					Cola <code>{queue.meta.queue}</code> · max attempts{" "}
					{queue.meta.max_attempts} · backoff{" "}
					{(queue.meta.backoff_seconds || []).join(" / ")}s
				</p>
			) : null}

			<section className="space-y-3">
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Runs recientes
				</h2>
				<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
					<div className="overflow-x-auto">
						<table className="min-w-full text-left text-sm">
							<thead className="border-b border-zinc-200 bg-zinc-50 text-[11px] uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60">
								<tr>
									<th className="px-3 py-2">UUID</th>
									<th className="px-3 py-2">Driver</th>
									<th className="px-3 py-2">Status</th>
									<th className="px-3 py-2">Attempt</th>
									<th className="px-3 py-2">ms</th>
									<th className="px-3 py-2">Next retry</th>
									<th className="px-3 py-2">Acciones</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
								{runs.length === 0 ? (
									<tr>
										<td
											colSpan={7}
											className="px-3 py-6 text-center text-zinc-500"
										>
											Sin runs todavía.
										</td>
									</tr>
								) : (
									runs.map((run) => (
										<tr key={run.id}>
											<td className="max-w-[140px] truncate px-3 py-2 font-mono text-[11px] text-zinc-500">
												{run.automation_uuid}
											</td>
											<td className="px-3 py-2">
												<p className="font-medium">{run.driver}</p>
												<p className="text-xs text-zinc-400">
													{run.handler}
												</p>
											</td>
											<td className="px-3 py-2">
												<Badge color={STATUS_COLOR[run.status] || "zinc"}>
													{run.status}
												</Badge>
											</td>
											<td className="px-3 py-2 tabular-nums">
												{run.attempt}
											</td>
											<td className="px-3 py-2 tabular-nums">
												{run.duration_ms ?? "—"}
											</td>
											<td className="px-3 py-2 text-xs text-zinc-500">
												{formatTime(run.next_retry_at)}
											</td>
											<td className="px-3 py-2">
												{run.status !== "completed" ? (
													<Button
														plain
														disabled={busy === `retry-${run.id}`}
														onClick={() =>
															act("retry", { run_id: run.id })
														}
													>
														Retry Manual
													</Button>
												) : (
													<span className="text-xs text-zinc-400">—</span>
												)}
											</td>
										</tr>
									))
								)}
							</tbody>
						</table>
					</div>
				</div>
			</section>

			<section className="space-y-3">
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Dead Letters
				</h2>
				<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
					<div className="overflow-x-auto">
						<table className="min-w-full text-left text-sm">
							<thead className="border-b border-zinc-200 bg-zinc-50 text-[11px] uppercase tracking-wide text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/60">
								<tr>
									<th className="px-3 py-2">UUID</th>
									<th className="px-3 py-2">Driver</th>
									<th className="px-3 py-2">Error</th>
									<th className="px-3 py-2">Attempts</th>
									<th className="px-3 py-2">Status</th>
									<th className="px-3 py-2">Acciones</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
								{deadLetters.length === 0 ? (
									<tr>
										<td
											colSpan={6}
											className="px-3 py-6 text-center text-zinc-500"
										>
											Sin dead letters.
										</td>
									</tr>
								) : (
									deadLetters.map((dl) => (
										<tr key={dl.id}>
											<td className="max-w-[120px] truncate px-3 py-2 font-mono text-[11px] text-zinc-500">
												{dl.automation_uuid}
											</td>
											<td className="px-3 py-2">{dl.driver}</td>
											<td className="max-w-xs truncate px-3 py-2 text-xs text-zinc-600">
												{dl.error || "—"}
											</td>
											<td className="px-3 py-2 tabular-nums">
												{dl.attempts}
											</td>
											<td className="px-3 py-2">
												<Badge color={STATUS_COLOR[dl.status] || "zinc"}>
													{dl.status}
												</Badge>
											</td>
											<td className="px-3 py-2">
												<div className="flex flex-wrap gap-2">
													{dl.status === "open" ? (
														<>
															<Button
																plain
																disabled={
																	busy === `requeue-${dl.id}`
																}
																onClick={() =>
																	act("requeue", {
																		dead_letter_id: dl.id,
																	})
																}
															>
																Requeue
															</Button>
															<Button
																plain
																disabled={
																	busy === `discard-${dl.id}`
																}
																onClick={() =>
																	act("discard", {
																		dead_letter_id: dl.id,
																	})
																}
															>
																Discard
															</Button>
														</>
													) : (
														<span className="text-xs text-zinc-400">
															—
														</span>
													)}
												</div>
											</td>
										</tr>
									))
								)}
							</tbody>
						</table>
					</div>
				</div>
			</section>
		</div>
	);
}
