import { useState, Fragment } from "react";
import { useForm } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import {
	CloudArrowDownIcon,
	ArrowPathIcon,
} from "@heroicons/react/24/outline";
import {
	Dialog,
	DialogTitle,
	DialogDescription,
	DialogBody,
	DialogActions,
} from "@/Components/Catalyst/dialog";
import { Tab, TabGroup, TabList, TabPanel, TabPanels } from "@headlessui/react";
import AppliedFilters from "@/Components/AppliedFilters";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";

export default function ExportDialog({
	canExport,
	filters,
	filterBadges,
	exportUrl,
	title,
	className = "",
}) {
	if (!canExport) {
		return null;
	}

	const [showDialog, setShowDialog] = useState(false);
	const [selectedTab, setSelectedTab] = useState(0);
	const { post, processing, transform } = useForm({});

	transform(() => {
		return selectedTab === 0 ? filters : {};
	});

	const handleExportClick = () => {
		if (filterBadges.length > 0) {
			setShowDialog(true);
		} else {
			handleDirectExport();
		}
	};

	const handleDirectExport = () => {
		if (!processing) {
			post(exportUrl, {
				replace: true,
				preserveState: true,
			});
		}
	};

	const handleDialogExport = () => {
		post(exportUrl, {
			onSuccess: () => {
				setShowDialog(false);
				setSelectedTab(0);
			},
		});
	};

	return (
		<>
			<Button
				outline
				onClick={handleExportClick}
				disabled={processing}
				className={className}
			>
				<CloudArrowDownIcon />
				Descargar
				<FilterCountBadge count={filterBadges.length} />
				{processing && <ArrowPathIcon className="animate-spin" />}
			</Button>

			<Dialog open={showDialog} onClose={setShowDialog}>
				<DialogTitle>{title}</DialogTitle>
				<DialogDescription>
					¿Cómo deseas descargar la información?
				</DialogDescription>
				<DialogBody>
					<TabGroup selectedIndex={selectedTab} onChange={setSelectedTab}>
						<TabList className="grid grid-cols-1 gap-2 rounded-lg bg-slate-50 p-1.5 sm:grid-cols-2 dark:bg-slate-800">
							<Tab as={Fragment}>
								{({ selected }) => (
									<Button
										{...(selected
											? { color: "white" }
											: { plain: true })}
										className="w-full"
									>
										Con filtros
										<FilterCountBadge
											count={filterBadges.length}
										/>
									</Button>
								)}
							</Tab>
							<Tab as={Fragment}>
								{({ selected }) => (
									<Button
										{...(selected
											? { color: "white" }
											: { plain: true })}
										className="w-full"
									>
										Sin filtros
									</Button>
								)}
							</Tab>
						</TabList>

						<TabPanels className="mt-6">
							<TabPanel>
								<Text>Se aplicarán los siguientes filtros:</Text>
								<div className="mt-2">
									<AppliedFilters filterBadges={filterBadges} />
								</div>
							</TabPanel>

							<TabPanel>
								<Text>
									Se descargará toda la información disponible
								</Text>
							</TabPanel>
						</TabPanels>
					</TabGroup>
				</DialogBody>
				<DialogActions>
					<Button
						plain
						onClick={() => setShowDialog(false)}
						disabled={processing}
						autoFocus
					>
						Cancelar
					</Button>
					<Button
						color="famedic"
						onClick={handleDialogExport}
						disabled={processing}
					>
						{processing ? (
							<ArrowPathIcon className="animate-spin" />
						) : (
							<CloudArrowDownIcon />
						)}
						Descargar
					</Button>
				</DialogActions>
			</Dialog>
		</>
	);
}