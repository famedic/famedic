import { useEffect, useState } from "react";
import { usePage } from "@inertiajs/react";
import BasicInfoForm from "@/Pages/Account/BasicInfoForm";
import ContactInfoForm from "@/Pages/Account/ContactInfoForm";
import UpdatePasswordForm from "@/Pages/Account/UpdatePasswordForm";
import AccountOverview from "@/Pages/Account/AccountOverview";
import SettingsLayout from "@/Layouts/SettingsLayout";
import { Tab, TabGroup, TabList, TabPanel, TabPanels } from "@/Components/Catalyst/tabs";
import clsx from "clsx";

const TABS = [
	{ id: "datos-personales", label: "Información básica" },
	{ id: "contacto", label: "Contacto" },
	{ id: "seguridad", label: "Contraseña" },
];

function tabIndexFor(value) {
	if (value === "contacto") return 1;
	if (value === "seguridad" || value === "contrasena") return 2;
	if (value === "datos-personales" || value === "basica") return 0;

	return null;
}

function tabFromLocation() {
	if (typeof window === "undefined") return 0;

	if (window.location.hash) {
		const fromHash = tabIndexFor(window.location.hash.replace("#", ""));
		if (fromHash !== null) return fromHash;
	}

	const fromQuery = tabIndexFor(
		new URLSearchParams(window.location.search).get("tab"),
	);

	return fromQuery ?? 0;
}

function tabFromErrors(errors) {
	if (!errors) return null;

	if (errors.email || errors.phone || errors.phone_country) return 1;

	if (
		errors.current_password ||
		errors.password ||
		errors.password_confirmation
	) {
		return 2;
	}

	if (
		errors.name ||
		errors.paternal_lastname ||
		errors.maternal_lastname ||
		errors.birth_date ||
		errors.gender
	) {
		return 0;
	}

	return null;
}

export default function Account() {
	const { errors } = usePage().props;
	const [selectedIndex, setSelectedIndex] = useState(tabFromLocation);

	useEffect(() => {
		const sync = () => setSelectedIndex(tabFromLocation());
		window.addEventListener("hashchange", sync);

		return () => window.removeEventListener("hashchange", sync);
	}, []);

	useEffect(() => {
		const fromErrors = tabFromErrors(errors);
		if (fromErrors !== null) setSelectedIndex(fromErrors);
	}, [errors]);

	const selectTab = (index) => {
		setSelectedIndex(index);
		const url = `${window.location.pathname}${window.location.search}#${TABS[index].id}`;
		window.history.replaceState(null, "", url);
	};

	return (
		<SettingsLayout title="Mi cuenta">
			<AccountOverview />

			<TabGroup selectedIndex={selectedIndex} onChange={selectTab}>
				<TabList className="flex gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-600">
					{TABS.map((tab) => (
						<Tab
							key={tab.id}
							dusk={`accountTab-${tab.id}`}
							className="shrink-0"
						>
							{(selected) => (
								<span
									className={clsx(
										"block whitespace-nowrap rounded-lg px-4 py-2 text-sm font-semibold",
										selected
											? "bg-famedic-darker text-white shadow-sm dark:bg-famedic-lime dark:text-famedic-darker"
											: "text-zinc-600 hover:bg-white/80 dark:text-slate-200 dark:hover:bg-slate-700",
									)}
								>
									{tab.label}
								</span>
							)}
						</Tab>
					))}
				</TabList>

				<TabPanels className="mt-3">
					{[BasicInfoForm, ContactInfoForm, UpdatePasswordForm].map(
						(Form, index) => (
							<TabPanel
								key={TABS[index].id}
								unmount={false}
								className="rounded-xl bg-white p-5 shadow ring-1 ring-slate-200 sm:p-6 dark:bg-slate-800 dark:ring-slate-600"
							>
								<Form />
							</TabPanel>
						),
					)}
				</TabPanels>
			</TabGroup>
		</SettingsLayout>
	);
}
