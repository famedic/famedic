import { useCallback, useEffect, useRef, useState } from "react";
import { Link, router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import JourneyFilters from "@/Components/Admin/ActiveCampaign/Journey/JourneyFilters";
import JourneySummary from "@/Components/Admin/ActiveCampaign/Journey/JourneySummary";
import JourneyCanvas from "@/Components/Admin/ActiveCampaign/Journey/JourneyCanvas";
import JourneySidebar from "@/Components/Admin/ActiveCampaign/Journey/JourneySidebar";
import JourneyLegend from "@/Components/Admin/ActiveCampaign/Journey/JourneyLegend";
import JourneyStats from "@/Components/Admin/ActiveCampaign/Journey/JourneyStats";

export default function CustomerJourney({
	filters,
	contactOptions = [],
	journey = null,
	contactsUrl,
}) {
	const { journeyDetail } = usePage().props;
	const [selectedNodeId, setSelectedNodeId] = useState(null);
	const [detailLoading, setDetailLoading] = useState(false);
	const [detail, setDetail] = useState(null);
	const requestIdRef = useRef(0);
	const selectedNodeRef = useRef(null);

	selectedNodeRef.current = selectedNodeId;

	useEffect(() => {
		if (!journeyDetail?.node_id) {
			return;
		}
		if (journeyDetail.node_id !== selectedNodeRef.current) {
			return;
		}
		setDetail(journeyDetail);
		setDetailLoading(false);
	}, [journeyDetail]);

	useEffect(() => {
		requestIdRef.current += 1;
		setSelectedNodeId(null);
		setDetail(null);
		setDetailLoading(false);
	}, [journey?.summary?.contact_id]);

	const onSelectNode = useCallback(
		(node) => {
			if (!journey?.summary?.contact_id) {
				return;
			}
			const requestId = ++requestIdRef.current;
			setSelectedNodeId(node.id);
			setDetailLoading(true);
			setDetail(null);

			router.reload({
				only: ["journeyDetail"],
				data: {
					contact_id: journey.summary.contact_id,
					node_id: node.id,
					search: filters?.search || "",
					start_date: filters?.start_date || "",
					end_date: filters?.end_date || "",
					type: filters?.type || "",
				},
				preserveState: true,
				preserveScroll: true,
				onFinish: () => {
					if (requestIdRef.current !== requestId) {
						return;
					}
					if (selectedNodeRef.current !== node.id) {
						return;
					}
					setDetailLoading(false);
				},
			});
		},
		[journey?.summary?.contact_id, filters],
	);

	return (
		<AdminLayout title="Marketing Intelligence · Customer Journey">
			<div className="space-y-6">
				<nav
					aria-label="Breadcrumb"
					className="flex flex-wrap items-center gap-1.5 text-[11px] uppercase tracking-[0.12em]"
				>
					<Link
						href={route("admin.activecampaign.dashboard")}
						className="font-medium text-zinc-400 transition hover:text-famedic-light"
					>
						Marketing Intelligence
					</Link>
					<ChevronRightIcon className="size-3 text-zinc-300 dark:text-zinc-600" />
					<span className="font-semibold text-zinc-700 dark:text-zinc-200">
						Customer Journey
					</span>
				</nav>

				<div className="flex flex-wrap items-start justify-between gap-4">
					<div className="max-w-2xl space-y-2">
						<div className="flex flex-wrap items-center gap-2">
							<Heading>Customer Journey</Heading>
							<Badge color="famedic">Flow</Badge>
							<Badge color="sky">Timeline = fuente de verdad</Badge>
						</div>
						<Text className="text-sm text-zinc-600 dark:text-zinc-400">
							Diagrama del recorrido del paciente en Famedic. Sin tablas ni
							timeline vertical: nodos conectados con detalle bajo demanda.
						</Text>
					</div>
					{contactsUrl ? (
						<Link
							href={contactsUrl}
							className="text-xs font-semibold text-famedic-light hover:underline"
						>
							Ir a Contactos →
						</Link>
					) : null}
				</div>

				<JourneyFilters
					filters={filters}
					contactOptions={contactOptions}
					typeOptions={journey?.type_options || []}
				/>

				<JourneySummary summary={journey?.summary} />

				{journey ? (
					<>
						<JourneyLegend />

						<div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
							<section className="min-w-0 space-y-2">
								<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
									Journey visual
								</h2>
								<JourneyCanvas
									journey={journey}
									selectedNodeId={selectedNodeId}
									onSelectNode={onSelectNode}
								/>
							</section>
							<JourneySidebar
								detail={detail}
								loading={detailLoading}
								onClose={() => {
									setSelectedNodeId(null);
									setDetail(null);
								}}
							/>
						</div>

						<JourneyStats stats={journey.stats} />
					</>
				) : (
					<div className="rounded-xl border border-dashed border-zinc-300 bg-white p-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-sm text-zinc-500">
							Busca y selecciona un paciente para construir el Journey desde el
							Timeline.
						</Text>
					</div>
				)}
			</div>
		</AdminLayout>
	);
}
