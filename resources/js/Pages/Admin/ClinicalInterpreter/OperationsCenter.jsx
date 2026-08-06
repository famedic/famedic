import { Link, router } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Badge } from "@/Components/Catalyst/badge";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import OverviewModule from "@/Components/Admin/ClinicalInterpreter/Operations/OverviewModule";
import PromptManagementModule from "@/Components/Admin/ClinicalInterpreter/Operations/PromptManagementModule";
import ConfidenceAnalyticsModule from "@/Components/Admin/ClinicalInterpreter/Operations/ConfidenceAnalyticsModule";
import LearningCenterModule from "@/Components/Admin/ClinicalInterpreter/Operations/LearningCenterModule";
import InterpretationExplorerModule from "@/Components/Admin/ClinicalInterpreter/Operations/InterpretationExplorerModule";
import PerformanceModule from "@/Components/Admin/ClinicalInterpreter/Operations/PerformanceModule";
import SystemHealthModule from "@/Components/Admin/ClinicalInterpreter/Operations/SystemHealthModule";
import RoadmapModule from "@/Components/Admin/ClinicalInterpreter/Operations/RoadmapModule";

const MODULES = [
	{ id: "overview", label: "Overview" },
	{ id: "prompts", label: "Prompt Management" },
	{ id: "confidence", label: "Confidence Analytics" },
	{ id: "learning", label: "Learning Center" },
	{ id: "explorer", label: "Interpretation Explorer" },
	{ id: "performance", label: "Performance" },
	{ id: "health", label: "System Health" },
	{ id: "roadmap", label: "Roadmap" },
];

export default function OperationsCenter({
	meta = {},
	modules = {},
	module: activeModule = "overview",
}) {
	const current =
		MODULES.find((m) => m.id === activeModule)?.id || "overview";

	const selectModule = (id) => {
		router.get(
			route("admin.clinical-interpreter.operations"),
			{ module: id },
			{ preserveState: true, replace: true, preserveScroll: true },
		);
	};

	return (
		<AdminLayout title="AI Operations Center">
			<div className="space-y-6 pb-10">
				<nav
					aria-label="Breadcrumb"
					className="flex flex-wrap items-center gap-1.5 text-[11px] uppercase tracking-[0.12em]"
				>
					<span className="font-medium text-zinc-400">IA</span>
					<ChevronRightIcon className="size-3 text-zinc-300" />
					<Link
						href={route("admin.clinical-interpreter.index")}
						className="font-medium text-zinc-400 hover:text-famedic-light"
					>
						AI Clinical Interpreter
					</Link>
					<ChevronRightIcon className="size-3 text-zinc-300" />
					<span className="font-semibold text-zinc-700 dark:text-zinc-200">
						AI Operations Center
					</span>
				</nav>

				<header className="flex flex-wrap items-start justify-between gap-4">
					<div className="space-y-2">
						<div className="flex flex-wrap items-center gap-2">
							<h1 className="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
								AI Operations Center
							</h1>
							<Badge color="famedic" className="!text-[10px]">
								Enterprise
							</Badge>
						</div>
						<p className="max-w-2xl text-sm text-zinc-500">
							Consola para administrar y medir el AI Clinical Interpreter — sin
							alterar el Wizard ni los motores.
						</p>
						{meta.truth && (
							<p className="text-[11px] text-zinc-400">
								Fuente · {meta.truth}
							</p>
						)}
					</div>
				</header>

				<div
					role="tablist"
					aria-label="Módulos"
					className="flex gap-1 overflow-x-auto rounded-xl border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-950"
				>
					{MODULES.map((mod) => {
						const active = mod.id === current;
						return (
							<button
								key={mod.id}
								type="button"
								role="tab"
								aria-selected={active}
								onClick={() => selectModule(mod.id)}
								className={`shrink-0 rounded-lg px-3 py-2 text-xs font-medium transition ${
									active
										? "bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-zinc-50"
										: "text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
								}`}
							>
								{mod.label}
							</button>
						);
					})}
				</div>

				<section className="min-h-[20rem]">
					{current === "overview" && (
						<OverviewModule data={modules.overview} />
					)}
					{current === "prompts" && (
						<PromptManagementModule data={modules.prompts} />
					)}
					{current === "confidence" && (
						<ConfidenceAnalyticsModule data={modules.confidence} />
					)}
					{current === "learning" && (
						<LearningCenterModule data={modules.learning} />
					)}
					{current === "explorer" && (
						<InterpretationExplorerModule data={modules.explorer} />
					)}
					{current === "performance" && (
						<PerformanceModule data={modules.performance} />
					)}
					{current === "health" && (
						<SystemHealthModule data={modules.health} />
					)}
					{current === "roadmap" && (
						<RoadmapModule data={modules.roadmap} />
					)}
				</section>
			</div>
		</AdminLayout>
	);
}
