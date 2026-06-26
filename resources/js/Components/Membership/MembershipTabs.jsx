import { lazy, Suspense, useState } from "react";
import { Text } from "@/Components/Catalyst/text";
import useMembershipTab from "@/Components/Membership/useMembershipTab";
import MembershipSummary from "@/Components/Membership/MembershipSummary";
import clsx from "clsx";

const MembershipPlan = lazy(
	() => import("@/Components/Membership/MembershipPlan"),
);
const MembershipPayments = lazy(
	() => import("@/Components/Membership/MembershipPayments"),
);
const MembershipCoverage = lazy(
	() => import("@/Components/Membership/MembershipCoverage"),
);
const MembershipUsage = lazy(
	() => import("@/Components/Membership/MembershipUsage"),
);
const MembershipHistory = lazy(
	() => import("@/Components/Membership/MembershipHistory"),
);

const TABS = [
	{ key: "resumen", label: "Resumen" },
	{ key: "plan", label: "Plan" },
	{ key: "pagos", label: "Pagos" },
	{ key: "cobertura", label: "Cobertura" },
	{ key: "uso", label: "Uso y beneficios" },
	{ key: "historial", label: "Historial" },
];

function TabLoading() {
	return (
		<div className="flex min-h-[200px] items-center justify-center rounded-2xl border border-dashed border-slate-200 dark:border-slate-700">
			<Text className="text-sm text-zinc-500 dark:text-slate-400">
				Cargando información...
			</Text>
		</div>
	);
}

function TabError() {
	return (
		<div className="rounded-2xl border border-rose-200 bg-rose-50 p-6 text-center dark:border-rose-500/30 dark:bg-rose-500/10">
			<Text className="text-sm text-rose-700 dark:text-rose-300">
				No pudimos cargar esta sección. Intenta de nuevo más tarde.
			</Text>
		</div>
	);
}

function TabContent({ activeTab, membership, tabData, loading, error }) {
	if (activeTab === "resumen") {
		return (
			<MembershipSummary
				holder={membership.holder}
				plan={membership.plan}
				payment={membership.payment}
			/>
		);
	}

	if (loading) {
		return <TabLoading />;
	}

	if (error) {
		return <TabError />;
	}

	return (
		<Suspense fallback={<TabLoading />}>
			{activeTab === "plan" && (
				<MembershipPlan
					plan={tabData?.plan}
					benefits={tabData?.benefits ?? []}
				/>
			)}
			{activeTab === "pagos" && (
				<MembershipPayments
					payments={tabData?.payments ?? []}
					capabilities={
						tabData?.capabilities ?? membership.capabilities
					}
				/>
			)}
			{activeTab === "cobertura" && (
				<MembershipCoverage
					coverage={tabData?.coverage ?? membership.coverage}
					capabilities={
						tabData?.capabilities ?? membership.capabilities
					}
				/>
			)}
			{activeTab === "uso" && (
				<MembershipUsage usage={tabData?.usage} />
			)}
			{activeTab === "historial" && (
				<MembershipHistory timeline={tabData?.timeline ?? []} />
			)}
		</Suspense>
	);
}

export default function MembershipTabs({
	membership,
	activeTab: controlledTab,
	onTabChange,
}) {
	const [internalTab, setInternalTab] = useState("resumen");
	const activeTab = controlledTab ?? internalTab;

	const setActiveTab = (tab) => {
		if (onTabChange) {
			onTabChange(tab);
		} else {
			setInternalTab(tab);
		}
	};

	const { data, loading, error } = useMembershipTab(activeTab);

	return (
		<section className="space-y-5">
			<nav
				className="-mx-1 flex gap-2 overflow-x-auto overscroll-x-contain px-1 pb-1 [-webkit-overflow-scrolling:touch]"
				aria-label="Secciones de membresía"
			>
				{TABS.map((tab) => {
					const isActive = activeTab === tab.key;

					return (
						<button
							key={tab.key}
							type="button"
							onClick={() => setActiveTab(tab.key)}
							aria-current={isActive ? "page" : undefined}
							className={clsx(
								"shrink-0 rounded-full border px-4 py-2.5 text-sm font-medium whitespace-nowrap transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-famedic-dark",
								isActive
									? "border-famedic-lime bg-famedic-lime font-semibold text-famedic-dark shadow-md ring-2 ring-famedic-lime/40"
									: "border-slate-200 bg-white text-zinc-600 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800/60 dark:text-slate-300",
							)}
						>
							{tab.label}
						</button>
					);
				})}
			</nav>

			<div className="min-h-[240px]">
				<TabContent
					activeTab={activeTab}
					membership={membership}
					tabData={data}
					loading={loading}
					error={error}
				/>
			</div>
		</section>
	);
}

export { TABS };
