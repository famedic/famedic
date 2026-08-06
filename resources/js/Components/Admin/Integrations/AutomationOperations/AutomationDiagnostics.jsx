import { useState } from "react";
import axios from "axios";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { PlayIcon } from "@heroicons/react/16/solid";

const STATUS_COLOR = {
	success: "lime",
	failed: "rose",
	skipped: "zinc",
	idle: "zinc",
};

export default function AutomationDiagnostics({ catalog = [], diagnosticUrl }) {
	const [running, setRunning] = useState(null);
	const [results, setResults] = useState({});

	const run = async (key) => {
		setRunning(key);
		try {
			const { data } = await axios.post(diagnosticUrl, { key });
			setResults((prev) => ({
				...prev,
				[key]: data.result,
			}));
		} catch (error) {
			setResults((prev) => ({
				...prev,
				[key]: {
					key,
					label: key,
					status: "failed",
					message:
						error?.response?.data?.message ||
						error.message ||
						"Error al ejecutar diagnóstico",
				},
			}));
		} finally {
			setRunning(null);
		}
	};

	return (
		<div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
			{catalog.map((item) => {
				const result = results[item.key];
				return (
					<div
						key={item.key}
						className="flex flex-col rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"
					>
						<div className="flex items-start justify-between gap-2">
							<div>
								<h3 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
									{item.label}
								</h3>
								<Text className="mt-1 text-xs text-zinc-500">
									{item.description}
								</Text>
							</div>
							{result ? (
								<Badge color={STATUS_COLOR[result.status] || "zinc"}>
									{result.status}
								</Badge>
							) : (
								<Badge color="zinc">idle</Badge>
							)}
						</div>

						{result ? (
							<div className="mt-3 rounded-lg bg-zinc-50 p-3 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
								<p>{result.message}</p>
								{result.duration_ms != null ? (
									<p className="mt-1 tabular-nums text-zinc-400">
										{result.duration_ms} ms
									</p>
								) : null}
							</div>
						) : null}

						<div className="mt-auto pt-4">
							<Button
								onClick={() => run(item.key)}
								disabled={running === item.key}
								className="w-full"
							>
								<PlayIcon />
								{running === item.key ? "Ejecutando…" : "Ejecutar test"}
							</Button>
						</div>
					</div>
				);
			})}
		</div>
	);
}
